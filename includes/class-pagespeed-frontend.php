<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PageSpeed_Frontend {
    private $option_name = 'ps_scanner_options';
    private $scanner;

    public function __construct() {
        $this->scanner = new PageSpeed_Scanner();

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'pagespeed_scanner', [ $this, 'shortcode_handler' ] );

        // AJAX endpoints (both logged-in and not)
        add_action( 'wp_ajax_ps_scanner_start', [ $this, 'ajax_start' ] );
        add_action( 'wp_ajax_nopriv_ps_scanner_start', [ $this, 'ajax_start' ] );

        add_action( 'wp_ajax_ps_scanner_process', [ $this, 'ajax_process_batch' ] );
        add_action( 'wp_ajax_nopriv_ps_scanner_process', [ $this, 'ajax_process_batch' ] );

        add_action( 'wp_ajax_ps_scanner_export', [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_nopriv_ps_scanner_export', [ $this, 'ajax_export' ] );
    }

    public function get_options() {
        $opts = get_option( $this->option_name, [] );
        return wp_parse_args( $opts, [
            'api_key' => '',
            'max_pages' => 25,
            'cta_text' => 'Want to improve your scores? Contact us',
            'cta_url' => ''
        ] );
    }

    public function enqueue_assets() {
        wp_register_style( 'ps-scanner-frontend', PS_SCANNER_URL . 'assets/css/frontend.css', [], PS_SCANNER_VERSION );
        wp_register_script( 'ps-scanner-frontend', PS_SCANNER_URL . 'assets/js/frontend.js', [ 'jquery' ], PS_SCANNER_VERSION, true );

        wp_localize_script( 'ps-scanner-frontend', 'ps_scanner_vars', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ps_scanner_nonce' ),
            'max_pages'=> intval( $this->get_options()['max_pages'] ),
        ] );

        wp_enqueue_style( 'ps-scanner-frontend' );
        wp_enqueue_script( 'ps-scanner-frontend' );
    }

    public function shortcode_handler( $atts = [] ) {
        $opts = $this->get_options();

        ob_start();
        ?>
        <div class="ps-scanner-wrap">
            <form id="ps-scanner-form" class="ps-scanner-form">
                <div class="ps-input-group">
                    <h2>Batch Page Speed Test</h2>
                </div>
                <div class="ps-input-group">
                    <input type="text" id="ps-domain" name="domain" placeholder="https://example.com" required />
                    <label for="ps-strategy" style="margin-left:10px;">Scan Type:</label>
                    <select id="ps-strategy" name="strategy">
                        <option value="mobile">Mobile</option>
                        <option value="desktop">Desktop</option>
                    </select>
                </div>
                <div class="ps-input-group">
                    <small>Enter the domain of the website you want to scan (e.g., https://example.com).</small>
                </div>
                <div class="ps-input-group">
                    <button id="scan-button" type="submit" class="ps-btn"><i class="material-icons">speed</i> Run Scan</button>
                </div>
            </form>

            <div id="ps-results" style="margin-top:1rem;">
                <!-- Results table will be injected here by ps-scanner.js -->
            </div>
            
            <div id="ps-spinner-wrap" style="display:none; text-align:center; margin-bottom:8px;">
                <img id="ps-spinner" src="<?php echo esc_url( PS_SCANNER_URL . 'assets/images/spinner.gif' ); ?>" alt="<?php esc_attr_e( 'Loading', 'ps-scanner' ); ?>" width="36" height="36" />
            </div>

            <div id="ps-progress" style="display:none;">
                <div class="ps-progress-bar"><div class="ps-progress-fill" style="width:0%"></div></div>
                <div id="ps-progress-text"></div>
            </div>

            <div id="ps-confirm-clear" style="display:none; margin-top:12px;">
                <strong><button id="ps-clear-results" class="ps-btn"><i class="material-icons">clear_all</i>Clear Results</button> *Starting a new scan will clear previous results. </strong>
            </div>

            <div id="ps-results" style="margin-top:1rem;">
                <table id="ps-results-table" class="ps-results-table widefat striped">
                    <thead>
                        <tr>
                            <th>URL</th>
                            <th>Strategy</th>
                            <th>Performance</th>
                            <th>SEO</th>
                            <th>Accessibility</th>
                            <th>Best Practices</th>
                            <th>FCP</th>
                            <th>LCP</th>
                            <th>CLS</th>
                            <th>TBT</th>
                            <th>SI</th>
                            <th>TTI</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>

                <div style="margin-top:1rem;">
                    <button id="ps-download-csv" class="ps-btn" disabled>Download CSV</button>
                </div>
                <div id="ps-cta" style="margin-top:1.5rem; text-align:center;">
                    <a class="button" href="<?php echo esc_url( $opts['cta_url'] ); ?>" style="margin-left:12px;"><?php echo esc_html( $opts['cta_text'] ); ?></a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // AJAX: start scan -> returns list of URLs (up to limit)
    public function ajax_start() {
        check_ajax_referer( 'ps_scanner_nonce', 'nonce' );
        $domain = isset( $_POST['domain'] ) ? esc_url_raw( trim( wp_unslash( $_POST['domain'] ) ) ) : '';
        if ( empty( $domain ) ) {
            wp_send_json_error( [ 'message' => 'Domain is required.' ] );
        }

        // Normalize domain
        $domain = $this->scanner->normalize_domain( $domain );

        // Get URLs (sitemap or crawl). Returns array of URLs.
        $opts = $this->get_options();
        $max = intval( $opts['max_pages'] );

        $urls = $this->scanner->get_urls( $domain, $max );

        if ( empty( $urls ) ) {
            wp_send_json_error( [ 'message' => 'No pages found on the provided domain.' ] );
        }

        // Return URLs to frontend. Frontend will process in batches.
        wp_send_json_success( [ 'urls' => array_values( $urls ) ] );
    }

    // AJAX: process a batch of URLs (frontend calls repeatedly)
    public function ajax_process_batch() {
        check_ajax_referer( 'ps_scanner_nonce', 'nonce' );
        $urls = isset( $_POST['urls'] ) ? (array) $_POST['urls'] : [];
        if ( empty( $urls ) ) {
            wp_send_json_error( [ 'message' => 'No URLs in batch.' ] );
        }

        // Strategy default to mobile; frontend may send 'strategy'
        $strategy = isset( $_POST['strategy'] ) ? sanitize_text_field( $_POST['strategy'] ) : 'mobile';
        $opts = $this->get_options();
        $api_key = $opts['api_key'];

        $results = [];
        foreach ( $urls as $u ) {
            $u = esc_url_raw( $u );
            // call scanner to get pagespeed results for one URL
            $r = $this->scanner->get_pagespeed( $u, $strategy, $api_key );
            // r should be array with expected fields or an error
            $results[] = [
                'url' => $u,
                'result' => $r
            ];
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    // AJAX: export results array to CSV
    public function ajax_export() {
        check_ajax_referer( 'ps_scanner_nonce', 'nonce' );
        $data = isset( $_POST['data'] ) ? $_POST['data'] : [];
        if ( empty( $data ) || ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'No result data provided for export.' ] );
        }

        $exporter = new PageSpeed_Export();
        $file = $exporter->export_to_csv( $data );

        if ( is_wp_error( $file ) ) {
            wp_send_json_error( [ 'message' => $file->get_error_message() ] );
        }

        wp_send_json_success( [ 'file_url' => $file ] );
    }
}
