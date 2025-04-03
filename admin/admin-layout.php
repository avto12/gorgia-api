<?php
defined('ABSPATH') || exit;


function syncwoo_enqueue_style()
{
   // Enqueue your css file
   wp_enqueue_style( 'layout-css', plugin_dir_url(__FILE__) . './css/layout.css', array(), time(), 'all' );


}
add_action('admin_enqueue_scripts', 'syncwoo_enqueue_style');



if ( ! class_exists( 'sync_woo_json_importer' ) ) {
    class sync_woo_json_importer
    {
        public function __construct() {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_syncwoo_manual_sync', [$this, 'handle_manual_sync']);
            add_action('syncwoo_scheduled_sync', [$this, 'perform_sync']);
            
            // Add cron schedule filter
            add_filter('cron_schedules', [$this, 'add_cron_schedules']);
            
            // Schedule the cron job
            $this->schedule_cron();
        }


 

       // Add custom cron schedules

        public function add_cron_schedules($schedules) {
            // 1 minute interval
            if (!isset($schedules['every_1_minute'])) {
                $schedules['every_1_minute'] = array(
                    'interval' => 60,
                    'display' => __('Every 1 Minute', 'syncwoo')
                );
            }
            
            // 10 minutes interval
            if (!isset($schedules['every_10_minutes'])) {
                $schedules['every_10_minutes'] = array(
                    'interval' => 600,
                    'display' => __('Every 10 Minutes', 'syncwoo')
                );
            }
            
            // 15 minutes interval
            if (!isset($schedules['every_15_minutes'])) {
                $schedules['every_15_minutes'] = array(
                    'interval' => 900,
                    'display' => __('Every 15 Minutes', 'syncwoo')
                );
            }
            
            // 20 minutes interval
            if (!isset($schedules['every_20_minutes'])) {
                $schedules['every_20_minutes'] = array(
                    'interval' => 1200,
                    'display' => __('Every 20 Minutes', 'syncwoo')
                );
            }
            
            // 30 minutes interval
            if (!isset($schedules['every_30_minutes'])) {
                $schedules['every_30_minutes'] = array(
                    'interval' => 1800,
                    'display' => __('Every 30 Minutes', 'syncwoo')
                );
            }
            
            return $schedules;
        }

        // Schedule/reschedule the cron job
        public function schedule_cron() {
            $frequency = get_option('syncwoo_sync_frequency', 'hourly');
    
            // Clear existing schedule
            wp_clear_scheduled_hook('syncwoo_scheduled_sync');
            
            // Set new schedule only if no schedule exists
            if (!wp_next_scheduled('syncwoo_scheduled_sync')) {
                wp_schedule_event(time(), $frequency, 'syncwoo_scheduled_sync');
            }
        }
 
        // Add admin menu
        public function add_admin_menu() {
            add_menu_page(
                __('SyncWoo Settings', 'syncwoo'),
                __('SyncWoo', 'syncwoo'),
                'manage_options',
                'syncwoo',
                [$this, 'render_settings_page'],
                'dashicons-update',
                55
            );
        }

        // Register plugin settings
        public function register_settings() {
            register_setting('syncwoo_settings', 'syncwoo_json_url', [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => ''
            ]);

            register_setting('syncwoo_settings', 'syncwoo_sync_frequency', [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'hourly'
            ]);

            register_setting('syncwoo_settings', 'syncwoo_last_sync', [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]);

            add_settings_section(
                'syncwoo_main_section',
                __('JSON Synchronization Settings', 'syncwoo'),
                [$this, 'render_section_header'],
                'syncwoo'
            );

            add_settings_field(
                'syncwoo_json_url',
                __('JSON Feed URL', 'syncwoo'),
                [$this, 'render_json_url_field'],
                'syncwoo',
                'syncwoo_main_section'
            );

            add_settings_field(
                'syncwoo_sync_frequency',
                __('Synchronization Frequency', 'syncwoo'),
                [$this, 'render_sync_frequency_field'],
                'syncwoo',
                'syncwoo_main_section'
            );
        }

        // Render settings page
        public function render_settings_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'syncwoo'));
            }

            ?>
            <div class="wrap">
                <h1><?= esc_html__('SyncWoo JSON Settings', 'syncwoo'); ?></h1>

                <form method="post" action="options.php">
                    <?php
                    settings_fields('syncwoo_settings');
                    do_settings_sections('syncwoo');
                    submit_button(__('Save Settings', 'syncwoo'));
                    ?>
                </form>

                <div class="syncwoo-actions">
                    <h2><?php esc_html_e('Manual Synchronization', 'syncwoo'); ?></h2>
                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="syncwoo-sync-form">
                        <input type="hidden" name="action" value="syncwoo_manual_sync">
                        <?php
                        wp_nonce_field('syncwoo_manual_sync_action', 'syncwoo_manual_sync_nonce');
                        ?>
                        <p>
                            <input type="submit" class="button button-primary" value="<?php
                            esc_attr_e('Run Sync Now', 'syncwoo');
                            ?>">
                            <span class="description">
                                <?php
                                esc_html_e('Last sync: ', 'syncwoo');
                                echo get_option('syncwoo_last_sync') ? esc_html(get_option('syncwoo_last_sync')) : __('Never', 'syncwoo');
                                ?>
                        </span>
                        </p>
                    </form>
                </div>
            </div>




            <div class="wrap">
                <h1><?php _e('SyncWoo - Product Sync', 'syncwoo'); ?></h1>
                <p><?php _e('Click "Sync Now" to start syncing products from the JSON file.', 'syncwoo'); ?></p>

                <button id="syncwoo-button" class="button button-primary">
                   <?php _e('Sync Now', 'syncwoo'); ?>
                </button>


                <button id="syncwoo-cancel" class="button button-secondary">
                   <?php _e('Stop Sync', 'syncwoo'); ?>
                </button>

                <div id="syncwoo-result"></div>
            </div>

            <?php
        }



        // Handle manual sync
        public function handle_manual_sync() {
            if (!current_user_can('manage_options') ||
                !isset($_POST['syncwoo_manual_sync_nonce']) ||
                !wp_verify_nonce($_POST['syncwoo_manual_sync_nonce'], 'syncwoo_manual_sync_action')) {
                wp_die(__('Invalid request', 'syncwoo'));
            }

            $result = $this->perform_sync();

            if ($result['success']) {
                add_settings_error('syncwoo_messages', 'syncwoo_message', $result['message'], 'updated');
            } else {
                add_settings_error('syncwoo_messages', 'syncwoo_message', $result['message'], 'error');
            }

            set_transient('settings_errors', get_settings_errors(), 30);

            wp_safe_redirect(admin_url('admin.php?page=syncwoo'));
            exit;
        }

        // Perform the actual sync
       public function perform_sync() {
          $json_url = get_option('syncwoo_json_url');

          error_log('SyncWoo: Starting sync process for URL: ' . $json_url);

          if (empty($json_url)) {
             error_log('SyncWoo: No JSON URL configured');
             return [
                'success' => false,
                'message' => __('No JSON URL configured', 'syncwoo')
             ];
          }

          try {
             $upload_dir = wp_upload_dir();
             $local_dir = $upload_dir['basedir'] . '/syncwoo-json/';

             // Ensure directory exists and is writable
             if (!file_exists($local_dir)) {
                if (!wp_mkdir_p($local_dir)) {
                   throw new Exception(__('Failed to create directory', 'syncwoo'));
                }
             }

             // Get JSON data
             $response = wp_remote_get($json_url, [
                'timeout' => 30,
                'sslverify' => false,
             ]);

             if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
             }

             $response_code = wp_remote_retrieve_response_code($response);
             if ($response_code !== 200) {
                throw new Exception(sprintf(__('API returned HTTP status: %d', 'syncwoo'), $response_code));
             }

             $body = wp_remote_retrieve_body($response);
             $data = json_decode($body, true);

             if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Invalid JSON response', 'syncwoo'));
             }

             // Save the file
             $local_file = $local_dir . 'products.json';
             $result = file_put_contents($local_file, $body);

             if ($result === false) {
                throw new Exception(__('Failed to save JSON file. Check permissions.', 'syncwoo'));
             }

             error_log('SyncWoo: File saved successfully. Bytes written: ' . $result);

             // Process data
             $processed = $this->process_json_data($data);

             // Update last sync time
             update_option('syncwoo_last_sync', current_time('mysql'));

             return [
                'success' => true,
                'message' => sprintf(__('Successfully synchronized %d products', 'syncwoo'), $processed['count']),
                'data' => $processed
             ];

          } catch (Exception $e) {
             error_log('SyncWoo Error: ' . $e->getMessage());
             return [
                'success' => false,
                'message' => __('Sync failed: ', 'syncwoo') . $e->getMessage()
             ];
          }
       }

        // Process JSON data (placeholder for your import logic)
        private function process_json_data($data) {
            // Implement your actual WooCommerce product import logic here
            // This is just a placeholder structure

            $count = 0;
            $errors = [];

            if (isset($data['products']) && is_array($data['products'])) {
                $count = count($data['products']);
                // Process each product
            }

            return [
                'count' => $count,
                'errors' => $errors
            ];
        }

        // Create secure directory with index files
        private function create_secure_directory() {
            $plugin_dir = plugin_dir_path(__FILE__);
            $upload_dir = wp_upload_dir()['basedir'] . '/syncwoo-json/';

            $directories = [$plugin_dir, $upload_dir];

            foreach ($directories as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index_file = $dir . 'index.php';
                if (@file_put_contents($index_file, "<?php\n// Silence is golden\n") === false) {
                    error_log('Failed to write index.php in ' . $dir);
                }

                $htaccess_file = $dir . '.htaccess';
                if (!file_exists($htaccess_file)) {
                    file_put_contents($htaccess_file, "Options -Indexes\nDeny from all");
                }
            }
        }

        // Settings field renderers
        public function render_section_header() {
            echo '<p>' . esc_html__('Configure your JSON product feed synchronization settings below.', 'syncwoo') . '</p>';
        }

        public function render_json_url_field() {
            $url = get_option('syncwoo_json_url');
            echo '<input type="url" name="syncwoo_json_url" value="' . esc_url($url) . '" class="regular-text" placeholder="https://example.com/products.json">';
            echo '<p class="description">' . esc_html__('Enter the full URL to your JSON product feed', 'syncwoo') . '</p>';
        }

       public function render_sync_frequency_field() {
          $frequency = get_option('syncwoo_sync_frequency', 'hourly');
          $schedules = wp_get_schedules();

          // Define all available intervals
          $available = [
             'every_1_minute',
             'every_5_minutes',
             'every_10_minutes',
             'every_15_minutes',
             'every_20_minutes',
             'every_30_minutes',
             'hourly',
             'twicedaily',
             'daily'
          ];

          echo '<select name="syncwoo_sync_frequency">';
          foreach ($available as $schedule) {
             if (isset($schedules[$schedule])) {
                echo '<option value="' . esc_attr($schedule) . '" ' . selected($frequency, $schedule, false) . '>';
                echo esc_html($schedules[$schedule]['display']);
                echo '</option>';
             }
          }
          echo '</select>';
       }
    }

// Initialize the plugin
    new sync_woo_json_importer();

    // Improved activation hook
    register_activation_hook(__FILE__, function() {
        $upload_dir = wp_upload_dir();
        $local_dir = trailingslashit($upload_dir['basedir']) . 'syncwoo-json/';
        
        if (!file_exists($local_dir)) {
            wp_mkdir_p($local_dir);
            file_put_contents($local_dir . 'index.php', "<?php\n// Silence is golden");
            file_put_contents($local_dir . '.htaccess', "Options -Indexes\nDeny from all");
        }
        
        // Force immediate cron schedule setup
        $importer = new sync_woo_json_importer();
        $importer->schedule_cron();
    });

    // Improved deactivation hook
    register_deactivation_hook(__FILE__, function() {
        wp_clear_scheduled_hook('syncwoo_scheduled_sync');
    });
}
?>