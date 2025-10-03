<?php
/*
Plugin Name: PageSpeed Bulk Scanner
Description: Scan up to 25 pages of a website using Google PageSpeed API and export results as CSV. Includes CTA for lead capture.
Version: 0.1
Author: Arnold Rubi
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PS_SCANNER_PATH', plugin_dir_path( __FILE__ ) );
define( 'PS_SCANNER_URL',  plugin_dir_url( __FILE__ ) );
define( 'PS_SCANNER_VERSION', '0.1' );

require_once PS_SCANNER_PATH . 'includes/class-pagespeed-admin.php';
require_once PS_SCANNER_PATH . 'includes/class-pagespeed-frontend.php';
require_once PS_SCANNER_PATH . 'includes/class-pagespeed-scanner.php';
require_once PS_SCANNER_PATH . 'includes/class-pagespeed-export.php';

function ps_scanner_init() {
    // instantiate classes
    new PageSpeed_Admin();
    new PageSpeed_Frontend();
}
add_action( 'plugins_loaded', 'ps_scanner_init' );


// Activation hook: create uploads directory for exports
function ps_scanner_activate() {
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit( $upload_dir['basedir'] ) . 'pagespeed-scanner';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
}
register_activation_hook( __FILE__, 'ps_scanner_activate' );

// Conditionally load Material Icons only if shortcode is present
function ps_enqueue_material_icons() {
    global $post;

    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'pagespeed_scanner' ) ) {
        wp_enqueue_style(
            'material-icons',
            'https://fonts.googleapis.com/icon?family=Material+Icons',
            [],
            null
        );
    }
}
add_action( 'wp_enqueue_scripts', 'ps_enqueue_material_icons' );
