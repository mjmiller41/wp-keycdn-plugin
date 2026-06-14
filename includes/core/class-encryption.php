<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AES-256-CTR encryption for credential storage in wp_options.
 * Based on Felix Arntz's Data_Encryption pattern used in Google Site Kit.
 *
 * Key is derived from a dedicated plugin constant (preferred) or WP's LOGGED_IN_KEY.
 * IMPORTANT: rotating WP salts or changing KEYCDN_ENCRYPTION_KEY permanently
 * breaks decryption — prompt the admin to re-enter FTP credentials after key changes.
 */
class Encryption {

    const METHOD = 'aes-256-ctr';

    private string $key;
    private string $salt;

    public function __construct() {
        $this->key  = defined( 'KEYCDN_ENCRYPTION_KEY' ) ? KEYCDN_ENCRYPTION_KEY : ( defined( 'LOGGED_IN_KEY' )  ? LOGGED_IN_KEY  : 'fallback-key-change-me' );
        $this->salt = defined( 'KEYCDN_ENCRYPTION_SALT' ) ? KEYCDN_ENCRYPTION_SALT : ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'fallback-salt-change-me' );
    }

    public function encrypt( string $value ): string {
        if ( ! extension_loaded( 'openssl' ) ) {
            return base64_encode( $value );
        }
        $ivlen  = openssl_cipher_iv_length( self::METHOD );
        $iv     = openssl_random_pseudo_bytes( $ivlen );
        $raw    = openssl_encrypt( $value . $this->salt, self::METHOD, $this->key, 0, $iv );
        if ( false === $raw ) {
            return '';
        }
        return base64_encode( $iv . $raw );
    }

    public function decrypt( string $stored ): string {
        if ( ! extension_loaded( 'openssl' ) ) {
            return base64_decode( $stored );
        }
        $decoded = base64_decode( $stored, true );
        if ( false === $decoded ) {
            return '';
        }
        $ivlen = openssl_cipher_iv_length( self::METHOD );
        $iv    = substr( $decoded, 0, $ivlen );
        $raw   = openssl_decrypt( substr( $decoded, $ivlen ), self::METHOD, $this->key, 0, $iv );
        if ( false === $raw ) {
            return '';
        }
        // Strip the appended salt from the decrypted value.
        $salt_len = strlen( $this->salt );
        if ( substr( $raw, -$salt_len ) === $this->salt ) {
            return substr( $raw, 0, -$salt_len );
        }
        return '';
    }
}
