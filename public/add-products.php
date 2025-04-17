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

// Mark initial manual sync as done
add_action('wp_ajax_syncwoo_mark_initial_manual_sync_done', function () {
    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        exit;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 401);
        exit;
    }

    update_option('syncwoo_initial_manual_sync_done', true);
    wp_send_json_success(['message' => 'Initial manual sync marked as done']);
});

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

    $local_dir = WP_CONTENT_DIR . '/uploads/syncwoo-json/';
    if (!file_exists($local_dir)) {
        wp_mkdir_p($local_dir);
    }
    $htaccess_file = $local_dir . '.htaccess';
    file_put_contents($htaccess_file, "Options -Indexes\n<FilesMatch \"\\.(php)$\">\n    Deny from all\n</FilesMatch>\n<FilesMatch \"\\.(css|js)$\">\n    Allow from all\n</FilesMatch>");

    try {
        $processed_count = isset($_POST['processed_count']) ? intval($_POST['processed_count']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;

        $file_path_0 = WP_CONTENT_DIR . '/uploads/syncwoo-json/product_0.json';
        $file_path_1 = WP_CONTENT_DIR . '/uploads/syncwoo-json/product_1.json';

        $data = [];
        $results = ['new_products' => 0, 'updated_products' => 0, 'deleted_products' => 0, 'errors' => []];

        // Clear transient to ensure fresh JSON data
        $cache_key = 'syncwoo_json_data';
        delete_transient($cache_key);

        // Check if files exist
        if (!file_exists($file_path_0) || !file_exists($file_path_1)) {
            $error_message = 'One or both JSON files are missing';
            error_log("SyncWoo Error: $error_message (product_0.json exists: " . (file_exists($file_path_0) ? 'yes' : 'no') . ", product_1.json exists: " . (file_exists($file_path_1) ? 'yes' : 'no') . ")");
            throw new Exception($error_message);
        }

        // Load product_0.json
        $json_data_0 = file_get_contents($file_path_0);
        if ($json_data_0 === false) {
            $error_message = 'Failed to read product_0.json';
            error_log("SyncWoo Error: $error_message");
            throw new Exception($error_message);
        }
        $decoded_data_0 = json_decode($json_data_0, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'JSON error in product_0.json: ' . json_last_error_msg();
            error_log("SyncWoo Error: $error_message");
            throw new Exception('Invalid JSON in product_0.json');
        }
        if (empty($decoded_data_0)) {
            error_log("SyncWoo Warning: product_0.json is empty");
        } else {
            error_log("SyncWoo: Loaded " . count($decoded_data_0) . " products from product_0.json");
        }
        $data = $decoded_data_0;
        unset($json_data_0, $decoded_data_0);

        // Load product_1.json
        $json_data_1 = file_get_contents($file_path_1);
        if ($json_data_1 === false) {
            $error_message = 'Failed to read product_1.json';
            error_log("SyncWoo Error: $error_message");
            throw new Exception($error_message);
        }
        $decoded_data_1 = json_decode($json_data_1, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'JSON error in product_1.json: ' . json_last_error_msg();
            error_log("SyncWoo Error: $error_message");
            throw new Exception('Invalid JSON in product_1.json');
        }
        if (empty($decoded_data_1)) {
            error_log("SyncWoo Warning: product_1.json is empty");
        } else {
            error_log("SyncWoo: Loaded " . count($decoded_data_1) . " products from product_1.json");
        }

        // Merge the data
        $data = array_merge($data, $decoded_data_1);
        error_log("SyncWoo: Total products after merging: " . count($data));
        unset($json_data_1, $decoded_data_1);

        // Remove duplicates based on barcode
        $unique_data = [];
        $barcodes = [];
        foreach ($data as $item) {
            $barcode = sanitize_text_field($item['barcode'] ?? '');
            if (empty($barcode)) {
                continue; // Skip items without a barcode
            }
            if (!in_array($barcode, $barcodes)) {
                $barcodes[] = $barcode;
                $unique_data[] = $item;
            } else {
                error_log("Duplicate barcode found and skipped: $barcode");
            }
        }
        $data = $unique_data;

        // Check if data is empty after filtering
        if (empty($data)) {
            $error_message = 'No valid data in JSON files after removing duplicates';
            error_log("SyncWoo Error: $error_message");
            throw new Exception($error_message);
        }

        set_transient($cache_key, $data, DAY_IN_SECONDS);

        $total_products = count($data);
        $products_to_process = array_slice($data, $processed_count, $batch_size);
        error_log("SyncWoo: Processing batch (processed_count: $processed_count, batch_size: $batch_size, total_products: $total_products)");

        // Get all SKUs from JSON
        $json_skus = array_map(function ($product) {
            return sanitize_text_field($product['barcode'] ?? '');
        }, $data);
 
        if (empty($products_to_process)) {
            error_log("SyncWoo: No more products to process");
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
                    $results['errors'][] = "Failed to create root category $root_category_name: " . $root_term->get_error_message();
                    continue;
                }
            }
            $root_category_ids[$root_category_name] = $root_term['term_id'];
            error_log("SyncWoo: Root category '$root_category_name' processed (ID: {$root_term['term_id']})");
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
                        $results['errors'][] = "Failed to create child category $child_category_name: " . $child_term->get_error_message();
                        continue;
                    }
                }
                $child_category_ids[$child_category_name] = $child_term['term_id'];
                error_log("SyncWoo: Child category '$child_category_name' processed (ID: {$child_term['term_id']})");
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

        $brand_taxonomy = 'product_brand';
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
                    $results['errors'][] = "Failed to create brand term '$brand': " . $term_result->get_error_message();
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

                // Add your product creation or update logic here
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
                        $product->get_stock_quantity() != absint($product_data['amount'] ?? 0) ||
                        $product->get_weight() != floatval($product_data['weight'] ?? 0)
                    );
                }

                $product->set_name(sanitize_text_field($product_data['product']));
                $product->set_description(wp_kses_post($product_data['description']));
                $product->set_regular_price(floatval($product_data['list_price']));
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
                                $results['errors'][] = "Failed to create attribute '$name': " . $attribute_id->get_error_message();
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
                                $results['errors'][] = "Failed to insert term '$value' for taxonomy '$taxonomy': " . $term_result->get_error_message();
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
                                $results['errors'][] = "Failed to set term '$value' for product ID {$product->get_id()}: " . $result->get_error_message();
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
                            $results['errors'][] = "Failed to create attribute 'ერთეული': " . $attribute->get_error_message();
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
                            $results['errors'][] = "Failed to insert term '$unit_value' for taxonomy '$taxonomy': " . $term_result->get_error_message();
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
                            $results['errors'][] = "Failed to set term '$unit_value' for product ID {$product->get_id()}: " . $result->get_error_message();
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
                    } else {
                        error_log("Failed to upload main image for SKU: $sku, URL: $new_image_url");
                        $results['errors'][] = "Failed to upload main image for SKU: $sku, URL: $new_image_url";
                    }
                }

                // Handle gallery images with strict duplicate prevention
                if (!empty($product_data['images']) && count($product_data['images']) > 1) {
                    $current_gallery_ids = $product->get_gallery_image_ids();

                    if (!in_array($new_image_id, $current_gallery_ids)) {
                        $product->set_gallery_image_ids(array_merge($current_gallery_ids, [$new_image_id]));
                    }

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
                            } else {
                                error_log("Failed to upload gallery image for SKU: $sku, URL: $gallery_image_url");
                                $results['errors'][] = "Failed to upload gallery image for SKU: $sku, URL: $gallery_image_url";
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
                            $results['errors'][] = "Failed to create brand term '$product_brand': " . $term_result->get_error_message();
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
                            $results['errors'][] = "Failed to assign brand '$product_brand' to product ID $new_product_id: " . $result->get_error_message();
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
        error_log("SyncWoo: Batch processed (processed_count: " . ($processed_count + count($products_to_process)) . ", remaining: $remaining)");
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

// Render Product Sync page
function render_product_sync_page_with_frequency() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'syncwoo'));
    }

    ?>
    <div class="wrap">
        <?php
        $json_files = [
            'product_0' => WP_CONTENT_DIR . '/uploads/syncwoo-json/product_0.json',
            'product_1' => WP_CONTENT_DIR . '/uploads/syncwoo-json/product_1.json',
        ];

        foreach ($json_files as $key => $json_file) {
            if (file_exists($json_file)) {
                $json_data = file_get_contents($json_file);
                $data = json_decode($json_data, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo '<p>' . esc_html__('Invalid JSON file: ', 'syncwoo') . $key . ' - ' . json_last_error_msg() . '</p>';
                } else {
                    $product_count = is_array($data) ? count($data) : 0;
                    echo '<p>' . esc_html__('Total Products in JSON file ', 'syncwoo') . esc_html($key) . ': <strong>' . esc_html($product_count) . '</strong></p>';
                }
            } else {
                echo '<p>' . esc_html__('JSON file not found: ', 'syncwoo') . esc_html($key) . '</p>';
            }
        }
        ?>

        <h2><?php _e('SyncWoo - Product Sync', 'syncwoo'); ?></h2>
        <p><?php _e('Click "Start Sync" to start syncing products from the JSON file.', 'syncwoo'); ?></p>

        <button id="syncwoo-manual-button" class="button button-primary">Start Sync</button>
        <button id="syncwoo-manual-cancel" class="button">Cancel</button>
        <div id="syncwoo-manual-result"></div>
        <div style="width: 100%; background: #e1e1e1; height: 20px; margin-top: 10px;">
            <div class="progress-bar-manual" style="width: 0%; height: 100%;"></div>
        </div>

        <!-- Product Update Frequency Section -->
        <div class="syncwoo-product-update-frequency">
            <h2><?php esc_html_e('Product Update Frequency', 'syncwoo'); ?></h2>
            <h3 style="color:#ff0000"><?php esc_html_e('Select Manual(Start Sync) after synchronization!', 'syncwoo'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="syncwoo-product-frequency-form">
                <input type="hidden" name="action" value="syncwoo_save_product_frequency">
                <?php
                wp_nonce_field('syncwoo_save_product_frequency_action', 'syncwoo_save_product_frequency_nonce');

                $frequency = get_option('syncwoo_product_update_frequency', 'never');
                $schedules = wp_get_schedules();

                $available = [
                    'never',
                    'every_1_minute',
                    'every_2_minutes',
                    'every_3_minutes',
                    'every_4_minutes',
                    'every_5_minutes',
                    'every_10_minutes',
                    'every_15_minutes',
                    'every_20_minutes',
                    'every_30_minutes',
                    'hourly',
                    'every_2_hours',
                    'every_3_hours',
                    'every_4_hours',
                    'every_5_hours',
                    'three_times_a_day',
                    'twicedaily',
                    'daily',
                    'weekly',
                ];

                echo '<select name="syncwoo_product_update_frequency">';
                foreach ($available as $schedule) {
                    if ($schedule === 'never') {
                        echo '<option value="never" ' . selected($frequency, 'never', false) . '>';
                        echo esc_html__('Never', 'syncwoo');
                        echo '</option>';
                    } elseif (isset($schedules[$schedule])) {
                        echo '<option value="' . esc_attr($schedule) . '" ' . selected($frequency, $schedule, false) . '>';
                        echo esc_html($schedules[$schedule]['display']);
                        echo '</option>';
                    }
                }
                echo '</select>';
                ?>
                <div class="description">
                    <?php
                    echo '<span class="last-sync">' . esc_html__('Last product update: ', 'syncwoo') . '</span>';
                    ?>
                    <span class="last-sync"><?php
                    echo get_option('syncwoo_last_product_update') ? esc_html(get_option('syncwoo_last_product_update')) : __('Never', 'syncwoo');
                    ?></span>
                    <hr>
                    <span class="sync-interval">
                        <?php
                        $frequency = get_option('syncwoo_product_update_frequency', 'never');
                        $schedules = wp_get_schedules();

                        $time_remaining = 0;
                        if ($frequency !== 'never' && isset($schedules[$frequency])) {
                            $next_sync_timestamp = wp_next_scheduled('syncwoo_product_update_sync');
                            $current_time = time();

                            if ($next_sync_timestamp) {
                                $time_remaining = $next_sync_timestamp - $current_time;
                            }
                        }
                        ?>
                        <div id="countdown-timer-product-update" data-remaining="<?php echo esc_attr($time_remaining); ?>">
                            <span><?php esc_html_e('Time until next product update: ', 'syncwoo'); ?></span>
                            <span id="time-remaining-product-update"><?php echo ($frequency === 'never') ? esc_html__('Disabled', 'syncwoo') : ''; ?></span>
                        </div>
                    </span>
                    <hr>
                    <span class="first-sync">
                        <?php esc_html_e('Note: This controls how often products are updated, added, or deleted based on the JSON data.', 'syncwoo'); ?>
                    </span>
                    <hr>
                    <?php
                    echo '<br>' . esc_html__('Note: The frequency of the product update may be affected by your server settings.', 'syncwoo');
                    echo '<br>' . esc_html__('For example, if your server has a limit of 1 request per minute, the update will not run more frequently than that.', 'syncwoo');
                    echo '<hr>' . esc_html__('This process runs automatically after the initial manual sync.', 'syncwoo');
                    echo '<br>' . esc_html__('Happy updating!', 'syncwoo');
                    ?>
                </div>
                <p>
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save', 'syncwoo'); ?>">
                </p>
            </form>
        </div>
    </div>
    <?php
}


