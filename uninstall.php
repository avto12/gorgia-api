<?php
register_uninstall_hook(
    __FILE__,
    'sync_woo_uninstall'
);

function sync_woo_uninstall() {
    // Delete plugin options
    delete_option('syncwoo_json_url');
    delete_option('syncwoo_sync_frequency');
    delete_option('syncwoo_last_sync');

    // Remove uploaded files and directories
    $upload_dir = wp_upload_dir();
    $local_dir = trailingslashit($upload_dir['basedir']) . 'syncwoo-json/';

    if (file_exists($local_dir)) {
        // Delete all files in the directory
        $files = glob($local_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Remove the directory itself
        rmdir($local_dir);
    }

    // Clear all scheduled cron jobs
    $hook_name = 'syncwoo_scheduled_sync';
    while (wp_next_scheduled($hook_name)) {
        wp_clear_scheduled_hook($hook_name);
    }

    error_log('SyncWoo: All plugin data and cron jobs have been cleaned up.');
}