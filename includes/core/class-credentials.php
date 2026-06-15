<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Credentials {

    private Encryption $encryption;

    public function __construct( Encryption $encryption ) {
        $this->encryption = $encryption;
    }

    public function get_ftp_host(): string {
        if ( defined( 'KEYCDN_FTP_HOST' ) ) {
            return KEYCDN_FTP_HOST;
        }
        return get_option( 'keycdn_offload_ftp_host', 'ftp.keycdn.com' );
    }

    public function get_ftp_user(): string {
        if ( defined( 'KEYCDN_FTP_USER' ) && '' !== KEYCDN_FTP_USER ) {
            return KEYCDN_FTP_USER;
        }
        return (string) get_option( 'keycdn_offload_ftp_user', '' );
    }

    public function get_ftp_pass(): string {
        if ( defined( 'KEYCDN_FTP_PASS' ) && '' !== KEYCDN_FTP_PASS ) {
            return KEYCDN_FTP_PASS;
        }
        $enc = (string) get_option( 'keycdn_offload_ftp_pass_enc', '' );
        if ( '' === $enc ) {
            return '';
        }
        return $this->encryption->decrypt( $enc );
    }

    public function save_ftp_pass( string $plaintext ): void {
        update_option( 'keycdn_offload_ftp_pass_enc', $this->encryption->encrypt( $plaintext ), false );
    }

    public function get_zone_url(): string {
        if ( defined( 'KEYCDN_ZONE_URL' ) && '' !== KEYCDN_ZONE_URL ) {
            return rtrim( KEYCDN_ZONE_URL, '/' );
        }
        return rtrim( (string) get_option( 'keycdn_offload_zone_url', '' ), '/' );
    }

    public function is_configured(): bool {
        return '' !== $this->get_ftp_user()
            && '' !== $this->get_ftp_pass()
            && '' !== $this->get_zone_url();
    }
}
