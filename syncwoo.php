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
        error_log('SyncWoo: Activating and scheduling cron job.');
        $importer->schedule_cron();
    } else {
        error_log('SyncWoo: sync_woo_json_importer class not found.');
    }

    // Clear all existing cron jobs
    clear_all_cron_jobs();
}


// Function to clear all scheduled cron jobs
function clear_all_cron_jobs() {
    $crons = _get_cron_array();
    if ($crons) {
        foreach ($crons as $timestamp => $cron) {
            foreach ($cron as $hook => $d) {
                wp_clear_scheduled_hook($hook);
                error_log('SyncWoo: Cleared cron hook: ' . $hook);
            }
        }
    }
}


function sync_woo_deactivate() {

    // Clear the specific scheduled cron job
    wp_clear_scheduled_hook('syncwoo_scheduled_sync');

    // Optionally clear all cron jobs related to the plugin
    clear_all_cron_jobs();

    // Log the deactivation process
    error_log('SyncWoo: Plugin deactivated and cron jobs cleared.');
}