add_action('syncwoo_render_product_sync_page', 'render_product_sync_page_with_frequency');

// Add custom cron schedules
add_filter('cron_schedules', function($schedules) {
    $intervals = [
        'every_1_minute' => 60,
        'every_2_minutes' => 120,
        'every_3_minutes' => 180,
        'every_4_minutes' => 240,
        'every_5_minutes' => 300,
        'every_10_minutes' => 600,
        'every_15_minutes' => 900,
        'every_20_minutes' => 1200,
        'every_30_minutes' => 1800,
        'hourly' => 3600,
        'every_2_hours' => 7200,
        'every_3_hours' => 10800,
        'every_4_hours' => 14400,
        'every_5_hours' => 18000,
        'three_times_a_day' => 28800,
        'twicedaily' => 43200,
        'daily' => 86400,
        'weekly' => 604800,
    ];

    foreach ($intervals as $key => $interval) {
        if (!isset($schedules[$key])) {
            $schedules[$key] = [
                'interval' => $interval,
                'display' => ucwords(str_replace('_', ' ', $key)),
            ];
        }
    }

    return $schedules;
});

// Schedule/reschedule the product update cron job
function schedule_product_update_cron() {
    $frequency = get_option('syncwoo_product_update_frequency', 'never');
    error_log('SyncWoo: Scheduling product update cron job with frequency: ' . $frequency);

    // Clear any existing scheduled hooks
    while ($timestamp = wp_next_scheduled('syncwoo_product_update_sync')) {
        error_log('SyncWoo: Clearing existing product update scheduled hook at timestamp: ' . $timestamp);
        wp_unschedule_event($timestamp, 'syncwoo_product_update_sync');
    }

    // Only schedule if frequency is not 'never' and initial manual sync has been done
    $initial_sync_done = get_option('syncwoo_initial_manual_sync_done', false);
    if ($frequency !== 'never' && $initial_sync_done) {
        wp_schedule_event(time(), $frequency, 'syncwoo_product_update_sync');
        error_log('SyncWoo: Product update cron job scheduled with frequency: ' . $frequency);
    } else {
        error_log('SyncWoo: Cron not scheduled (frequency: ' . $frequency . ', initial_sync_done: ' . ($initial_sync_done ? 'true' : 'false') . ')');
    }
}

