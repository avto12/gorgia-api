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
            // Initialize plugin
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_syncwoo_manual_sync', [$this, 'handle_manual_sync']);
            add_action('syncwoo_scheduled_sync', [$this, 'perform_sync']);

            // Create secure directory
            $this->create_secure_directory();
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

            if (empty($json_url)) {
                return [
                    'success' => false,
                    'message' => __('No JSON URL configured', 'syncwoo')
                ];
            }

            try {
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

                // Save the JSON data locally (overwrite existing file)
                $upload_dir = wp_upload_dir();
                $local_dir = $upload_dir['basedir'] . '/syncwoo-json/';
                $local_file = $local_dir . 'products.json'; // Always overwrite this file

                if (!file_exists($local_dir)) {
                    wp_mkdir_p($local_dir);
                }

                if (file_put_contents($local_file, $body) === false) {
                    throw new Exception(__('Failed to save JSON file locally', 'syncwoo'));
                }

                // Process the data (WooCommerce import logic here)
                $processed = $this->process_json_data($data);

                // Update last sync time
                update_option('syncwoo_last_sync', current_time('mysql'));

                return [
                    'success' => true,
                    'message' => sprintf(__('Successfully synchronized %d products', 'syncwoo'), $processed['count']),
                    'data' => $processed
                ];

            } catch (Exception $e) {
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
            $available = ['hourly', 'twicedaily', 'daily'];

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

// Activation/deactivation hooks
    register_activation_hook(__FILE__, function() {
        // Schedule initial sync event
        $frequency = get_option('sync_woo_sync_frequency', 'hourly');

        if (!in_array($frequency, ['hourly', 'twicedaily', 'daily'])) {
            wp_schedule_event(time(), get_option('sync_woo_sync_frequency', 'hourly'), 'syncwoo_scheduled_sync');
        }

        if (!wp_next_scheduled('sync_woo_sync_frequency')) {
            wp_schedule_event(time(), $frequency, 'sync_woo_sync_frequency');
        }
    });

    register_deactivation_hook(__FILE__, function() {
        // Clear scheduled sync
        wp_clear_scheduled_hook('sync_woo_sync_frequency');
    });
}
?>