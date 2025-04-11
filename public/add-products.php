<?php
/**
 * Save JSON feed to uploads folder and import products to WooCommerce
 */

function syncwoo_enqueue_scripts() {
    // Enqueue your JavaScript file
    wp_enqueue_script('syncwoo-sync', plugin_dir_url(__FILE__) . '../admin/js/syncwoo-sync.js', array('jquery'), null, true);

    // Localize the script with the AJAX URL and nonce
    wp_localize_script('syncwoo-sync', 'syncwoo_vars', array(
        'syncwoo_ajax_url' => admin_url('admin-ajax.php'),
        'syncwoo_nonce'    => wp_create_nonce('syncwoo_nonce')
    ));
}
add_action('admin_enqueue_scripts', 'syncwoo_enqueue_scripts');


/**
 * Helper function to upload product image
 */
function syncwoo_upload_product_image($image_url) {
    if (empty($image_url)) {
        return 0;
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $filename = basename($image_url);
    $existing = new WP_Query([
        'post_type' => 'attachment',
        'meta_query' => [
            ['key' => '_wp_attached_file', 'value' => $filename]
        ]
    ]);

    if ($existing->have_posts()) {
        return $existing->posts[0]->ID;
    }

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        error_log("Image download failed: " . $tmp->get_error_message());
        return 0;
    }

    $file_array = [
        'name' => $filename,
        'tmp_name' => $tmp
    ];
    $image_id = media_handle_sideload($file_array, 0);

    if (is_wp_error($image_id)) {
        @unlink($tmp);
        error_log("Image upload failed: " . $image_id->get_error_message());
        return 0;
    }

    @unlink($tmp);
    return $image_id;
}



/**
 * Handle product synchronization via AJAX
 */
add_action('wp_ajax_syncwoo_perform_sync', 'syncwoo_perform_sync');

