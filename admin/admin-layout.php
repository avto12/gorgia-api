<?php
defined('ABSPATH') || exit;

function syncwoo_enqueue_style() {
   // Enqueue your css file
   wp_enqueue_style('layout-css', plugin_dir_url(__FILE__) . 'css/layout.css', array(), time(), 'all');
}
add_action('admin_enqueue_scripts', 'syncwoo_enqueue_style');

if (!class_exists('sync_woo_json_importer')) {
    class sync_woo_json_importer {
        public function __construct() {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_syncwoo_manual_sync', [$this, 'handle_manual_sync']);
            add_action('syncwoo_scheduled_sync', [$this, 'perform_sync']);
            add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        }

        public function add_cron_schedules($schedules) {
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
        }

        public function schedule_cron() {
            $frequency = get_option('syncwoo_sync_frequency', 'hourly');
            error_log('SyncWoo: Scheduling JSON sync cron job with frequency: ' . $frequency);

            while ($timestamp = wp_next_scheduled('syncwoo_scheduled_sync')) {
                error_log('SyncWoo: Clearing existing JSON sync scheduled hook at timestamp: ' . $timestamp);
                wp_unschedule_event($timestamp, 'syncwoo_scheduled_sync');
            }

            wp_schedule_event(time(), $frequency, 'syncwoo_scheduled_sync');
            error_log('SyncWoo: JSON sync cron job scheduled with frequency: ' . $frequency);
        }

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

            add_submenu_page(
                'syncwoo',
                __('Product Sync', 'syncwoo'),
                __('Product Sync', 'syncwoo'),
                'manage_options',
                'syncwoo-product-sync',
                [$this, 'render_product_sync_page']
            );
        }

        public function register_settings() {
            register_setting('syncwoo_settings', 'syncwoo_json_url_0', [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => ''
            ]);

            register_setting('syncwoo_settings', 'syncwoo_json_url_1', [
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
                'syncwoo_json_url_0',
                __('JSON Feed URL 0', 'syncwoo'),
                [$this, 'render_json_url_field_0'],
                'syncwoo',
                'syncwoo_main_section'
            );

            add_settings_field(
                'syncwoo_json_url_1',
                __('JSON Feed URL 1', 'syncwoo'),
                [$this, 'render_json_url_field_1'],
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

        public function render_settings_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'syncwoo'));
            }

            ?>
            <div class="wrap">
                <h1><?php esc_html_e('SyncWoo JSON Settings', 'syncwoo'); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('syncwoo_settings');
                    do_settings_sections('syncwoo');
                    submit_button(__('Save Settings', 'syncwoo'));
                    ?>
                </form>

                <div class="syncwoo-actions">
                    <h2><?php esc_html_e('Manual Synchronization', 'syncwoo'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="syncwoo-sync-form">
                        <input type="hidden" name="action" value="syncwoo_manual_sync">
                        <?php wp_nonce_field('syncwoo_manual_sync_action', 'syncwoo_manual_sync_nonce'); ?>
                        <p>
                            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Run Sync Now', 'syncwoo'); ?>">
                            <span class="description">
                                <?php
                                esc_html_e('Last JSON sync: ', 'syncwoo');
                                echo get_option('syncwoo_last_sync') ? esc_html(get_option('syncwoo_last_sync')) : __('Never', 'syncwoo');
                                ?>
                            </span>
                        </p>
                    </form>
                </div>
            </div>
            <?php
        }

        public function render_product_sync_page() {
            do_action('syncwoo_render_product_sync_page');
        }

        public function handle_manual_sync() {
            if (!current_user_can('manage_options') ||
                !isset($_POST['syncwoo_manual_sync_nonce']) ||
                !wp_verify_nonce($_POST['syncwoo_manual_sync_nonce'], 'syncwoo_manual_sync_action')) {
                wp_die(__('Invalid request', 'syncwoo'));
            }

            $result = $this->perform_sync();

            if ($result['success']) {
                update_option('syncwoo_last_sync', current_time('mysql'));
                add_settings_error('syncwoo_messages', 'syncwoo_message', $result['message'], 'updated');
            } else {
                add_settings_error('syncwoo_messages', 'syncwoo_message', $result['message'], 'error');
            }

            set_transient('settings_errors', get_settings_errors(), 30);
            wp_safe_redirect(admin_url('admin.php?page=syncwoo'));
            exit;
        }

        public function perform_sync() {
            $json_urls = [
                'product_0' => get_option('syncwoo_json_url_0'),
                'product_1' => get_option('syncwoo_json_url_1')
            ];

            error_log('SyncWoo: Starting JSON sync process for URLs: ' . print_r($json_urls, true));

            if (empty($json_urls['product_0']) && empty($json_urls['product_1'])) {
                error_log('SyncWoo: No JSON URL configured');
                return [
                    'success' => false,
                    'message' => __('No JSON URL configured', 'syncwoo')
                ];
            }

            try {
                $upload_dir = wp_upload_dir();
                $local_dir = $upload_dir['basedir'] . '/syncwoo-json/';

                if (!file_exists($local_dir)) {
                    if (!wp_mkdir_p($local_dir)) {
                        throw new Exception(__('Failed to create directory', 'syncwoo'));
                    }
                }

                $data = [];
                $errors = [];
                foreach ($json_urls as $key => $json_url) {
                    if (empty($json_url)) {
                        error_log('SyncWoo: No URL configured for ' . $key);
                        continue;
                    }

                    if (!filter_var($json_url, FILTER_VALIDATE_URL)) {
                        error_log('SyncWoo: Invalid URL provided for ' . $key);
                        $errors[] = sprintf(__('Invalid URL provided for %s', 'syncwoo'), $key);
                        continue;
                    }

                    $response = wp_remote_get($json_url, [
                        'timeout' => 30,
                        'sslverify' => false,
                    ]);

                    if (is_wp_error($response)) {
                        $errors[] = sprintf(__('Failed to fetch data from %s: %s', 'syncwoo'), $json_url, $response->get_error_message());
                        error_log('SyncWoo Error: Failed to fetch data from ' . $json_url . '. Error: ' . $response->get_error_message());
                        continue;
                    }

                    $response_code = wp_remote_retrieve_response_code($response);
                    if ($response_code !== 200) {
                        throw new Exception(sprintf(__('API returned HTTP status: %d', 'syncwoo'), $response_code));
                    }

                    $body = wp_remote_retrieve_body($response);
                    $json_data = json_decode($body, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception(__('Invalid JSON response', 'syncwoo'));
                    }

                    $data = array_merge($data, $json_data);

                    $local_file = $local_dir . $key . '.json';
                    $result = file_put_contents($local_file, json_encode($json_data, JSON_PRETTY_PRINT));

                    if ($result === false) {
                        throw new Exception(__('Failed to save JSON file. Check permissions.', 'syncwoo'));
                    }

                    error_log('SyncWoo: File saved successfully for URL ' . $json_url . '. Bytes written: ' . $result);
                }

                update_option('syncwoo_last_sync', current_time('mysql'));

                return [
                    'success' => true,
                    'message' => __('JSON files synchronized successfully', 'syncwoo'),
                    'data' => $data,
                    'errors' => $errors
                ];
            } catch (Exception $e) {
                error_log('SyncWoo Error: ' . $e->getMessage());
                return [
                    'success' => false,
                    'message' => __('JSON sync failed: ', 'syncwoo') . $e->getMessage(),
                    'errors' => $errors
                ];
            }
        }

        public function render_section_header() {
            echo '<p>' . esc_html__('Configure your JSON product feed synchronization settings below.', 'syncwoo') . '</p>';
        }

        public function render_json_url_field_0() {
            $url = get_option('syncwoo_json_url_0');
            echo '<input type="url" name="syncwoo_json_url_0" value="' . esc_url($url) . '" class="regular-text" placeholder="https://example.com/products_0.json">';
            echo '<p class="description">' . esc_html__('Enter the full Gorgia.ge URL to your JSON product feed', 'syncwoo') . '</p>';
        }

        public function render_json_url_field_1() {
            $url = get_option('syncwoo_json_url_1');
            echo '<input type="url" name="syncwoo_json_url_1" value="' . esc_url($url) . '" class="regular-text" placeholder="https://example.com/products_1.json">';
        }

        public function render_sync_frequency_field() {
            $frequency = get_option('syncwoo_sync_frequency', 'hourly');
            $schedules = wp_get_schedules();

            $available = [
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

            echo '<select name="syncwoo_sync_frequency">';
            foreach ($available as $schedule) {
                if (isset($schedules[$schedule])) {
                    echo '<option value="' . esc_attr($schedule) . '" ' . selected($frequency, $schedule, false) . '>';
                    echo esc_html($schedules[$schedule]['display']);
                    echo '</option>';
                }
            }
            echo '</select>';
            ?>
            <div class="description">
                <?php
                echo '<span class="last-sync">' . esc_html__('Last JSON sync: ', 'syncwoo') . '</span>';
                ?>
                <span class="last-sync"><?php
                echo get_option('syncwoo_last_sync') ? esc_html(get_option('syncwoo_last_sync')) : __('Never', 'syncwoo');
                ?></span>
                <hr>
                <span class="sync-interval">
                    <?php
                    $frequency = get_option('syncwoo_sync_frequency', 'hourly');
                    $schedules = wp_get_schedules();

                    $time_remaining = 0;
                    if (isset($schedules[$frequency])) {
                        $next_sync_timestamp = wp_next_scheduled('syncwoo_scheduled_sync');
                        $current_time = time();

                        if ($next_sync_timestamp) {
                            $time_remaining = $next_sync_timestamp - $current_time;
                        }
                    }
                    ?>
                    <div id="countdown-timer" data-remaining="<?php echo esc_attr($time_remaining); ?>">
                        <span><?php esc_html_e('Time until next JSON sync: ', 'syncwoo'); ?></span>
                        <span id="time-remaining"></span>
                    </div>
                </span>
                <hr>
                <span class="first-sync">
                    <?php esc_html_e('Note: When active plugin. First, click the "Run Sync Now" button below and then select the interval above.', 'syncwoo'); ?>
                </span>
                <hr>
                <?php
                echo '<br>' . esc_html__('Note: The frequency of the JSON sync may be affected by your server settings.', 'syncwoo');
                echo '<br>' . esc_html__('For example, if your server has a limit of 1 request per minute, the sync will not run more frequently than that.', 'syncwoo');
                echo '<hr>' . esc_html__('If you want to run the JSON sync manually, click the "Run Sync Now" button below.', 'syncwoo');
                echo '<br>' . esc_html__('Happy syncing!', 'syncwoo');
                ?>
            </div>
            <?php
        }
    }

    new sync_woo_json_importer();

    register_activation_hook(__FILE__, function() {
        $upload_dir = wp_upload_dir();
        $local_dir = trailingslashit($upload_dir['basedir']) . 'syncwoo-json/';

        if (!file_exists($local_dir)) {
            wp_mkdir_p($local_dir);
            file_put_contents($local_dir . 'index.php', "<?php\n// Silence is golden");
            file_put_contents($local_dir . '.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php)$\">\n    Deny from all\n</FilesMatch>\n<FilesMatch \"\\.(css|js)$\">\n    Allow from all\n</FilesMatch>");
        }

        $importer = new sync_woo_json_importer();
        $importer->schedule_cron();
    });

    register_deactivation_hook(__FILE__, function() {
        wp_clear_scheduled_hook('syncwoo_scheduled_sync');
    });

    add_action('update_option_syncwoo_sync_frequency', function($old_value, $new_value) {
        if ($old_value !== $new_value) {
            error_log('SyncWoo: JSON sync frequency updated from ' . $old_value . ' to ' . $new_value);
            $importer = new sync_woo_json_importer();
            $importer->schedule_cron();
        }
    }, 10, 2);

    add_action('admin_notices', function () {
        if (isset($_GET['sync']) && $_GET['sync'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync completed successfully!', 'syncwoo') . '</p></div>';
        }
    });
}


?>