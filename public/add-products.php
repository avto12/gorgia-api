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


// AJAX handler for synchronization
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
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

        $file_path_0 = WP_CONTENT_DIR . '/Uploads/syncwoo-json/product_0.json';
        $file_path_1 = WP_CONTENT_DIR . '/Uploads/syncwoo-json/product_1.json';

        $data = [];
        $results = ['new_products' => 0, 'updated_products' => 0, 'deleted_products' => 0, 'errors' => []];

        // Clear transient to ensure fresh JSON data
        $cache_key = 'syncwoo_json_data';
        delete_transient($cache_key);

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

        $total_products = count($data);
        $products_to_process = array_slice($data, $processed_count, $batch_size);

        // Get all SKUs from JSON
        $json_skus = array_map(function ($product) {
            return sanitize_text_field($product['barcode'] ?? '');
        }, $data);

        // Delete products not in JSON (only on first batch)
        if ($processed_count === 0) {
            $existing_products = wc_get_products([
                'limit' => -1,
                'status' => 'publish',
                'return' => 'objects'
            ]);

            foreach ($existing_products as $existing_product) {
                $sku = $existing_product->get_sku();
                if (!in_array($sku, $json_skus)) {
                    $existing_product->delete(true); // Permanent delete
                    $results['deleted_products']++;
                    error_log("Deleted product with SKU: $sku (not in JSON)");
                }
            }
        }

        if (empty($products_to_process)) {
            wp_send_json_success([
                'message' => 'No more products to process',
                'results' => $results,
                'remaining' => 0,
                'processed_count' => $processed_count,
                'total_products' => $total_products
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
                foreach ($product_data['features'] as $f) {
                    if (isset($f['feature_id']) && $f['feature_id'] == 5485 && !empty(trim($f['variant']))) {
                        $brands[trim($f['variant'])] = true;
                    }
                }
            }
        }

        $brand_taxonomy = 'product_brand'; // Use plain taxonomy as requested
        if (!taxonomy_exists($brand_taxonomy)) {
            register_taxonomy($brand_taxonomy, 'product', [
                'labels' => ['name' => 'Brands'],
                'hierarchical' => false,
                'public' => true
            ]);
            error_log("Created brand taxonomy '$brand_taxonomy'");
        }

        foreach (array_keys($brands) as $brand) {
            $term = term_exists($brand, $brand_taxonomy);
            if (!$term) {
                $term_result = wp_insert_term($brand, $brand_taxonomy);
                if (is_wp_error($term_result)) {
                    error_log("Failed to create brand term '$brand': " . $term_result->get_error_message());
                } else {
                    error_log("Created brand term '$brand', ID: " . $term_result['term_id']);
                }
            } else {
                error_log("Brand term '$brand' already exists");
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

                $needs_update = false;

                // Check if product needs updating
                if ($is_update) {
                    $needs_update = (
                        $product->get_name() !== sanitize_text_field($product_data['product']) ||
                        $product->get_description() !== wp_kses_post($product_data['description']) ||
                        $product->get_regular_price() != floatval($product_data['list_price']) ||
                        $product->get_sale_price() != floatval($product_data['price']) ||
                        $product->get_stock_quantity() != absint($product_data['amount'] ?? 0) ||
                        $product->get_weight() != floatval($product_data['weight'] ?? 0)
                    );
                }

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

                // Handle product attributes
                $attributes = $product->get_attributes();

                // Store brand value for assignment after save
                $product_brand = null;

                // Process features attributes
                if (!empty($product_data['features'])) {
                    foreach ($product_data['features'] as $feature) {
                        $name = trim($feature['feature']);
                        $value = trim($feature['variant']);
                        $feature_id = isset($feature['feature_id']) ? $feature['feature_id'] : 0;

                        if (empty($name) || empty($value) || $value === ' ') {
                            continue;
                        }

                        if ($feature_id == 5485) {
                            $product_brand = $value; // Store for later assignment
                            continue;
                        }

                        $taxonomy_slug = 'pa_go_' . $feature_id;
                        $taxonomy = wc_attribute_taxonomy_name('go_' . $feature_id);

                        // Create attribute if it doesn't exist
                        if (!taxonomy_exists($taxonomy)) {
                            $attribute_id = wc_create_attribute([
                                'name' => $name,
                                'slug' => 'go_' . $feature_id,
                                'type' => 'select'
                            ]);

                            if (is_wp_error($attribute_id)) {
                                error_log("Failed to create attribute '$name': " . $attribute_id->get_error_message());
                                continue;
                            }

                            register_taxonomy($taxonomy, 'product', [
                                'labels' => ['name' => $name],
                                'hierarchical' => false,
                                'public' => true
                            ]);
                            error_log("Created taxonomy '$taxonomy' for attribute '$name'");
                        }

                        // Ensure term exists or create it
                        $term = term_exists($value, $taxonomy);
                        if (!$term) {
                            $term_result = wp_insert_term($value, $taxonomy);
                            if (is_wp_error($term_result)) {
                                error_log("Failed to insert term '$value' for taxonomy '$taxonomy': " . $term_result->get_error_message());
                                continue;
                            } else {
                                $term_id = $term_result['term_id'];
                                error_log("New term '$value' created for taxonomy '$taxonomy', ID: $term_id");
                            }
                        } else {
                            $term_id = is_array($term) ? $term['term_id'] : $term;
                            error_log("Term '$value' already exists for taxonomy '$taxonomy', ID: $term_id");
                        }

                        // Assign term to product
                        if (isset($term_id) && $term_id) {
                            $result = wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, false);
                            if (is_wp_error($result)) {
                                error_log("Failed to set term '$value' for product ID {$product->get_id()}: " . $result->get_error_message());
                            } else {
                                error_log("Term '$value' assigned to product ID {$product->get_id()}");
                            }
                        }

                        // Add to product attributes
                        if (!isset($attributes[$taxonomy])) {
                            $attr = new WC_Product_Attribute();
                            $attr->set_id(wc_attribute_taxonomy_id_by_name('go_' . $feature_id));
                            $attr->set_name($taxonomy);
                            $attr->set_options([$value]);
                            $attr->set_position(0);
                            $attr->set_visible(true);
                            $attr->set_variation(false);
                            $attributes[$taxonomy] = $attr;
                            error_log("Attribute '$name' set for product ID {$product->get_id()} with value '$value'");
                        }
                    }
                }

                // Handle "ერთეული" attribute
                if (!empty($product_data['Unit'])) {
                    $unit_value = sanitize_text_field($product_data['Unit']);
                    $taxonomy = 'pa_unit';

                    // Create attribute if it doesn't exist
                    if (!taxonomy_exists($taxonomy)) {
                        $attribute = wc_create_attribute([
                            'name' => 'ერთეული',
                            'slug' => 'unit',
                            'type' => 'select'
                        ]);
                        if (is_wp_error($attribute)) {
                            error_log("Failed to create attribute 'ერთეული': " . $attribute->get_error_message());
                        } else {
                            register_taxonomy($taxonomy, 'product', [
                                'labels' => ['name' => 'ერთეული'],
                                'hierarchical' => false,
                                'public' => true
                            ]);
                            error_log("Created taxonomy '$taxonomy' for 'ერთეული'");
                        }
                    }

                    // Ensure term exists or create it
                    $term = term_exists($unit_value, $taxonomy);
                    if (!$term) {
                        $term_result = wp_insert_term($unit_value, $taxonomy);
                        if (is_wp_error($term_result)) {
                            error_log("Failed to insert term '$unit_value' for taxonomy '$taxonomy': " . $term_result->get_error_message());
                        } else {
                            $term_id = $term_result['term_id'];
                            error_log("New term '$unit_value' created for taxonomy '$taxonomy', ID: $term_id");
                        }
                    } else {
                        $term_id = is_array($term) ? $term['term_id'] : $term;
                        error_log("Term '$unit_value' already exists for taxonomy '$taxonomy', ID: $term_id");
                    }

                    // Assign term to product
                    if (isset($term_id) && $term_id) {
                        $result = wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, false);
                        if (is_wp_error($result)) {
                            error_log("Failed to set term '$unit_value' for product ID {$product->get_id()}: " . $result->get_error_message());
                        } else {
                            error_log("Term '$unit_value' assigned to product ID {$product->get_id()}");
                        }
                    }

                    // Add to product attributes
                    if (!isset($attributes[$taxonomy])) {
                        $attr = new WC_Product_Attribute();
                        $attr->set_id(wc_attribute_taxonomy_id_by_name('unit'));
                        $attr->set_name($taxonomy);
                        $attr->set_options([$unit_value]);
                        $attr->set_position(0);
                        $attr->set_visible(true);
                        $attr->set_variation(false);
                        $attributes[$taxonomy] = $attr;
                        error_log("Attribute 'ერთეული' set for product ID {$product->get_id()} with value '$unit_value'");
                    }
                }

                // Save all attributes to product
                $product->set_attributes($attributes);

                // Handle main image
                $current_image_id = $product->get_image_id();
                if (!empty($product_data['images'][0])) {
                    $new_image_url = rtrim($product_data['images'][0], '/');
                    $new_image_id = syncwoo_upload_product_image($new_image_url);

                    if ($new_image_id && !is_wp_error($new_image_id)) {
                        $current_url_hash = $current_image_id ? get_post_meta($current_image_id, '_syncwoo_image_url_hash', true) : '';
                        $new_url_hash = md5($new_image_url);

                        if ($current_image_id && $current_url_hash === $new_url_hash) {
                            error_log("Main image unchanged for SKU: $sku, URL: $new_image_url (hash: $new_url_hash)");
                        } else {
                            $product->set_image_id($new_image_id);
                            $needs_update = true;
                            error_log("Main image set/updated for SKU: $sku, URL: $new_image_url (hash: $new_url_hash)");
                        }
                    }
                }

                // Handle gallery images with strict duplicate prevention
                if (!empty($product_data['images']) && count($product_data['images']) > 1) {
                    $current_gallery_ids = $product->get_gallery_image_ids();
                    $current_gallery_hashes = array_map(function ($id) {
                        return get_post_meta($id, '_syncwoo_image_url_hash', true);
                    }, $current_gallery_ids);
                    $new_gallery_ids = [];
                    $new_gallery_hashes = [];

                    for ($i = 1; $i < count($product_data['images']); $i++) {
                        $gallery_image_url = rtrim($product_data['images'][$i], '/');
                        $gallery_image_hash = md5($gallery_image_url);

                        if (!in_array($gallery_image_hash, $current_gallery_hashes) && !in_array($gallery_image_hash, $new_gallery_hashes)) {
                            $gallery_image_id = syncwoo_upload_product_image($gallery_image_url);
                            if ($gallery_image_id && !is_wp_error($gallery_image_id)) {
                                $new_gallery_ids[] = $gallery_image_id;
                                $new_gallery_hashes[] = $gallery_image_hash;
                                error_log("New gallery image added for SKU: $sku, URL: $gallery_image_url (hash: $gallery_image_hash)");
                            }
                        } else {
                            error_log("Gallery image skipped (duplicate) for SKU: $sku, URL: $gallery_image_url (hash: $gallery_image_hash)");
                        }
                    }

                    if (!empty($new_gallery_ids)) {
                        $updated_gallery_ids = array_merge($current_gallery_ids, $new_gallery_ids);
                        $product->set_gallery_image_ids($updated_gallery_ids);
                        $needs_update = true;
                        error_log("Gallery updated for SKU: $sku, new IDs: " . implode(', ', $new_gallery_ids));
                    }
                }

                // Save the product first to ensure it has a valid ID
                $new_product_id = $product->save();
                if (!$new_product_id) {
                    throw new Exception('Failed to save product: ' . $product_data['product']);
                }

                // Now assign the brand taxonomy after saving the product
                if ($product_brand) {
                    $term = term_exists($product_brand, $brand_taxonomy);
                    $term_id = null;

                    if (!$term) {
                        $term_result = wp_insert_term($product_brand, $brand_taxonomy);
                        if (is_wp_error($term_result)) {
                            error_log("Failed to create brand term '$product_brand': " . $term_result->get_error_message());
                        } else {
                            $term_id = $term_result['term_id'];
                            error_log("Created brand term '$product_brand', ID: $term_id");
                        }
                    } else {
                        $term_id = is_array($term) ? $term['term_id'] : $term;
                        error_log("Brand term '$product_brand' already exists, ID: $term_id");
                    }

                    if ($term_id) {
                        $result = wp_set_object_terms($new_product_id, (int)$term_id, $brand_taxonomy, false);
                        if (is_wp_error($result)) {
                            error_log("Failed to assign brand '$product_brand' to product ID $new_product_id: " . $result->get_error_message());
                        } else {
                            error_log("Assigned brand '$product_brand' to product ID $new_product_id, term ID: $term_id");
                        }
                    }
                }

                if ($is_update && $needs_update) {
                    $results['updated_products']++;
                    error_log("Updated product with SKU: $sku due to changes");
                } elseif (!$is_update) {
                    $results['new_products']++;
                    error_log("Added new product with SKU: $sku");
                } else {
                    error_log("No changes detected for product with SKU: $sku");
                }

                unset($product_data, $product, $attributes);

            } catch (Exception $e) {
                error_log("Product sync error: " . $e->getMessage());
                $results['errors'][] = $e->getMessage();
            }
        }

        $remaining = max(0, $total_products - ($processed_count + count($products_to_process)));
        wp_send_json_success([
            'message' => 'Processed successfully',
            'results' => $results,
            'remaining' => $remaining,
            'processed_count' => $processed_count + count($products_to_process),
            'total_products' => $total_products
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



// Image upload function with improved duplicate check
function syncwoo_upload_product_image($image_url) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    // Check if image URL is empty
    if (empty($image_url)) {
        return new WP_Error('empty_image_url', __('Image URL is missing', 'syncwoo'));
    }

    // Check if image already exists in the media library
    $existing_id = attachment_url_to_postid($image_url);
    if ($existing_id) {
        return $existing_id; // Return existing image ID
    }

    // Download new image
    $tmp_file = download_url($image_url);
    if (is_wp_error($tmp_file)) {
        return new WP_Error('download_failed', __('Failed to download image', 'syncwoo'));
    }

    // Extract file name from URL
    $image_name = basename(parse_url($image_url, PHP_URL_PATH));

    // Ensure the file name is valid
    if (empty($image_name)) {
        @unlink($tmp_file); // Delete temporary file
        return new WP_Error('invalid_image_name', __('Invalid image name', 'syncwoo'));
    }

    // Check if an image with the same name exists in media library
    $existing_attachment = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'meta_query' => [
            [
                'key' => '_wp_attached_file',
                'value' => $image_name,
                'compare' => 'LIKE',
            ],
        ],
        'posts_per_page' => 1,
    ]);

    if (!empty($existing_attachment)) {
        return $existing_attachment[0]->ID; // Return existing image ID
    }

    $file_array = [
        'name' => $image_name,
        'tmp_name' => $tmp_file
    ];

    // Upload the image to WordPress media library
    $id = media_handle_sideload($file_array, 0);

    // Clean up temp file
    @unlink($tmp_file);

    return is_wp_error($id) ? $id : $id;
}