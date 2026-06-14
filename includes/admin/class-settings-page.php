<?php
namespace KeyCDN\Offload\Admin;

use KeyCDN\Offload\Core\Credentials;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsPage {

    private Credentials $credentials;

    public function __construct( Credentials $credentials ) {
        $this->credentials = $credentials;
    }

    public function register_settings(): void {
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_zone_url',       [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_ftp_host',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_ftp_user',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_ftp_pass_new',   [ 'sanitize_callback' => [ $this, 'save_password' ] ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_auto_offload',   [ 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_remove_local',   [ 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_trash_ttl_days', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_large_file_mb',  [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_woo_compat',     [ 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'keycdn_offload_settings', 'keycdn_offload_zone_subdir',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public function save_password( string $value ): string {
        // We store the encrypted password in a different option key.
        // Returning empty string prevents writing to keycdn_offload_ftp_pass_new.
        if ( '' !== $value ) {
            $this->credentials->save_ftp_pass( $value );
        }
        return '';
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $using_constants = defined( 'KEYCDN_FTP_USER' ) && defined( 'KEYCDN_FTP_PASS' ) && defined( 'KEYCDN_ZONE_URL' );
        include KEYCDN_OFFLOAD_PATH . 'templates/settings-page.php';
    }
}
