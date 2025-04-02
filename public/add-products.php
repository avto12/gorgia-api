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
 * Handle product synchronization via AJAX
 */
add_action('wp_ajax_syncwoo_perform_sync', 'syncwoo_perform_sync');
// Note: Removed wp_ajax_nopriv_ - this should be admin-only
function syncwoo_perform_sync() {
    // Clear any existing output
    if (ob_get_length()) {
        ob_end_clean();
    }

    // Verify nonce and capabilities
    if (!check_ajax_referer('syncwoo_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed'], 403);
        wp_die();
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized access'], 401);
        wp_die();
    }

    // Process request
    try {
        $file_path = WP_CONTENT_DIR . '/uploads/syncwoo-json/products.json';

        if (!file_exists($file_path)) {
            throw new Exception('JSON file not found at: ' . $file_path);
        }

        $json_data = file_get_contents($file_path);
        $data = json_decode($json_data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }

        // Process products...
        $results = [
            'processed' => 0,
            'errors' => []
        ];

//        gorgia
         foreach ($data as $product_data) {
             try {
                 if (empty($product_data['barcode'])) {
                     throw new Exception(__('Missing product barcode', 'syncwoo'));
                 }

                 $sku = sanitize_text_field($product_data['barcode']);
                 $product_id = wc_get_product_id_by_sku($sku);

                 // Create or update product
                 $product = $product_id ? new WC_Product($product_id) : new WC_Product_Simple();

                 // Set basic product details
                 $product->set_name(sanitize_text_field($product_data['product']));
                 $product->set_description(wp_kses_post($product_data['description']));
                 $product->set_regular_price($product_data['list_price']);
                 $product->set_sale_price($product_data['price']);
                 $product->set_sku($sku);
                 $product->set_manage_stock(true);
                 $product->set_stock_quantity(absint($product_data['amount'] ?? 0));

                 // Set weight if available
                 if (!empty($product_data['weight'])) {
                     $product->set_weight($product_data['weight']);
                 }

                 // Handle categories
                 $category_ids = [];
                 $categories = [$product_data['root_category'], $product_data['category']];
                 foreach ($categories as $category) {
                     if (!empty($category)) {
                         $term = term_exists($category, 'product_cat');
                         if (!$term) {
                             $term = wp_insert_term($category, 'product_cat');
                         }
                         if (!is_wp_error($term)) {
                             $category_ids[] = $term['term_id'];
                         }
                     }
                 }
                 if (!empty($category_ids)) {
                     $product->set_category_ids($category_ids);
                 }


                 // Handle product attributes(features)
                if (!empty($product_data['features'])) {
                   $attributes = $product->get_attributes();
                   $product_brand = null; // To store the brand value

                   foreach ($product_data['features'] as $feature) {
                      $name = trim($feature['feature']);
                      $value = trim($feature['variant']);
                      $feature_id = isset($feature['feature_id']) ? $feature['feature_id'] : 0;

                      // Skip empty values and specific features
                      if (empty($name) || empty($value) || $value === ' ') {
                         continue;
                      }

                      // SPECIAL CASE: Handle Brand (feature_id 5485)
                      if ($feature_id == 5485) {
                         $product_brand = $value; // Store brand for later processing
                         continue; // Skip attribute creation for brand
                      }

                      // Skip other excluded features
                      if (in_array($feature_id, [5489])) {
                         continue;
                      }

                      // 1. Create taxonomy slug
                      $taxonomy_slug = 'go_' . $feature_id;
                      $taxonomy = wc_attribute_taxonomy_name($taxonomy_slug);

                      // 2. Create Global Attribute if Missing
                      if (!taxonomy_exists($taxonomy)) {
                         $attribute_id = wc_create_attribute([
                            'name' => $name,
                            'slug' => $taxonomy_slug,
                            'type' => 'select'
                         ]);

                         if (!is_wp_error($attribute_id)) {
                            register_taxonomy($taxonomy, 'product', [
                               'labels' => ['name' => $name],
                               'hierarchical' => true
                            ]);
                         }
                      }

                      // 3. Handle term
                      $term = term_exists($value, $taxonomy);
                      if (!$term) {
                         $term = wp_insert_term($value, $taxonomy);
                         if (is_wp_error($term)) {
                            continue;
                         }
                         $term_id = $term['term_id'];
                      } else {
                         $term_id = $term['term_id'];
                      }

                      // Assign term to product
                      wp_set_object_terms($product->get_id(), (int)$term_id, $taxonomy, true);

                      // 4. Add to product attributes
                      if (!isset($attributes[$taxonomy])) {
                         $new_attr = new WC_Product_Attribute();
                         $new_attr->set_name($taxonomy);
                         $new_attr->set_visible(true);
                         $new_attr->set_variation(false);
                         $new_attr->set_options([$value]); // Using term name
                         $attributes[$taxonomy] = $new_attr;
                      } else {
                         $current_values = $attributes[$taxonomy]->get_options();
                         if (!in_array($value, $current_values)) {
                            $current_values[] = $value;
                            $attributes[$taxonomy]->set_options($current_values);
                         }
                      }
                   }

                   // Set product attributes
                   $product->set_attributes($attributes);

                   // Handle Brand separately (if found)
                   if ($product_brand) {
                      $brand_taxonomy = 'product_brand';

                      // Ensure brand taxonomy exists
                      if (!taxonomy_exists($brand_taxonomy)) {
                         register_taxonomy($brand_taxonomy, 'product', [
                            'labels' => ['name' => 'Brands'],
                            'hierarchical' => true
                         ]);
                      }

                      // Check if the brand already exists
                      $brand_term = term_exists($product_brand, $brand_taxonomy);
                      if (!$brand_term) {
                         // Create the brand if it doesn't exist
                         $brand_term = wp_insert_term($product_brand, $brand_taxonomy);
                         if (is_wp_error($brand_term)) {
                            $brand_term_id = 0;
                         } else {
                            $brand_term_id = $brand_term['term_id'];
                         }
                      } else {
                         $brand_term_id = $brand_term['term_id'];
                      }

                      // Assign the existing or newly created brand term to the product
                      if ($brand_term_id) {
                         wp_set_object_terms($product->get_id(), (int)$brand_term_id, $brand_taxonomy, true);
                      }
                   }

                   // Save once at the end
                   $product->save();
                }


                 // Set product image
                 if (!empty($product_data['images'][0])) {
                     // Set the first image as the product image
                     $image_id = syncwoo_upload_product_image($product_data['images'][0]);
                     if (is_wp_error($image_id)) {
                         error_log("Image upload failed: " . $image_id->get_error_message());
                     } else {
                         $product->set_image_id($image_id);
                     }
                 }

                // Set gallery images (if any)
                 if (!empty($product_data['images']) && count($product_data['images']) > 1) {
                     $gallery_ids = [];

                     // Loop through the images starting from the second image (index 1)
                     for ($pI = 1, $pIMax = count($product_data['images']); $pI < $pIMax; $pI++) {
                         $image_url = $product_data['images'][$pI];
                         $image_id = syncwoo_upload_product_image($image_url);

                         if (is_wp_error($image_id)) {
                             error_log("Gallery image upload failed: " . $image_id->get_error_message() . " | Image URL: " . $image_url);
                         } elseif (!$image_id || $image_id == 0) {
                             error_log("Gallery image upload failed: Image ID is invalid (0) | Image URL: " . $image_url);
                         } else {
                             $gallery_ids[] = $image_id; // Add to the gallery array
                         }
                     }

                     // Set gallery images if at least one was uploaded successfully
                     if (!empty($gallery_ids)) {
                         $product->set_gallery_image_ids($gallery_ids);
                     } else {
                         error_log("No valid gallery images uploaded.");
                     }
                 }
                 // Save the product
                 $product_id = $product->save();

                 if (!$product_id) {
                     throw new Exception(__('Failed to save product', 'syncwoo'));
                 }

             } catch (Exception $e) {
                 error_log("Error syncing product: " . $e->getMessage());
                 continue;
             }
         }

        // 4. Return success response
        wp_send_json_success([
            'message' => 'Sync completed',
            'results' => $results
        ]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    // Always die at the end
    wp_die();
}


/**
 * Helper function to upload product image
 */
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