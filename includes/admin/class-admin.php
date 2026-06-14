<?php
namespace KeyCDN\Offload\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    private SettingsPage $settings_page;
    private BulkPage     $bulk_page;
    private StatusPage   $status_page;

    public function __construct( SettingsPage $settings_page, BulkPage $bulk_page, StatusPage $status_page ) {
        $this->settings_page = $settings_page;
        $this->bulk_page     = $bulk_page;
        $this->status_page   = $status_page;
    }

    public function add_menu_pages(): void {
        add_menu_page(
            __( 'KeyCDN Offload', 'wp-keycdn-offload' ),
            __( 'KeyCDN Offload', 'wp-keycdn-offload' ),
            'manage_options',
            'keycdn-offload',
            [ $this->settings_page, 'render' ],
            'dashicons-cloud-upload',
            80
        );
        add_submenu_page(
            'keycdn-offload',
            __( 'Settings', 'wp-keycdn-offload' ),
            __( 'Settings', 'wp-keycdn-offload' ),
            'manage_options',
            'keycdn-offload',
            [ $this->settings_page, 'render' ]
        );
        add_submenu_page(
            'keycdn-offload',
            __( 'Bulk Offload', 'wp-keycdn-offload' ),
            __( 'Bulk Offload', 'wp-keycdn-offload' ),
            'manage_options',
            'keycdn-offload-bulk',
            [ $this->bulk_page, 'render' ]
        );
        add_submenu_page(
            'keycdn-offload',
            __( 'Offload Status', 'wp-keycdn-offload' ),
            __( 'Status Log', 'wp-keycdn-offload' ),
            'manage_options',
            'keycdn-offload-status',
            [ $this->status_page, 'render' ]
        );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( 'keycdn-offload_page_keycdn-offload-bulk' === $hook ) {
            wp_enqueue_script(
                'keycdn-offload-bulk',
                KEYCDN_OFFLOAD_URL . 'assets/js/bulk-progress.js',
                [ 'jquery' ],
                KEYCDN_OFFLOAD_VERSION,
                true
            );
            wp_localize_script( 'keycdn-offload-bulk', 'keyCdnOffload', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'keycdn_offload_bulk' ),
            ] );
        }

        if ( 'toplevel_page_keycdn-offload' === $hook ) {
            wp_enqueue_script(
                'keycdn-offload-settings',
                KEYCDN_OFFLOAD_URL . 'assets/js/settings.js',
                [ 'jquery' ],
                KEYCDN_OFFLOAD_VERSION,
                true
            );
            wp_localize_script( 'keycdn-offload-settings', 'keyCdnSettings', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'keycdn_test_connection' ),
            ] );
        }
    }

    public function show_activation_notice(): void {
        if ( ! get_transient( 'keycdn_offload_activated' ) ) {
            return;
        }
        delete_transient( 'keycdn_offload_activated' );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s <a href="%s"><strong>%s</strong></a></p></div>',
            esc_html__( 'WP KeyCDN Media Offload is active.', 'wp-keycdn-offload' ),
            esc_url( admin_url( 'admin.php?page=keycdn-offload' ) ),
            esc_html__( 'Enter your KeyCDN credentials to get started →', 'wp-keycdn-offload' )
        );
    }
}
