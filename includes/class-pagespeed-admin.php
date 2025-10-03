<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PageSpeed_Admin {
    private $option_name = 'ps_scanner_options';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        
        // ✅ Add AJAX hook
        add_action( 'wp_ajax_ps_validate_api_key', [ $this, 'ajax_validate_api_key' ] );
    }

    public function register_menu() {
        add_options_page(
            'PageSpeed Scanner',
            'PageSpeed Scanner',
            'manage_options',
            'ps-scanner',
            [ $this, 'settings_page' ]
        );
        // Hook to enqueue admin scripts
        add_action( 'admin_enqueue_scripts', function( $hook ) {
            if ( $hook === 'settings_page_ps-scanner' ) {
                wp_enqueue_script(
                    'ps-admin',
                    PS_SCANNER_URL . 'assets/js/admin.js',
                    [ 'jquery' ],
                    '1.0',
                    true
                );
                wp_localize_script( 'ps-admin', 'PSAdmin', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'ps_admin_nonce' ),
                ] );
            }
        });
    }

    public function register_settings() {
        register_setting( 'ps_scanner_group', $this->option_name, [ $this, 'sanitize' ] );

        add_settings_section(
            'ps_scanner_main',
            'API & Limits',
            function(){ echo '<p>Configure PageSpeed API key and limits.</p>'; },
            'ps-scanner'
        );

        add_settings_field(
            'api_key',
            'Google PageSpeed API Key<br><small>You can get an API key from <a href="https://console.developers.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.</small>',
            [ $this, 'field_api_key' ],
            'ps-scanner',
            'ps_scanner_main'
        );

        add_settings_field(
            'max_pages',
            'Max pages per scan',
            [ $this, 'field_max_pages' ],
            'ps-scanner',
            'ps_scanner_main'
        );

        add_settings_field(
            'cta_text',
            'CTA Button Text',
            [ $this, 'field_cta_text' ],
            'ps-scanner',
            'ps_scanner_main'
        );

        add_settings_field(
            'cta_url',
            'CTA URL',
            [ $this, 'field_cta_url' ],
            'ps-scanner',
            'ps_scanner_main'
        );
    }

    public function sanitize( $input ) {
        $out = [];
        $out['api_key'] = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
        $out['max_pages'] = isset( $input['max_pages'] ) ? intval( $input['max_pages'] ) : 25;
        if ( $out['max_pages'] <= 0 ) $out['max_pages'] = 25;
        $out['cta_text'] = isset( $input['cta_text'] ) ? sanitize_text_field( $input['cta_text'] ) : 'Want to improve your scores? Contact us';
        $out['cta_url'] = isset( $input['cta_url'] ) ? esc_url_raw( $input['cta_url'] ) : '';
        return $out;
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

    public function field_api_key() {
        $opts = $this->get_options();
        printf(
            '<input type="text" name="%1$s[api_key]" value="%2$s" style="width:60%%" />',
            esc_attr( $this->option_name ),
            esc_attr( $opts['api_key'] )
        );
    }

    public function field_max_pages() {
        $opts = $this->get_options();
        printf(
            '<input type="number" name="%1$s[max_pages]" value="%2$s" min="1" max="100" /> <em>(Default 25)</em>',
            esc_attr( $this->option_name ),
            esc_attr( $opts['max_pages'] )
        );
    }

    public function field_cta_text() {
        $opts = $this->get_options();
        printf(
            '<input type="text" name="%1$s[cta_text]" value="%2$s" style="width:60%%" />',
            esc_attr( $this->option_name ),
            esc_attr( $opts['cta_text'] )
        );
    }

    public function field_cta_url() {
        $opts = $this->get_options();
        printf(
            '<input type="url" name="%1$s[cta_url]" value="%2$s" style="width:60%%" placeholder="https://your-agency.com/contact" />',
            esc_attr( $this->option_name ),
            esc_attr( $opts['cta_url'] )
        );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>PageSpeed Bulk Scanner</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'ps_scanner_group' );
                do_settings_sections( 'ps-scanner' );
                submit_button();
                ?>
            </form>
            <h2>Shortcode</h2>
            <p>Use <code>[pagespeed_scanner]</code> in any page or post to render the scanner form.</p>
        </div>
        <?php
    }
    public function ajax_validate_api_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'API key is empty' ] );
        }

        $test_url = add_query_arg( [
            'url'      => 'https://example.com',
            'strategy' => 'mobile',
            'category' => 'performance',
            'key'      => $api_key,
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );

        $resp = wp_remote_get( $test_url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 || isset( $body['error'] ) ) {
            $msg = $body['error']['message'] ?? 'Invalid API key';
            wp_send_json_error( [ 'message' => $msg ] );
        }

        wp_send_json_success( [ 'message' => 'API key is valid ✅' ] );
    }
}
