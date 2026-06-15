<?php
namespace KeyCDN\Offload\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    private SettingsPage $settings_page;
    private BulkPage     $bulk_page;
    private StatusPage   $status_page;
    private ImportPage   $import_page;

    public function __construct( SettingsPage $settings_page, BulkPage $bulk_page, StatusPage $status_page, ImportPage $import_page ) {
        $this->settings_page = $settings_page;
        $this->bulk_page     = $bulk_page;
        $this->status_page   = $status_page;
        $this->import_page   = $import_page;
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
        add_submenu_page(
            'keycdn-offload',
            __( 'Import from CDN', 'wp-keycdn-offload' ),
            __( 'Import from CDN', 'wp-keycdn-offload' ),
            'manage_options',
            'keycdn-offload-import',
            [ $this->import_page, 'render' ]
        );
    }

    public function add_admin_bar_menu( \WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $ftp   = get_transient( 'keycdn_ftp_status' );
        $color = ! $ftp ? '#999999' : ( ( $ftp['ok'] ?? null ) ? '#46b450' : '#dc3232' );
        $bar->add_node( [
            'id'    => 'keycdn-offload-status',
            'title' => '<span id="keycdn-bar-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr( $color ) . ';margin-right:5px;vertical-align:middle;"></span>'
                     . '<span id="keycdn-bar-label">KeyCDN</span>',
            'href'  => admin_url( 'admin.php?page=keycdn-offload' ),
            'meta'  => [ 'title' => esc_attr__( 'KeyCDN Offload status', 'wp-keycdn-offload' ) ],
        ] );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( current_user_can( 'manage_options' ) ) {
            wp_enqueue_script(
                'keycdn-offload-admin-status',
                KEYCDN_OFFLOAD_URL . 'assets/js/admin-status.js',
                [ 'jquery' ],
                KEYCDN_OFFLOAD_VERSION,
                true
            );
            wp_localize_script( 'keycdn-offload-admin-status', 'keyCdnAdmin', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'keycdn_admin_status' ),
            ] );
        }

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

        if ( 'keycdn-offload_page_keycdn-offload-import' === $hook ) {
            wp_enqueue_script(
                'keycdn-offload-import',
                KEYCDN_OFFLOAD_URL . 'assets/js/import.js',
                [ 'jquery' ],
                KEYCDN_OFFLOAD_VERSION,
                true
            );
            wp_localize_script( 'keycdn-offload-import', 'keyCdnImport', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'keycdn_cdn_import' ),
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

    public function add_plugin_action_links( array $links ): array {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=keycdn-offload' ) ),
            esc_html__( 'Settings', 'wp-keycdn-offload' )
        );
        array_unshift( $links, $settings );
        return $links;
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
