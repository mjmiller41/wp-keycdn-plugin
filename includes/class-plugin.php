<?php
namespace KeyCDN\Offload;

use KeyCDN\Offload\Admin\Admin;
use KeyCDN\Offload\Admin\AjaxHandler;
use KeyCDN\Offload\Admin\BulkPage;
use KeyCDN\Offload\Admin\ImportPage;
use KeyCDN\Offload\Admin\SettingsPage;
use KeyCDN\Offload\Admin\StatusPage;
use KeyCDN\Offload\Cleanup\DeleteJob;
use KeyCDN\Offload\Cleanup\ReconcileJob;
use KeyCDN\Offload\Cleanup\TrashManager;
use KeyCDN\Offload\Core\Credentials;
use KeyCDN\Offload\Core\Encryption;
use KeyCDN\Offload\Core\FileSanitizer;
use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;
use KeyCDN\Offload\Rewrite\UrlRewriter;
use KeyCDN\Offload\Rewrite\WooRewriter;
use KeyCDN\Offload\Sync\CdnScanner;
use KeyCDN\Offload\Upload\BulkOffload;
use KeyCDN\Offload\Upload\UploadJob;
use KeyCDN\Offload\Upload\UploadManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    private Loader $loader;

    public function __construct() {
        $this->loader = new Loader();
    }

    public function run(): void {
        // --- Core services ---
        $encryption  = new Encryption();
        $credentials = new Credentials( $encryption );
        $state_machine = new StateMachine();
        $manifest    = new Manifest( $state_machine );
        $ftp         = new FtpClient( $credentials );
        $sanitizer   = new FileSanitizer();

        // --- Upload ---
        $upload_manager = new UploadManager( $manifest );
        $upload_job     = new UploadJob( $ftp, $manifest );
        $bulk_offload   = new BulkOffload( $upload_manager, $manifest );

        // --- Cleanup ---
        $trash       = new TrashManager( $manifest );
        $delete_job  = new DeleteJob( $ftp, $manifest, $trash );
        $reconcile   = new ReconcileJob( $ftp, $manifest, $trash );

        // --- Rewrite ---
        $url_rewriter = new UrlRewriter( $credentials, $manifest );

        // --- Sync ---
        $scanner = new CdnScanner( $ftp, $manifest );

        // --- Admin ---
        $settings_page = new SettingsPage( $credentials );
        $bulk_page     = new BulkPage();
        $status_page   = new StatusPage( $manifest );
        $import_page   = new ImportPage();
        $admin         = new Admin( $settings_page, $bulk_page, $status_page, $import_page );
        $ajax_handler  = new AjaxHandler( $bulk_offload, $ftp, $manifest );

        // --- Register hooks ---
        $this->register_upload_hooks( $sanitizer, $upload_manager );
        $this->register_cleanup_hooks( $delete_job, $trash, $reconcile );
        $this->register_rewrite_hooks( $url_rewriter );
        $this->register_as_jobs( $upload_job, $bulk_offload, $delete_job, $trash, $reconcile, $scanner );
        $this->register_admin_hooks( $admin, $settings_page, $ajax_handler );
        $this->maybe_register_woo( $url_rewriter );
        $this->maybe_register_cli( $bulk_offload, $reconcile, $trash, $manifest );

        $this->loader->run();
    }

    private function register_upload_hooks( FileSanitizer $sanitizer, UploadManager $manager ): void {
        $this->loader->add_filter( 'wp_handle_upload_prefilter', $sanitizer, 'prefilter_upload', 10, 1 );
        $this->loader->add_filter( 'wp_generate_attachment_metadata', $manager, 'on_attachment_metadata', 999, 2 );
        $this->loader->add_filter( 'wp_update_attachment_metadata',   $manager, 'on_attachment_metadata', 999, 2 );
    }

    private function register_cleanup_hooks( DeleteJob $delete_job, TrashManager $trash, ReconcileJob $reconcile ): void {
        $this->loader->add_action( 'delete_attachment', $delete_job, 'on_delete_attachment', 10, 1 );
    }

    private function register_rewrite_hooks( UrlRewriter $rewriter ): void {
        $this->loader->add_filter( 'wp_get_attachment_url',        $rewriter, 'rewrite_attachment_url',    10, 2 );
        $this->loader->add_filter( 'wp_calculate_image_srcset',    $rewriter, 'rewrite_srcset',            10, 5 );
        $this->loader->add_filter( 'wp_prepare_attachment_for_js', $rewriter, 'rewrite_attachment_for_js', 10, 3 );
    }

    private function register_as_jobs( UploadJob $upload_job, BulkOffload $bulk, DeleteJob $delete_job, TrashManager $trash, ReconcileJob $reconcile, CdnScanner $scanner ): void {
        $this->loader->add_action( 'keycdn_upload_attachment',   $upload_job,  'handle',             10, 1 );
        $this->loader->add_action( 'keycdn_delete_attachment',   $delete_job,  'handle',             10, 1 );
        $this->loader->add_action( 'keycdn_remove_local',        $trash,       'handle_remove_local', 10, 3 );
        $this->loader->add_action( 'keycdn_purge_trash',         $trash,       'purge_expired',       10, 0 );
        $this->loader->add_action( 'keycdn_reconcile_manifest',  $reconcile,   'handle',             10, 0 );
        $this->loader->add_action( 'keycdn_bulk_page',           $bulk,        'handle_page',        10, 3 );
        $this->loader->add_action( 'keycdn_scan_cdn_page',       $scanner,     'handle',             10, 3 );
    }

    private function register_admin_hooks( Admin $admin, SettingsPage $settings, AjaxHandler $ajax ): void {
        $this->loader->add_action( 'admin_menu',              $admin,    'add_menu_pages',        10, 0 );
        $this->loader->add_action( 'admin_enqueue_scripts',   $admin,    'enqueue_scripts',       10, 1 );
        $this->loader->add_action( 'admin_notices',           $admin,    'show_activation_notice', 10, 0 );
        $this->loader->add_filter( 'plugin_action_links_' . plugin_basename( KEYCDN_OFFLOAD_FILE ), $admin, 'add_plugin_action_links', 10, 1 );
        $this->loader->add_action( 'admin_init',              $settings, 'register_settings',     10, 0 );
        $this->loader->add_action( 'wp_ajax_keycdn_start_bulk',      $ajax, 'start_bulk',       10, 0 );
        $this->loader->add_action( 'wp_ajax_keycdn_bulk_progress',   $ajax, 'bulk_progress',    10, 0 );
        $this->loader->add_action( 'wp_ajax_keycdn_test_connection',    $ajax, 'test_connection',    10, 0 );
        $this->loader->add_action( 'wp_ajax_keycdn_preview_cdn_import', $ajax, 'preview_cdn_import', 10, 0 );
        $this->loader->add_action( 'wp_ajax_keycdn_start_cdn_import',   $ajax, 'start_cdn_import',   10, 0 );
    }

    private function maybe_register_woo( UrlRewriter $rewriter ): void {
        if ( ! get_option( 'keycdn_offload_woo_compat', true ) ) {
            return;
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        $woo_rewriter = new WooRewriter( $rewriter );
        $this->loader->add_filter( 'woocommerce_single_product_image_thumbnail_html', $woo_rewriter, 'rewrite_product_thumbnail_html', 10, 2 );
        $this->loader->add_filter( 'woocommerce_product_get_image',                   $woo_rewriter, 'rewrite_product_get_image',       10, 2 );
    }

    private function maybe_register_cli( BulkOffload $bulk, ReconcileJob $reconcile, TrashManager $trash, Manifest $manifest ): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        $cli = new \KeyCDN\Offload\CLI\CliCommand( $bulk, $reconcile, $trash, $manifest );
        \WP_CLI::add_command( 'keycdn-offload', $cli );
    }
}
