<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Export results to CSV and return a public file URL.
 */
class PageSpeed_Export {

    
    
    /**
     * Clean up PSI metric values (fix encoding issues like Â, non-breaking spaces, etc.)
     */
    public function clean_metric_value( $value ) {
        // Replace non-breaking space (U+00A0) with a normal space
        $value = str_replace("\u{00A0}", ' ', $value);
        // Also handle HTML entities just in case
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($value);
    }

    public function export_to_csv( $results ) {
        if ( ! is_array( $results ) || empty( $results ) ) {
            return new WP_Error( 'no_data', 'No results to export.' );
        }

        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'pagespeed-scanner';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $filename = 'pagespeed-export-' . date( 'Ymd-His' ) . '.csv';
        $filepath = trailingslashit( $dir ) . $filename;

        $fh = fopen( $filepath, 'w' );
        if ( $fh === false ) {
            return new WP_Error( 'file_write', 'Unable to create export file.' );
        }

        // Header
        $header = [ 
            'URL', 
            'Strategy',
            'Performance', 
            'SEO',
            'Accessibility',
            'Best Practices',
            'FCP', 
            'LCP', 
            'CLS', 
            'TBT', 
            'SI', 
            'TTI',
            'raw_result' 
        ];
        fputcsv( $fh, $header );

            foreach ( $results as $r ) {
                $url = $r['url'] ?? '';
                $res = $r['result'] ?? [];
                $strategy = $r['strategy'] ?? 'mobile';

                // Skip PageSpeed API errors
                if ( isset($res['errors']['pagespeed_error']) ) {
                    continue;
                }

                if ( is_wp_error( $res ) ) {
                    $row = [ $url, $strategy, 'error', '', '', '', '', '', '', $res->get_error_message() ];
                } else {
                    $row = [
                        $url,
                        $strategy,
                        $res['score'] ?? '',
                        $res['SEO'] ?? '',
                        $res['Accessibility'] ?? '',
                        $res['BestPractices'] ?? '',
                        $res['FCP'] ?? '',
                        $res['LCP'] ?? '',
                        $res['CLS'] ?? '',
                        $res['TBT'] ?? '',
                        $res['SI'] ?? '',
                        $res['TTI'] ?? '',
                        json_encode( $res )
                    ];
                }

                fputcsv( $fh, $row );
            }


        fclose( $fh );

        // Return file URL
        $file_url = trailingslashit( $upload['baseurl'] ) . 'pagespeed-scanner/' . $filename;
        return esc_url_raw( $file_url );
    }
}
