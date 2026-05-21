<?php
/**
 * Request-context resolution utilities for GivePayments.
 *
 * Centralises the stateless helpers that read WordPress options and server
 * globals to produce the API key, base URL, merchant ID, and requestor IP
 * needed for every outbound API call.
 *
 * All methods are static, no instance state is required.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Request_Context {

    // -------------------------------------------------------------------------
    // API URL
    // -------------------------------------------------------------------------

    /**
     * Return the base API URL for the given environment (no trailing slash).
     *
     * @param string $environment 'production' or anything else for sandbox.
     * @return string
     */
    public static function api_base_url( string $environment ): string {
        $url = ( 'production' === $environment )
            ? 'https://connect.givepayments.com'
            : 'https://api-sandbox.givepayments.com';

        /**
         * Override the GivePayments API base URL.
         *
         * Useful for pointing a dev or staging site at a mock server without
         * patching source code. The URL must have no trailing slash.
         *
         * @param string $url         Base URL (no trailing slash).
         * @param string $environment 'production' or 'test'.
         */
        return (string) apply_filters( 'givepayments_api_base_url', $url, $environment );
    }

    // -------------------------------------------------------------------------
    // API key resolution
    // -------------------------------------------------------------------------

    /**
     * Return the raw (encrypted-at-rest) API key stored for the active environment.
     *
     * @return string Encrypted blob, or '' if not set.
     */
    public static function get_api_key_for_environment(): string {
        $environment = get_option( 'givepayments_environment', 'test' );
        if ( 'test' === $environment ) {
            return (string) get_option( 'givepayments_sandbox_api_key', '' );
        }
        return (string) get_option( 'givepayments_production_api_key', '' );
    }

    /**
     * Return the decrypted API key for a given environment (admin UI use only).
     *
     * Falls back to returning the raw option value when decryption fails so that
     * settings saved with older plugin versions continue to work.
     *
     * @param string $environment 'production' or 'test'.
     * @return string Plaintext API key, or '' if not configured.
     */
    public static function get_api_key_decrypted( string $environment ): string {
        $environment = ( 'production' === $environment ) ? 'production' : 'test';
        $encrypted   = ( 'test' === $environment )
            ? (string) get_option( 'givepayments_sandbox_api_key', '' )
            : (string) get_option( 'givepayments_production_api_key', '' );

        if ( '' === $encrypted ) {
            return '';
        }

        $decrypted = GIVEPAYMENTS_Encryptor::decrypt( $encrypted );
        return '' !== $decrypted ? $decrypted : $encrypted;
    }

    /**
     * Resolve an API key value that may be encrypted-at-rest or already plaintext.
     *
     * Strips leading/trailing whitespace from the resolved value.
     *
     * @param string $value Raw option value (encrypted blob or plaintext key).
     * @return string Plaintext API key, or '' if the value is empty or unresolvable.
     */
    public static function resolve_api_key( string $value ): string {
        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }
        if ( GIVEPAYMENTS_Encryptor::looks_like_encrypted( $value ) ) {
            $decrypted = GIVEPAYMENTS_Encryptor::decrypt( $value );
            if ( '' !== $decrypted ) {
                return trim( $decrypted );
            }
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Merchant ID
    // -------------------------------------------------------------------------

    /**
     * Return the configured merchant ID.
     *
     * @return string
     */
    public static function get_merchant_id(): string {
        return (string) get_option( 'givepayments_merchant_id', '' );
    }

    // -------------------------------------------------------------------------
    // Requestor IP
    // -------------------------------------------------------------------------

    /**
     * Resolve the end-user IP in a "secure by default" way.
     *
     * Priority order:
     *  1. Cloudflare CF-Connecting-IP / True-Client-IP, only when REMOTE_ADDR
     *     is a known Cloudflare edge CIDR (prevents spoofing when not behind CF).
     *  2. X-Forwarded-For / X-Real-IP / Client-IP, only when REMOTE_ADDR looks
     *     like a private/loopback address (i.e. a local load balancer/proxy).
     *  3. REMOTE_ADDR as the safe fallback.
     *
     * @return string IPv4 or IPv6 address string, defaults to '127.0.0.1'.
     */
    public static function get_client_ip(): string {
        $sanitize = static function ( $value ): string {
            return sanitize_text_field( wp_unslash( $value ) );
        };

        $is_valid_ip = static function ( string $ip ): bool {
            return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
        };

        $ip_in_cidr = static function ( string $ip, string $cidr ) use ( $is_valid_ip ): bool {
            if ( false === strpos( $cidr, '/' ) ) {
                return $ip === $cidr;
            }

            list( $subnet, $mask_bits ) = explode( '/', $cidr, 2 );
            $ip_bin     = @inet_pton( $ip );
            $subnet_bin = @inet_pton( $subnet );
            $mask_bits  = (int) $mask_bits;

            if ( false === $ip_bin || false === $subnet_bin ) {
                return false;
            }
            if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
                return false;
            }

            $full_bytes     = (int) floor( $mask_bits / 8 );
            $remaining_bits = $mask_bits % 8;

            for ( $i = 0; $i < $full_bytes; $i++ ) {
                if ( $ip_bin[ $i ] !== $subnet_bin[ $i ] ) {
                    return false;
                }
            }

            if ( $remaining_bits > 0 ) {
                $mask = chr( ( 0xFF << ( 8 - $remaining_bits ) ) & 0xFF );
                if ( ( $ip_bin[ $full_bytes ] & $mask ) !== ( $subnet_bin[ $full_bytes ] & $mask ) ) {
                    return false;
                }
            }

            return true;
        };

        $is_cloudflare_proxy = static function ( string $ip ) use ( $ip_in_cidr ): bool {
            if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return false;
            }

            // Cloudflare published edge CIDRs (snapshot for defensive header trust).
            $cf_cidrs = array(
                '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
                '103.31.4.0/22',   '141.101.64.0/18', '108.162.192.0/18',
                '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
                '198.41.128.0/17', '162.158.0.0/15',  '104.16.0.0/13',
                '104.24.0.0/14',   '172.64.0.0/13',   '131.0.72.0/22',
                '2400:cb00::/32',  '2606:4700::/32',   '2803:f800::/32',
                '2405:b500::/32',  '2405:8100::/32',   '2a06:98c0::/29',
                '2c0f:f248::/32',
            );

            foreach ( $cf_cidrs as $cidr ) {
                if ( $ip_in_cidr( $ip, $cidr ) ) {
                    return true;
                }
            }

            return false;
        };

        $looks_like_proxy = static function ( string $ip ): bool {
            if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return false;
            }

            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                $long = ip2long( $ip );
                if ( false === $long ) {
                    return false;
                }
                if ( ( $long & 0xFF000000 ) === 0x0A000000 ) { return true; } // 10.0.0.0/8
                if ( ( $long & 0xFFF00000 ) === 0xAC100000 ) { return true; } // 172.16.0.0/12
                if ( ( $long & 0xFFFF0000 ) === 0xC0A80000 ) { return true; } // 192.168.0.0/16
                if ( ( $long & 0xFF000000 ) === 0x7F000000 ) { return true; } // 127.0.0.0/8
                if ( ( $long & 0xFFFF0000 ) === 0xA9FE0000 ) { return true; } // 169.254.0.0/16
                return false;
            }

            // IPv6 heuristics.
            $ip_lower = strtolower( $ip );
            if ( '::1' === $ip_lower )                                           { return true; }
            if ( 'fc' === substr( $ip_lower, 0, 2 ) || 'fd' === substr( $ip_lower, 0, 2 ) ) { return true; } // fc00::/7
            if ( 'fe80' === substr( $ip_lower, 0, 4 ) )                          { return true; } // fe80::/10

            return false;
        };

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $sanitize( $_SERVER['REMOTE_ADDR'] ) : '';

        // 1) Cloudflare headers, only when REMOTE_ADDR is a known Cloudflare edge.
        $cf_candidates = array();
        if ( $is_cloudflare_proxy( $remote_addr ) ) {
            if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                $cf_candidates[] = $sanitize( $_SERVER['HTTP_CF_CONNECTING_IP'] );
            }
            if ( isset( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ) {
                $cf_candidates[] = $sanitize( $_SERVER['HTTP_TRUE_CLIENT_IP'] );
            }
        }
        foreach ( $cf_candidates as $candidate ) {
            if ( $candidate && $is_valid_ip( $candidate ) ) {
                return (string) $candidate;
            }
        }

        // 2) Proxy headers, only when REMOTE_ADDR looks like a private/loopback address.
        if ( $looks_like_proxy( $remote_addr ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $xff       = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? $sanitize( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $x_real_ip = isset( $_SERVER['HTTP_X_REAL_IP'] ) ? $sanitize( $_SERVER['HTTP_X_REAL_IP'] ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $client_ip = isset( $_SERVER['HTTP_CLIENT_IP'] ) ? $sanitize( $_SERVER['HTTP_CLIENT_IP'] ) : '';

            if ( $xff ) {
                $first = trim( explode( ',', $xff )[0] );
                // Handle bracketed IPv6 and/or IPv4:port formats.
                if ( preg_match( '/^\[([^\]]+)\]/', $first, $m ) ) {
                    $first = $m[1];
                } elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $first, $m ) ) {
                    $first = $m[1];
                }
                if ( $first && $is_valid_ip( $first ) ) {
                    return (string) $first;
                }
            }

            if ( $x_real_ip && $is_valid_ip( $x_real_ip ) ) { return (string) $x_real_ip; }
            if ( $client_ip && $is_valid_ip( $client_ip ) ) { return (string) $client_ip; }
        }

        // 3) Safe fallback: use REMOTE_ADDR when valid.
        if ( $remote_addr && $is_valid_ip( $remote_addr ) ) {
            return $remote_addr === '::1' ? '127.0.0.1' : (string) $remote_addr;
        }

        return '127.0.0.1';
    }
}