// Register plugin settings
add_action('admin_init', function() {
    register_setting('syncwoo_product_settings', 'syncwoo_product_update_frequency', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'never' // Changed default to 'never'
    ]);

    register_setting('syncwoo_product_settings', 'syncwoo_last_product_update', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);

    register_setting('syncwoo_product_settings', 'syncwoo_initial_product_update_done', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ]);

    register_setting('syncwoo_product_settings', 'syncwoo_processed_count', [
        'type' => 'integer',
        'sanitize_callback' => 'intval',
        'default' => 0
    ]);
});

// Handle saving product update frequency
add_action('admin_post_syncwoo_save_product_frequency', function() {
    if (!current_user_can('manage_options') ||
        !isset($_POST['syncwoo_save_product_frequency_nonce']) ||
        !wp_verify_nonce($_POST['syncwoo_save_product_frequency_nonce'], 'syncwoo_save_product_frequency_action')) {
        wp_die(__('Invalid request', 'syncwoo'));
    }

    if (isset($_POST['syncwoo_product_update_frequency'])) {
        $frequency = sanitize_text_field($_POST['syncwoo_product_update_frequency']);
        update_option('syncwoo_product_update_frequency', $frequency);
        schedule_product_update_cron();
        add_settings_error('syncwoo_product_messages', 'syncwoo_product_message', __('Product update frequency saved successfully', 'syncwoo'), 'updated');
    } else {
        add_settings_error('syncwoo_product_messages', 'syncwoo_product_message', __('No frequency selected', 'syncwoo'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=syncwoo-product-sync'));
    exit;
});

// Clear cron jobs during manual sync
add_action('wp_ajax_syncwoo_clear_cron', function() {
    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        exit;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 401);
        exit;
    }

    while ($timestamp = wp_next_scheduled('syncwoo_product_update_sync')) {
        wp_unschedule_event($timestamp, 'syncwoo_product_update_sync');
    }

    wp_send_json_success(['message' => 'Cron jobs cleared']);
});

// Reschedule cron jobs after manual sync
add_action('wp_ajax_syncwoo_reschedule_cron', function() {
    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        exit;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 401);
        exit;
    }

    schedule_product_update_cron();
    wp_send_json_success(['message' => 'Cron jobs rescheduled']);
});