function syncwoo_perform_sync() {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        exit;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 401);
        exit;
    }

    $local_dir = WP_CONTENT_DIR . '/Uploads/syncwoo-json/';
    if (!file_exists($local_dir)) {
        wp_mkdir_p($local_dir);
    }
    $htaccess_file = $local_dir . '.htaccess';
    file_put_contents($htaccess_file, "Options -Indexes\n<FilesMatch \"\\.(php)$\">\n    Deny from all\n</FilesMatch>\n<FilesMatch \"\\.(css|js)$\">\n    Allow from all\n</FilesMatch>");

    try {
        $processed_count = isset($_POST['processed_count']) ? intval($_POST['processed_count']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;

        $file_path_0 = WP_CONTENT_DIR . '/Uploads/syncwoo-json/product_0.json';
        $file_path_1 = WP_CONTENT_DIR . '/Uploads/syncwoo-json/product_1.json';

        $data = [];
        $results = ['processed' => 0, 'errors' => [], 'duplicates' => []];

        $cache_key = 'syncwoo_json_data';
        $data = get_transient($cache_key);
        if ($data === false) {
            if (!file_exists($file_path_0) || !file_exists($file_path_1)) {
                throw new Exception('One or both JSON files are missing');
            }

            $json_data_0 = file_get_contents($file_path_0);
            if ($json_data_0 === false) {
                throw new Exception('Failed to read product_0.json');
            }
            $decoded_data_0 = json_decode($json_data_0, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON error in product_0.json: ' . json_last_error_msg());
                throw new Exception('Invalid JSON in product_0.json');
            }
            $data = $decoded_data_0;
            unset($json_data_0, $decoded_data_0);

            $json_data_1 = file_get_contents($file_path_1);
            if ($json_data_1 === false) {
                throw new Exception('Failed to read product_1.json');
            }
            $decoded_data_1 = json_decode($json_data_1, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON error in product_1.json: ' . json_last_error_msg());
                throw new Exception('Invalid JSON in product_1.json');
            }
            $data = array_merge($data, $decoded_data_1);
            unset($json_data_1, $decoded_data_1);

            if (empty($data)) {
                throw new Exception('No valid data in JSON files');
            }

            set_transient($cache_key, $data, DAY_IN_SECONDS);
        }

        $products_to_process = array_slice($data, $processed_count, $batch_size);
        if (empty($products_to_process)) {
            wp_send_json_success([
                'message' => 'No more products to process',
                'results' => $results,
                'remaining' => 0,
                'processed_count' => $processed_count
            ]);
            exit;
        }

        // Pre-create categories with parent-child relationship
        $root_categories = [];
        $child_categories = [];
        foreach ($products_to_process as $product_data) {
            if (!empty($product_data['root_category'])) {
                $root_categories[$product_data['root_category']] = true;
            }
            if (!empty($product_data['category']) && !empty($product_data['root_category'])) {
                $child_categories[$product_data['root_category']][$product_data['category']] = true;
            }
        }

        $root_category_ids = [];
        foreach (array_keys($root_categories) as $root_category_name) {
            $latin_slug = sanitize_title_with_dashes(transliterate_georgian_to_latin($root_category_name));
            $root_term = term_exists($root_category_name, 'product_cat');
            if (!$root_term) {
                $root_term = wp_insert_term($root_category_name, 'product_cat', [
                    'slug' => $latin_slug
                ]);
                if (is_wp_error($root_term)) {
                    error_log("Failed to create root category $root_category_name: " . $root_term->get_error_message());
                    continue;
                }
            }
            $root_category_ids[$root_category_name] = $root_term['term_id'];
        }

        $child_category_ids = [];
        foreach ($child_categories as $root_category_name => $children) {
            $parent_id = $root_category_ids[$root_category_name] ?? 0;
            foreach (array_keys($children) as $child_category_name) {
                $latin_slug = sanitize_title_with_dashes(transliterate_georgian_to_latin($child_category_name));
                $child_term = term_exists($child_category_name, 'product_cat');
                if (!$child_term) {
                    $child_term = wp_insert_term($child_category_name, 'product_cat', [
                        'slug' => $latin_slug,
                        'parent' => $parent_id
                    ]);
                    if (is_wp_error($child_term)) {
                        error_log("Failed to create child category $child_category_name: " . $child_term->get_error_message());
                        continue;
                    }
                }
                $child_category_ids[$child_category_name] = $child_term['term_id'];
            }
        }

        // Pre-create brands
        $brands = [];
        foreach ($products_to_process as $product_data) {
            if (!empty($product_data['features'])) {
                foreach ($product_data['features'] as $feature) {
                    if (isset($feature['feature_id']) && $feature['feature_id'] == 5485) {
                        $brands[trim($feature['variant'])] = true;
                    }
                }
            }
        }

        $brand_taxonomy = 'product_brand';
        if (!taxonomy_exists($brand_taxonomy)) {
            register_taxonomy($brand_taxonomy, 'product', [
                'labels' => ['name' => 'Brands'],
                'hierarchical' => true
            ]);
        }
        foreach (array_keys($brands) as $brand) {
            if (!term_exists($brand, $brand_taxonomy)) {
                wp_insert_term($brand, $brand_taxonomy);
            }
        }

        foreach ($products_to_process as $product_data) {
            try {
                if (empty($product_data['barcode'])) {
                    throw new Exception('Missing barcode for product: ' . ($product_data['product'] ?? 'Unknown'));
                }

                $sku = sanitize_text_field($product_data['barcode']);
                $product_id = wc_get_product_id_by_sku($sku);
                $is_update = $product_id > 0;
                $product = $is_update ? new WC_Product($product_id) : new WC_Product_Simple();

                $product->set_name(sanitize_text_field($product_data['product']));
                $product->set_description(wp_kses_post($product_data['description']));
                $product->set_regular_price(floatval($product_data['list_price']));
                $product->set_sale_price(floatval($product_data['price']));
                $product->set_sku($sku);
                $product->set_manage_stock(true);
                $product->set_stock_quantity(absint($product_data['amount'] ?? 0));

                if (!empty($product_data['weight'])) {
                    $product->set_weight(floatval($product_data['weight']));
                }

                $category_ids = [];
                if (!empty($product_data['root_category'])) {
                    $root_category_name = $product_data['root_category'];
                    if (isset($root_category_ids[$root_category_name])) {
                        $category_ids[] = $root_category_ids[$root_category_name];
                    }
                }
                if (!empty($product_data['category'])) {
                    $child_category_name = $product_data['category'];
                    if (isset($child_category_ids[$child_category_name])) {
                        $category_ids[] = $child_category_ids[$child_category_name];
                    }
                }
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }

                $attributes = $product->get_attributes();
                $product_brand = null;

                if (!empty($product_data['features'])) {
                    foreach ($product_data['features'] as $feature) {
                        $name = trim($feature['feature']);
                        $value = trim($feature['variant']);
                        $feature_id = isset($feature['feature_id']) ? $feature['feature_id'] : 0;

                        if (empty($name) || empty($value) || $value === ' ') {
                            continue;
                        }

                        if ($feature_id == 5485) {
                            $product_brand = $value;
                            continue;
                        }

                        $taxonomy_slug = 'go_' . $feature_id;
                        $taxonomy = wc_attribute_taxonomy_name($taxonomy_slug);

                        if (!taxonomy_exists($taxonomy)) {
                            $attribute_id = wc_create_attribute([
                                'name' => $name,
                                'slug' => $taxonomy_slug,
                                'type' => 'select'
                            ]);

                            if (is_wp_error($attribute_id)) {
                                error_log("Failed to create attribute $name: " . $attribute_id->get_error_message());
                                continue;
                            }

                            register_taxonomy($taxonomy, 'product', [
                                'labels' => ['name' => $name],
                                'hierarchical' => true
                            ]);
                        }

                        $term = term_exists($value, $taxonomy);
                        if (!$term) {
                            $term = wp_insert_term($value, $taxonomy);
                            if (is_wp_error($term)) {
                                error_log("Failed to create term $value for $taxonomy: " . $term->get_error_message());
                                continue;
                            }
                        }
                        $term_id = is_array($term) ? $term['term_id'] : $term;

                        wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, true);

                        if (!isset($attributes[$taxonomy])) {
                            $attr = new WC_Product_Attribute();
                            $attr->set_name($name);
                            $attr->set_visible(true);
                            $attr->set_variation(false);
                            $attr->set_options([$value]);
                            $attributes[$taxonomy] = $attr;
                        } else {
                            $current_values = $attributes[$taxonomy]->get_options();
                            if (!in_array($value, $current_values)) {
                                $current_values[] = $value;
                                $attributes[$taxonomy]->set_options($current_values);
                            }
                        }
                    }
                }

                if (!empty($product_data['Unit'])) {
                    $unit_value = sanitize_text_field($product_data['Unit']);
                    $taxonomy = 'unit';
                    if (!taxonomy_exists($taxonomy)) {
                        wc_create_attribute([
                            'name' => 'Unit',
                            'slug' => $taxonomy,
                            'type' => 'select'
                        ]);
                        register_taxonomy($taxonomy, 'product', [
                            'labels' => ['name' => 'Unit'],
                            'hierarchical' => true
                        ]);
                    }

                    $term = term_exists($unit_value, $taxonomy) ?: wp_insert_term($unit_value, $taxonomy);
                    if (!is_wp_error($term)) {
                        wp_set_object_terms($product->get_id(), (int)$term['term_id'], $taxonomy, true);
                        if (!isset($attributes[$taxonomy])) {
                            $attr = new WC_Product_Attribute();
                            $attr->set_name('Unit');
                            $attr->set_visible(true);
                            $attr->set_variation(false);
                            $attr->set_options([$unit_value]);
                            $attributes[$taxonomy] = $attr;
                        }
                    }
                }

                $product->set_attributes($attributes);

                if ($product_brand) {
                    $brand_term = term_exists($product_brand, $brand_taxonomy);
                    if ($brand_term) {
                        wp_set_object_terms($product->get_id(), (int)$brand_term['term_id'], $brand_taxonomy, true);
                    }
                }

                if (!empty($product_data['images'][0])) {
                    $image_id = syncwoo_upload_product_image($product_data['images'][0]);
                    if ($image_id && !is_wp_error($image_id)) {
                        $product->set_image_id($image_id);
                    }
                }

                if (!empty($product_data['images']) && count($product_data['images']) > 1) {
                    $gallery_ids = [];
                    for ($i = 1; $i < count($product_data['images']); $i++) {
                        $image_id = syncwoo_upload_product_image($product_data['images'][$i]);
                        if ($image_id && !is_wp_error($image_id)) {
                            $gallery_ids[] = $image_id;
                        }
                    }
                    if (!empty($gallery_ids)) {
                        $product->set_gallery_image_ids($gallery_ids);
                    }
                }

                // Save product and increment processed only on success
                $new_product_id = $product->save();
                if (!$new_product_id) {
                    throw new Exception('Failed to save product: ' . $product_data['product']);
                }

                if ($is_update) {
                    $results['duplicates'][] = $sku;
                    error_log("Duplicate SKU updated: $sku");
                } else {
                    $results['processed']++;
                }

                unset($product_data, $product, $attributes);

            } catch (Exception $e) {
                error_log("Product sync error: " . $e->getMessage());
                $results['errors'][] = $e->getMessage();
            }
        }

        $remaining = max(0, count($data) - ($processed_count + $results['processed']));
        wp_send_json_success([
            'message' => 'Batch processed successfully',
            'results' => $results,
            'remaining' => $remaining,
            'processed_count' => $processed_count + $results['processed'],
            'total_processed' => $results['processed'],
            'duplicates' => count($results['duplicates'])
        ]);

        unset($products_to_process, $data);

    } catch (Exception $e) {
        error_log('Sync error: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }

    exit;
}

// Function to transliterate Georgian to Latin
function transliterate_georgian_to_latin($text) {
    $georgian = [
        'ა', 'ბ', 'გ', 'დ', 'ე', 'ვ', 'ზ', 'თ', 'ი', 'კ', 'ლ', 'მ', 'ნ', 'ო', 'პ', 'ჟ',
        'რ', 'ს', 'ტ', 'უ', 'ფ', 'ქ', 'ღ', 'ყ', 'შ', 'ჩ', 'ც', 'ძ', 'წ', 'ჭ', 'ხ', 'ჯ', 'ჰ'
    ];
    $latin = [
        'a', 'b', 'g', 'd', 'e', 'v', 'z', 't', 'i', 'k', 'l', 'm', 'n', 'o', 'p', 'zh',
        'r', 's', 't', 'u', 'f', 'q', 'gh', 'k', 'sh', 'ch', 'ts', 'dz', 'ts', 'tch', 'kh', 'j', 'h'
    ];

    return str_replace($georgian, $latin, $text);
}
