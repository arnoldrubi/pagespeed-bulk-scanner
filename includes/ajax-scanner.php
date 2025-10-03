<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX handler for running a PageSpeed scan.
 */
function ps_run_scan_ajax() {
    // Nonce check for security
    check_ajax_referer( 'ps_scanner_nonce', 'nonce' );

    // Sanitize inputs
    $domain   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
    $strategy = isset( $_POST['strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['strategy'] ) ) : 'mobile';

    if ( empty( $domain ) ) {
        wp_send_json_error( [ 'message' => 'No domain provided.' ], 400 );
    }

    // Load scanner class
    if ( ! class_exists( 'PageSpeed_Scanner' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'class-pagespeed-scanner.php';
    }

    $scanner = new PageSpeed_Scanner();
    $origin  = $scanner->normalize_domain( $domain );

    // Get up to 25 URLs
    $urls    = $scanner->get_urls( $origin, 25 );
    $results = [];

    if ( empty( $urls ) ) {
        wp_send_json_error( [ 'message' => 'No valid URLs found for this domain.' ], 404 );
    }

    foreach ( $urls as $url ) {
        $res = $scanner->get_pagespeed( $url, $strategy );

        if ( is_wp_error( $res ) ) {
            $results[] = [
                'url'      => $url,
                'strategy' => $strategy,
                'error'    => $res->get_error_message(),
            ];
        } else {
            $results[] = [
                'url'      => $url,
                'strategy' => $strategy,
                'metrics'  => $res,
            ];
        }
    }

    wp_send_json_success( $results );
}
add_action( 'wp_ajax_ps_run_scan', 'ps_run_scan_ajax' );
add_action( 'wp_ajax_nopriv_ps_run_scan', 'ps_run_scan_ajax' );