// Reset initial update flag after manual sync
add_action('wp_ajax_syncwoo_reset_initial_update', function() {
    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        exit;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 401);
        exit;
    }

    update_option('syncwoo_initial_product_update_done', false);
    update_option('syncwoo_processed_count', 0);
    wp_send_json_success(['message' => 'Initial update flag reset']);
});

// Perform product update (delta sync for products)
add_action('syncwoo_product_update_sync', function () {
    $json_files = [
        'product_0' => WP_CONTENT_DIR . '/uploads/syncwoo-json/product_0.json',
        'product_1' => WP_CONTENT_DIR . '/uploads/syncwoo-json/product_1.json',
    ];

    $data = [];
    $errors = [];

    foreach ($json_files as $key => $json_file) {
        error_log("SyncWoo: Checking file: $json_file");
        if (file_exists($json_file)) {
            $json_data = file_get_contents($json_file);
            error_log("SyncWoo: File $json_file loaded successfully");
            $decoded_data = json_decode($json_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = sprintf(__('Invalid JSON file: %s - %s', 'syncwoo'), $key, json_last_error_msg());
                error_log("SyncWoo Error: Invalid JSON file: $key - " . json_last_error_msg());
                continue;
            }
            $data = array_merge($data, $decoded_data);
            error_log("SyncWoo: Loaded " . count($decoded_data) . " products from $key (cron sync)");
        } else {
            $errors[] = sprintf(__('JSON file not found: %s', 'syncwoo'), $key);
            error_log("SyncWoo Error: JSON file not found: $key");
        }
    }

    if (empty($data)) {
        error_log('SyncWoo: No data to process for product update (cron sync)');
        return ['success' => false, 'message' => __('No data to process for product update', 'syncwoo'), 'errors' => $errors];
    }

    try {
        $total_products = count($data);
        $batch_size = 30;
        $processed_count = get_option('syncwoo_processed_count', 0);
        $results = ['new_products' => 0, 'updated_products' => 0, 'deleted_products' => 0, 'errors' => $errors];

        error_log("SyncWoo: Starting cron sync (processed_count: $processed_count, batch_size: $batch_size, total_products: $total_products)");

        $products_to_process = array_slice($data, $processed_count, $batch_size);

        $snapshot = get_option('syncwoo_product_snapshot', []);
        $current_snapshot = [];
        foreach ($data as $product) {
            if (isset($product['barcode']) && isset($product['last_update'])) {
                $current_snapshot[$product['barcode']] = [
                    'last_update' => $product['last_update'],
                    'name' => $product['product'] ?? '',
                    'list_price' => $product['list_price'] ?? '',
                    'amount' => $product['amount'] ?? 0,
                    'weight' => $product['weight'] ?? 0,
                    'description' => $product['description'] ?? ''
                ];
            }
        }

        $json_skus = array_map(function ($product) {
            return sanitize_text_field($product['barcode'] ?? '');
        }, $data);
        error_log("SyncWoo: Found " . count($json_skus) . " unique SKUs in JSON");


        if (empty($products_to_process)) {
            update_option('syncwoo_product_snapshot', $current_snapshot);
            update_option('syncwoo_processed_count', 0);
            update_option('syncwoo_last_product_update', current_time('mysql'));
            error_log("SyncWoo: Cron sync completed (no more products to process)");
            return ['success' => true, 'message' => 'Cron sync completed', 'results' => $results, 'total_products' => $total_products, 'errors' => $results['errors']];
        }

        $root_categories = [];
        $child_categories = [];
        foreach ($products_to_process as $product_data) {
            if (!empty($product_data['root_category'])) $root_categories[$product_data['root_category']] = true;
            if (!empty($product_data['category']) && !empty($product_data['root_category'])) {
                $child_categories[$product_data['root_category']][$product_data['category']] = true;
            }
        }

        $root_category_ids = [];
        foreach (array_keys($root_categories) as $root_category_name) {
            $latin_slug = sanitize_title_with_dashes(transliterate_georgian_to_latin($root_category_name));
            $root_term = term_exists($root_category_name, 'product_cat');
            if (!$root_term) $root_term = wp_insert_term($root_category_name, 'product_cat', ['slug' => $latin_slug]);
            if (!is_wp_error($root_term)) $root_category_ids[$root_category_name] = $root_term['term_id'];
            error_log("SyncWoo: Root category '$root_category_name' processed (ID: {$root_term['term_id']}) (cron sync)");
        }

        $child_category_ids = [];
        foreach ($child_categories as $root_category_name => $children) {
            $parent_id = $root_category_ids[$root_category_name] ?? 0;
            foreach (array_keys($children) as $child_category_name) {
                $latin_slug = sanitize_title_with_dashes(transliterate_georgian_to_latin($child_category_name));
                $child_term = term_exists($child_category_name, 'product_cat');
                if (!$child_term) $child_term = wp_insert_term($child_category_name, 'product_cat', ['slug' => $latin_slug, 'parent' => $parent_id]);
                if (!is_wp_error($child_term)) $child_category_ids[$child_category_name] = $child_term['term_id'];
                error_log("SyncWoo: Child category '$child_category_name' processed (ID: {$child_term['term_id']}) (cron sync)");
            }
        }

        $brands = [];
        foreach ($products_to_process as $product_data) {
            if (!empty($product_data['features'])) foreach ($product_data['features'] as $f) {
                if (isset($f['feature_id']) && $f['feature_id'] == 5485 && !empty(trim($f['variant']))) $brands[trim($f['variant'])] = true;
            }
        }

        $brand_taxonomy = 'product_brand';
        if (!taxonomy_exists($brand_taxonomy)) {
            register_taxonomy($brand_taxonomy, 'product', ['labels' => ['name' => 'Brands'], 'hierarchical' => false, 'public' => true]);
            error_log("Created brand taxonomy '$brand_taxonomy' (cron sync)");
        }

        foreach (array_keys($brands) as $brand) {
            $term = term_exists($brand, $brand_taxonomy);
            if (!$term) {
                $term_result = wp_insert_term($brand, $brand_taxonomy);
                if (!is_wp_error($term_result)) error_log("Created brand term '$brand', ID: " . $term_result['term_id'] . " (cron sync)");
            } else error_log("Brand term '$brand' already exists (cron sync)");
        }

        foreach ($products_to_process as $product_data) {
            try {
                $sku = trim(sanitize_text_field($product_data['barcode'] ?? ''));
                if (empty($sku)) {
                    $errors[] = "Skipping product due to empty barcode";
                    error_log("SyncWoo: Skipping product due to empty barcode");
                    continue;
                }

                $product_id = wc_get_product_id_by_sku($sku);
                $is_update = $product_id > 0;
                $last_update = $product_data['last_update'] ?? '';
                $current_data = $current_snapshot[$sku] ?? [];

                if ($is_update) {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        $is_update = false;
                        $product = new WC_Product_Simple();
                        error_log("SyncWoo: Product with SKU $sku (ID: $product_id) could not be loaded, creating new product (cron sync)");
                    }
                    $stored_last_update = get_post_meta($product_id, '_syncwoo_last_update', true) ?? '';
                    $stored_name = $product->get_name() ?? '';
                    $stored_list_price = $product->get_regular_price() ?: '0';
                    $stored_amount = $product->get_stock_quantity() ?? 0;
                    $stored_weight = $product->get_weight() ?? 0;
                    $stored_description = $product->get_description() ?? '';
                } else $product = new WC_Product_Simple();

                $needs_update = !$is_update || (
                    $stored_last_update !== $last_update ||
                    $stored_name !== ($product_data['product'] ?? '') ||
                    $stored_list_price !== strval(floatval($product_data['list_price'] ?? 0)) ||
                    $stored_amount !== absint($product_data['amount'] ?? 0) ||
                    $stored_weight !== floatval($product_data['weight'] ?? 0) ||
                    $stored_description !== ($product_data['description'] ?? '')
                );
                error_log("SyncWoo: Checking SKU $sku, needs_update: $needs_update (last_update: $stored_last_update vs $last_update, name: $stored_name vs " . ($product_data['product'] ?? '') . " " . strval(floatval($product_data['price'] ?? 0)) . ")");

                $product->set_name(sanitize_text_field($product_data['product'] ?? ''));
                $product->set_description(wp_kses_post($product_data['description'] ?? ''));
                $product->set_regular_price(floatval($product_data['list_price'] ?? 0));
                $product->set_sku($sku);
                $product->set_manage_stock(true);
                $product->set_stock_quantity(absint($product_data['amount'] ?? 0));
                if (!empty($product_data['weight'])) $product->set_weight(floatval($product_data['weight']));

                $category_ids = [];
                if (!empty($product_data['root_category']) && isset($root_category_ids[$product_data['root_category']])) {
                    $category_ids[] = $root_category_ids[$product_data['root_category']];
                }
                if (!empty($product_data['category']) && isset($child_category_ids[$product_data['category']])) {
                    $category_ids[] = $child_category_ids[$product_data['category']];
                }
                if (!empty($category_ids)) $product->set_category_ids($category_ids);

                $attributes = $product->get_attributes();
                $product_brand = null;

                if (!empty($product_data['features'])) foreach ($product_data['features'] as $feature) {
                    $name = trim($feature['feature']);
                    $value = trim($feature['variant']);
                    $feature_id = $feature['feature_id'] ?? 0;
                    if (empty($name) || empty($value) || $value === ' ') continue;
                    if ($feature_id == 5485) {
                        $product_brand = $value;
                        continue;
                    }
                    $taxonomy = wc_attribute_taxonomy_name('go_' . $feature_id);
                    if (!taxonomy_exists($taxonomy)) {
                        $attribute_id = wc_create_attribute(['name' => $name, 'slug' => 'go_' . $feature_id, 'type' => 'select']);
                        if (!is_wp_error($attribute_id)) {
                            register_taxonomy($taxonomy, 'product', ['labels' => ['name' => $name], 'hierarchical' => false, 'public' => true]);
                            error_log("Created taxonomy '$taxonomy' for attribute '$name' (cron sync)");
                        }
                    }
                    $term = term_exists($value, $taxonomy);
                    $term_id = $term ? (is_array($term) ? $term['term_id'] : $term) : null;
                    if (!$term_id) {
                        $term_result = wp_insert_term($value, $taxonomy);
                        if (!is_wp_error($term_result)) $term_id = $term_result['term_id'];
                    }
                    if ($term_id) wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, false);
                    if (!isset($attributes[$taxonomy])) {
                        $attr = new WC_Product_Attribute();
                        $attr->set_id(wc_attribute_taxonomy_id_by_name('go_' . $feature_id));
                        $attr->set_name($taxonomy);
                        $attr->set_options([$value]);
                        $attr->set_position(0);
                        $attr->set_visible(true);
                        $attr->set_variation(false);
                        $attributes[$taxonomy] = $attr;
                    }
                }

                if (!empty($product_data['Unit'])) {
                    $unit_value = sanitize_text_field($product_data['Unit']);
                    $taxonomy = 'pa_unit';
                    if (!taxonomy_exists($taxonomy)) {
                        $attribute = wc_create_attribute(['name' => 'ერთეული', 'slug' => 'unit', 'type' => 'select']);
                        if (!is_wp_error($attribute)) {
                            register_taxonomy($taxonomy, 'product', ['labels' => ['name' => 'ერთეული'], 'hierarchical' => false, 'public' => true]);
                            error_log("Created taxonomy '$taxonomy' for 'ერთეული' (cron sync)");
                        }
                    }
                    $term = term_exists($unit_value, $taxonomy);
                    $term_id = $term ? (is_array($term) ? $term['term_id'] : $term) : null;
                    if (!$term_id) {
                        $term_result = wp_insert_term($unit_value, $taxonomy);
                        if (!is_wp_error($term_result)) $term_id = $term_result['term_id'];
                    }
                    if ($term_id) wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, false);
                    if (!isset($attributes[$taxonomy])) {
                        $attr = new WC_Product_Attribute();
                        $attr->set_id(wc_attribute_taxonomy_id_by_name('unit'));
                        $attr->set_name($taxonomy);
                        $attr->set_options([$unit_value]);
                        $attr->set_position(0);
                        $attr->set_visible(true);
                        $attr->set_variation(false);
                        $attributes[$taxonomy] = $attr;
                    }
                }

                $product->set_attributes($attributes);

                // Handle main image
                $current_image_id = $product->get_image_id();
                $new_image_urls = $product_data['images'] ?? [];
                if (!empty($new_image_urls[0])) {
                    $new_image_url = rtrim($new_image_urls[0], '/');
                    $new_image_id = syncwoo_upload_product_image($new_image_url);
                    if ($new_image_id && !is_wp_error($new_image_id)) {
                        $current_url_hash = $current_image_id ? get_post_meta($current_image_id, '_syncwoo_image_url_hash', true) : '';
                        $new_url_hash = md5($new_image_url);
                        if ($current_image_id && $current_url_hash === $new_url_hash) {
                            error_log("SyncWoo: Main image unchanged for SKU: $sku, URL: $new_image_url (hash: $new_url_hash) (cron sync)");
                        } else {
                            $product->set_image_id($new_image_id);
                            error_log("SyncWoo: Main image set/updated for SKU: $sku, URL: $new_image_url (hash: $new_url_hash) (cron sync)");
                        }
                    }
                } elseif ($current_image_id) {
                    wp_delete_attachment($current_image_id, true);
                    $product->set_image_id(0);
                    error_log("SyncWoo: Main image deleted for SKU: $sku (cron sync)");
                }

                // Handle gallery images
                $current_gallery_ids = $product->get_gallery_image_ids();
                $current_gallery_hashes = array_map(function ($id) {
                    return get_post_meta($id, '_syncwoo_image_url_hash', true);
                }, $current_gallery_ids);
                $new_gallery_ids = [];
                $json_image_hashes = array_map('md5', array_filter($new_image_urls));
                if (!empty($new_image_urls) && count($new_image_urls) > 1) {
                    for ($i = 1; $i < count($new_image_urls); $i++) {
                        $gallery_image_url = rtrim($new_image_urls[$i], '/');
                        $gallery_image_hash = md5($gallery_image_url);
                        if (!in_array($gallery_image_hash, $current_gallery_hashes)) {
                            $gallery_image_id = syncwoo_upload_product_image($gallery_image_url);
                            if ($gallery_image_id && !is_wp_error($gallery_image_id)) {
                                $new_gallery_ids[] = $gallery_image_id;
                                error_log("SyncWoo: New gallery image added for SKU: $sku, URL: $gallery_image_url (hash: $gallery_image_hash) (cron sync)");
                            }
                        }
                    }
                }
                // Delete gallery images not in JSON
                foreach ($current_gallery_ids as $gallery_id) {
                    $gallery_hash = get_post_meta($gallery_id, '_syncwoo_image_url_hash', true);
                    if (!in_array($gallery_hash, $json_image_hashes)) {
                        wp_delete_attachment($gallery_id, true);
                        error_log("SyncWoo: Gallery image deleted for SKU: $sku, ID: $gallery_id (hash: $gallery_hash) (cron sync)");
                    }
                }
                if (!empty($new_gallery_ids)) {
                    $updated_gallery_ids = array_merge(array_diff($current_gallery_ids, array_filter($current_gallery_ids, function ($id) use ($json_image_hashes) {
                        return in_array(get_post_meta($id, '_syncwoo_image_url_hash', true), $json_image_hashes);
                    })), $new_gallery_ids);
                    $product->set_gallery_image_ids($updated_gallery_ids);
                    error_log("SyncWoo: Gallery updated for SKU: $sku, new IDs: " . implode(', ', $new_gallery_ids) . " (cron sync)");
                } elseif (empty($new_image_urls)) {
                    $product->set_gallery_image_ids([]);
                    error_log("SyncWoo: Gallery cleared for SKU: $sku (cron sync)");
                }

                $new_product_id = $product->save();
                if (!$new_product_id) throw new Exception("Failed to save product with SKU: $sku");

                if ($product_brand) {
                    $term = term_exists($product_brand, $brand_taxonomy);
                    $term_id = $term ? (is_array($term) ? $term['term_id'] : $term) : null;
                    if (!$term_id) {
                        $term_result = wp_insert_term($product_brand, $brand_taxonomy);
                        if (!is_wp_error($term_result)) $term_id = $term_result['term_id'];
                    }
                    if ($term_id) wp_set_object_terms($new_product_id, (int)$term_id, $brand_taxonomy, false);
                }

                update_post_meta($new_product_id, '_syncwoo_last_update', $last_update);

                if ($is_update && $needs_update) {
                    $results['updated_products']++;
                    error_log("SyncWoo: Updated product with SKU: $sku due to changes (cron sync)");
                } elseif (!$is_update) {
                    $results['new_products']++;
                    error_log("SyncWoo: Added new product with SKU: $sku (cron sync)");
                } else {
                    error_log("SyncWoo: No changes detected for product with SKU: $sku (cron sync)");
                }
            } catch (Exception $e) {
                $error_msg = "Product sync error for SKU $sku: " . $e->getMessage();
                error_log("SyncWoo Error: $error_msg");
                $results['errors'][] = $error_msg;
                continue;
            }
        }

        update_option('syncwoo_product_snapshot', $current_snapshot);
        $new_processed_count = $processed_count + count($products_to_process);
        update_option('syncwoo_processed_count', $new_processed_count);
        error_log("SyncWoo: Cron batch processed (processed_count: $new_processed_count, remaining: " . max(0, $total_products - $new_processed_count) . ")");

        return ['success' => true, 'message' => 'Processed successfully', 'results' => $results, 'total_products' => $total_products, 'errors' => $results['errors']];
    } catch (Exception $e) {
        $error_msg = 'SyncWoo Error: ' . $e->getMessage();
        error_log("SyncWoo Error: $error_msg");
        return ['success' => false, 'message' => __('Product update failed: ', 'syncwoo') . $e->getMessage(), 'errors' => array_merge($errors, [$error_msg])];
    }
});