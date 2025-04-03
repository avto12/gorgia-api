<?php
/*
 * Plugin Name: SyncWoo
 * Description: A starter plugin for WordPress.
 * Version: 1.0
 * Author: Avtandil Kakachishvili
 * Author URI: https://kakachishvili.com/
 * Text Domain: syncwoo
 */

defined('ABSPATH') || exit;

// Load plugin files
require_once __DIR__ . '/admin/admin-layout.php';
require_once __DIR__ . '/public/add-products.php';

register_activation_hook(__FILE__, 'sync_woo_activate');
register_deactivation_hook(__FILE__, 'sync_woo_deactivate');

function sync_woo_activate() {
    // Create directory immediately on activation
    $upload_dir = wp_upload_dir();
    $local_dir = $upload_dir['basedir'] . '/syncwoo-json/';
    
    if (!file_exists($local_dir)) {
        wp_mkdir_p($local_dir);
    }
    
    // Create security files
    $index_file = $local_dir . 'index.php';
    $htaccess_file = $local_dir . '.htaccess';
    
    if (!file_exists($index_file)) {
        file_put_contents($index_file, "<?php\n// Silence is golden");
    }
    
    if (!file_exists($htaccess_file)) {
        file_put_contents($htaccess_file, "Options -Indexes\nDeny from all");
    }
    
    // Schedule the initial sync
    if (class_exists('sync_woo_json_importer')) {
        $importer = new sync_woo_json_importer();
        $importer->schedule_cron();
    }
}

function sync_woo_deactivate() {
    wp_clear_scheduled_hook('syncwoo_scheduled_sync');
}