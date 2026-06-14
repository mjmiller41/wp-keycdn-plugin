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
        if ( 'keycdn-offload_page_keycdn-offload-bulk' !== $hook ) {
            return;
        }
        wp_enqueue_script(
            'keycdn-offload-bulk',
            KEYCDN_OFFLOAD_URL . 'assets/js/bulk-progress.js',
            [ 'jquery' ],
            KEYCDN_OFFLOAD_VERSION,
            true
        );
        wp_localize_script( 'keycdn-offload-bulk', 'keyCdnOffload', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'keycdn_offload_bulk' ),
        ] );
    }
}
