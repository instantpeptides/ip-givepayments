<?php
/**
 * AES-256-GCM encryption / decryption utilities.
 *
 * New encryptions always use AES-256-GCM (authenticated encryption, no
 * padding-oracle risk). Legacy blobs produced by the old AES-256-CBC path
 * are decrypted transparently via the fallback branch in decrypt().
 *
 * Blob formats:
 *  - GCM (current): 'gcm::' . base64( 12-byte-iv | 16-byte-tag | ciphertext )
 *  - CBC (legacy):  base64( base64_ciphertext . '::' . 16-byte-iv )
 *
 * All methods are static so they can be called without an instance.
 * The encryption key is stored as a base64-encoded 32-byte value in
 * the `givepayments_secret_key` WP option and is generated on first use.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Encryptor {

    /**
     * Ensure the secret key option exists, generating one if not.
     *
     * Uses add_option() rather than a get-then-update pattern to avoid a
     * TOCTOU race: under concurrent requests both callers could pass the
     * get_option() check and the second update_option() would silently
     * overwrite the first key, permanently corrupting all data encrypted
     * with it. add_option() is atomic at the DB level, the unique index
     * on option_name means only one INSERT wins; the loser is a safe no-op.
     *
     * autoload is explicitly 'no': the raw encryption key must not be
     * loaded into memory on every WP request.
     *
     * @return void
     */
    public static function generate_and_store_key(): void {
        add_option( 'givepayments_secret_key', base64_encode( openssl_random_pseudo_bytes( 32 ) ), '', 'no' );
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM (authenticated encryption).
     *
     * Automatically ensures the secret key exists before encrypting.
     * The returned blob is self-contained: decrypt() needs no extra args.
     *
     * @param string $plaintext
     * @return string 'gcm::' + base64( 12-byte-iv | 16-byte-tag | ciphertext ), or '' on failure.
     */
    public static function encrypt( string $plaintext ): string {
        self::generate_and_store_key();
        $secret_key = base64_decode( get_option( 'givepayments_secret_key' ) );
        $iv         = openssl_random_pseudo_bytes( 12 ); // 12-byte nonce is standard for GCM.
        $tag        = '';
        $encrypted  = openssl_encrypt( $plaintext, 'AES-256-GCM', $secret_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( false === $encrypted ) {
            return '';
        }
        // Prefix 'gcm::' so decrypt() can distinguish new blobs from legacy CBC blobs.
        return 'gcm::' . base64_encode( $iv . $tag . $encrypted );
    }

    /**
     * Decrypt a ciphertext produced by encrypt().
     *
     * Handles both the current GCM format and the legacy CBC format so that
     * API keys encrypted before the GCM migration continue to decrypt correctly.
     *
     * @param string $ciphertext GCM blob ('gcm::…') or legacy CBC blob.
     * @return string Plaintext, or '' on any failure.
     */
    public static function decrypt( string $ciphertext ): string {
        $ciphertext = trim( $ciphertext );
        if ( '' === $ciphertext ) {
            return '';
        }

        $secret_raw = get_option( 'givepayments_secret_key' );
        if ( ! is_string( $secret_raw ) || '' === $secret_raw ) {
            return '';
        }
        $secret_key = base64_decode( $secret_raw, true );
        if ( false === $secret_key || strlen( $secret_key ) !== 32 ) {
            return '';
        }

        // GCM path, blobs produced by the current encrypt().
        if ( 0 === strncmp( $ciphertext, 'gcm::', 5 ) ) {
            $blob = base64_decode( substr( $ciphertext, 5 ), true );
            if ( false === $blob || strlen( $blob ) < 28 ) { // 12-byte IV + 16-byte auth tag minimum.
                return '';
            }
            $iv             = substr( $blob, 0, 12 );
            $tag            = substr( $blob, 12, 16 );
            $encrypted_data = substr( $blob, 28 );
            $plain = openssl_decrypt( $encrypted_data, 'AES-256-GCM', $secret_key, OPENSSL_RAW_DATA, $iv, $tag );
            return is_string( $plain ) ? $plain : '';
        }

        // Legacy CBC path, read-only; no new encryptions use this cipher.
        $decoded = base64_decode( $ciphertext, true );
        if ( false === $decoded || false === strpos( $decoded, '::' ) ) {
            return '';
        }
        list( $encrypted_data, $iv ) = explode( '::', $decoded, 2 );
        if ( '' === $encrypted_data || strlen( $iv ) !== 16 ) {
            return '';
        }

        $plain = openssl_decrypt( $encrypted_data, 'AES-256-CBC', $secret_key, 0, $iv );
        return is_string( $plain ) ? $plain : '';
    }

    /**
     * Detect whether a value looks like a blob produced by encrypt().
     *
     * Recognises both the current GCM format ('gcm::…') and the legacy CBC
     * format so that settings-page detection works across migrations.
     *
     * @param mixed $value
     * @return bool
     */
    public static function looks_like_encrypted( $value ): bool {
        $s = (string) $value;

        // Current GCM blobs start with 'gcm::'.
        if ( 0 === strncmp( $s, 'gcm::', 5 ) ) {
            $blob = base64_decode( substr( $s, 5 ), true );
            return is_string( $blob ) && strlen( $blob ) >= 28; // 12-byte IV + 16-byte tag minimum.
        }

        // Legacy CBC blob: base64 that decodes to a string containing '::'.
        $decoded = base64_decode( $s, true );
        return is_string( $decoded ) && false !== strpos( $decoded, '::' );
    }
}
