<?php
/*
 * Plugin Name: SyncWoo
 * Description: A starter plugin for WordPress.
 * Version: 1.0
 * Author: Avtandil Kakachishvili
 * Author URI:        https://kakachishvili.com/
 * Text Domain: SyncWoo
 */

defined( 'ABSPATH' ) || exit;


register_activation_hook(
    __FILE__,
    'sync_woo_activate'
);

function sync_woo_activate(){
    if ( headers_sent( $file, $line ) ) {
        error_log( "Headers already sent in $file on line $line" );
    }
}


function sync_woo_deactivate(){
//    When deactivate plugin
}

register_deactivation_hook(
    __FILE__,
    'sync_woo_deactivate'
);


if ( is_admin() ) {
    // we are in admin mode
    require_once __DIR__ . '/admin/admin-layout.php';
}

require_once __DIR__ . '/public/add-products.php';
