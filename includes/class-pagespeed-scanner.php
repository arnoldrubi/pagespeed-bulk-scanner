<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core scanner class.
 * NOTE: This file contains basic helpers and stubs for the real logic.
 * We'll expand the sitemap parser, crawler, and PageSpeed API parsing next.
 */
class PageSpeed_Scanner {

    /**
     * Normalize incoming domain/url to origin (scheme + host)
     * e.g. https://example.com/page -> https://example.com
     */
    public function normalize_domain( $url ) {
        $url = trim( $url );
        if ( strpos( $url, 'http' ) !== 0 ) {
            $url = 'https://' . $url;
        }
        $parts = parse_url( $url );
        if ( ! isset( $parts['scheme'] ) || ! isset( $parts['host'] ) ) {
            return rtrim( $url, '/' );
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if ( isset( $parts['port'] ) ) {
            $origin .= ':' . $parts['port'];
        }
        return rtrim( $origin, '/' );
    }

    /**
     * Get urls up to $limit from sitemap or crawl homepage
     * Returns array of absolute URLs (unique)
     */
    public function get_urls( $domain_origin, $limit = 25 ) {
        // Try sitemap first
        $sitemap_url = $domain_origin . '/sitemap.xml';
        $urls = [];

        $resp = wp_remote_get( $sitemap_url, [ 'timeout' => 30 ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $body = wp_remote_retrieve_body( $resp );
            if ( ! empty( $body ) ) {
                // parse sitemap (very permissive)
                try {
                    $xml = simplexml_load_string( $body );
                    if ( $xml !== false ) {
                        foreach ( $xml->url as $u ) {
                            $loc = (string) $u->loc;
                            if ( ! empty( $loc ) ) {
                                // ✅ apply non-HTML filter
                                $path = parse_url($loc, PHP_URL_PATH);
                                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                                if ($ext && !in_array($ext, ['html', 'htm', 'php'])) {
                                    continue; // skip images, css, js, pdf, etc.
                                }

                                $urls[] = $loc;
                                if ( count( $urls ) >= $limit ) break;
                            }
                        }
                    } elseif ( isset( $xml->sitemap ) ) {
                        // fallback: sitemap index - naive parse (TODO: expand)
                        foreach ( $xml->sitemap as $s ) {
                            $loc = (string) $s->loc;
                            if ( ! empty( $loc ) ) {
                                $sub = wp_remote_get( $loc, [ 'timeout' => 30 ] );
                                if ( ! is_wp_error( $sub ) && wp_remote_retrieve_response_code( $sub ) === 200 ) {
                                    $b2 = wp_remote_retrieve_body( $sub );
                                    $xml2 = simplexml_load_string( $b2 );
                                    if ( $xml2 !== false ) {
                                        foreach ( $xml2->url as $u2 ) {
                                            $loc2 = (string) $u2->loc;
                                            if ( $loc2 ) {
                                                // ✅ apply non-HTML filter
                                                $path = parse_url($loc2, PHP_URL_PATH);
                                                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                                                if ($ext && !in_array($ext, ['html', 'htm', 'php'])) {
                                                    continue;
                                                }

                                                $urls[] = $loc2;
                                                if ( count( $urls ) >= $limit ) break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch ( Exception $e ) {
                    // ignore parsing error -> fallback to crawl
                }
            }
        }

        // If no urls found, do a simple crawl of homepage (very basic)
        if ( empty( $urls ) ) {
            $resp = wp_remote_get( $domain_origin, [ 'timeout' => 30 ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $body = wp_remote_retrieve_body( $resp );
                // find hrefs - simple regex (not perfect)
                preg_match_all( '/href=[\'"]?([^\'" >]+)/i', $body, $matches );
                if ( ! empty( $matches[1] ) ) {
                    foreach ( $matches[1] as $href ) {
                        // skip anchors, mailto, tel, external domains
                        if ( strpos( $href, '#' ) === 0 ) continue;
                        if ( strpos( $href, 'mailto:' ) === 0 ) continue;
                        if ( strpos( $href, 'tel:' ) === 0 ) continue;

                        $path = parse_url($href, PHP_URL_PATH);
                        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                        // If extension exists and it’s not a typical page extension → skip
                        if ($ext && !in_array($ext, ['html', 'htm', 'php'])) {
                            continue;
                        }

                        // convert relative to absolute
                        if ( strpos( $href, 'http' ) !== 0 ) {
                            // make absolute
                            $href = rtrim( $domain_origin, '/' ) . '/' . ltrim( $href, '/' );
                        }
                        // only keep same origin
                        if ( strpos( $href, $domain_origin ) === 0 ) {
                            $urls[] = strtok( $href, '?' ); // remove query for uniqueness
                            if ( count( $urls ) >= $limit ) break;
                        }
                    }
                }
            }
            // always include origin as first page
            array_unshift( $urls, $domain_origin );
        }

        // sanitize, unique, limit
        $urls = array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) );

        // ✅ always include origin as the first URL
        array_unshift( $urls, $domain_origin );

        // ✅ ensure uniqueness again, and enforce the limit
        $urls = array_unique($urls);
        return array_slice( $urls, 0, $limit );
    }

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

    /**
     * Call PageSpeed Insights API for one URL.
     * Returns parsed array with key metrics OR WP_Error.

     */
    public function get_pagespeed( $url, $strategy = 'mobile', $api_key = '' ) {


        // ✅ Normalize & validate strategy (frontend passes either 'mobile' or 'desktop')
        $strategy = strtolower( trim( $strategy ) );
        $strategy = in_array( $strategy, [ 'mobile', 'desktop' ], true ) ? $strategy : 'mobile';

        // build base + common args
        $base = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $args = [
            'url'      => esc_url_raw( $url ),
            'strategy' => $strategy,
        ];

        if ( ! empty( $api_key ) ) {
            $args['key'] = $api_key;
        }

        // start URL with base args
        $req_url = add_query_arg( $args, $base );

        // Add categories manually (PSI expects repeated params)
        $categories = ['performance', 'accessibility', 'seo', 'best-practices'];
        foreach ( $categories as $cat ) {
            $req_url .= '&category=' . urlencode( $cat );
        }

        // now call the API
        $resp = wp_remote_get( $req_url, [ 'timeout' => 90 ] );
        if ( is_wp_error( $resp ) ) {
            error_log( '[PSI Scanner] wp_remote_get error for ' . $url . ': ' . $resp->get_error_message() );
            return new WP_Error( 'wp_remote_error', $resp->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        if ( $code !== 200 || empty( $body ) ) {
            // Log the body (may contain helpful JSON error)
            error_log( '[PSI Scanner] Non-200 response for ' . $url . ' code=' . $code . ' body=' . substr( $body, 0, 2000 ) );
            return new WP_Error( 'pagespeed_error', 'PageSpeed API returned non-200 response. code=' . $code );
        }

        $json = json_decode( $body, true );

        if ( ! is_array( $json ) ) {
            error_log( '[PSI Scanner] JSON parse error for ' . $url . ' raw=' . substr( $body, 0, 2000 ) );
            return new WP_Error( 'pagespeed_parse', 'Unable to parse PageSpeed API response.' );
        }

        // Log categories returned to see missing categories
        if ( isset( $json['lighthouseResult']['categories'] ) ) {
            error_log( '[PSI Scanner] Categories returned for ' . $url . ': ' . implode( ',', array_keys( $json['lighthouseResult']['categories'] ) ) );
        }

        // ✅ Basic metric extraction
        $metrics = [];
        $metrics['strategy']          = $strategy; // keep track of Desktop vs Mobile
        $metrics['lighthouseVersion'] = $json['lighthouseResult']['lighthouseVersion'] ?? '';
        $metrics['score']             = isset( $json['lighthouseResult']['categories']['performance']['score'] )
            ? round( $json['lighthouseResult']['categories']['performance']['score'] * 100 )
            : null;

        // ✅ Core Web Vitals (from audits)
        $audits          = $json['lighthouseResult']['audits'] ?? [];
        $metrics['FCP']  = isset($audits['first-contentful-paint']['displayValue']) ? $this->clean_metric_value($audits['first-contentful-paint']['displayValue']) : '';
        $metrics['LCP']  = isset($audits['largest-contentful-paint']['displayValue']) ? $this->clean_metric_value($audits['largest-contentful-paint']['displayValue']) : '';
        $metrics['CLS']  = isset($audits['cumulative-layout-shift']['displayValue']) ? $this->clean_metric_value($audits['cumulative-layout-shift']['displayValue']) : '';
        $metrics['TBT']  = isset($audits['total-blocking-time']['displayValue']) ? $this->clean_metric_value($audits['total-blocking-time']['displayValue']) : '';

        // ✅ Add Speed Index & Time to Interactive
        $metrics['SI']   = isset( $audits['speed-index']['displayValue'] )
            ? $this->clean_metric_value($audits['speed-index']['displayValue'])
            : '';
        $metrics['TTI']  = isset( $audits['interactive']['displayValue'] )
            ? $this->clean_metric_value($audits['interactive']['displayValue'])
            : '';


        //Add SEO, Best Practices, Accessibility Scores
        $metrics['SEO'] = isset( $json['lighthouseResult']['categories']['seo']['score'] )
            ? round( $json['lighthouseResult']['categories']['seo']['score'] * 100 )
            : null; 
        $metrics['BestPractices'] = isset( $json['lighthouseResult']['categories']['best-practices']['score'] )
            ? round( $json['lighthouseResult']['categories']['best-practices']['score'] * 100 )
            : null; 
        $metrics['Accessibility'] = isset( $json['lighthouseResult']['categories']['accessibility']['score'] )
            ? round( $json['lighthouseResult']['categories']['accessibility']['score'] * 100 )
            : null;

        return $metrics;
    }


}
