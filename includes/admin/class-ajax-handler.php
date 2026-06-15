<?php
namespace KeyCDN\Offload\Admin;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Upload\BulkOffload;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AjaxHandler {

    private BulkOffload $bulk;
    private FtpClient   $ftp;
    private Manifest    $manifest;

    public function __construct( BulkOffload $bulk, FtpClient $ftp, Manifest $manifest ) {
        $this->bulk     = $bulk;
        $this->ftp      = $ftp;
        $this->manifest = $manifest;
    }

    /** wp_ajax_keycdn_start_bulk */
    public function start_bulk(): void {
        check_ajax_referer( 'keycdn_offload_bulk', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $batch_size = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 50;
        $this->bulk->start( $batch_size );
        wp_send_json_success( $this->bulk->get_progress() );
    }

    /** wp_ajax_keycdn_test_connection */
    public function test_connection(): void {
        check_ajax_referer( 'keycdn_test_connection', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $entries = [];
        try {
            $this->ftp->connect();
            $entries = $this->ftp->list_dir( '/' );
            $message = sprintf(
                /* translators: %d: number of entries found in the CDN zone root */
                __( 'Connection successful. Zone root contains %d entries.', 'wp-keycdn-offload' ),
                count( $entries )
            );
            set_transient( 'keycdn_ftp_status', [ 'ok' => true,  'message' => $message,          'time' => time() ], HOUR_IN_SECONDS );
            wp_send_json_success( [ 'message' => $message ] );
        } catch ( \Throwable $e ) {
            set_transient( 'keycdn_ftp_status', [ 'ok' => false, 'message' => $e->getMessage(),  'time' => time() ], HOUR_IN_SECONDS );
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        } finally {
            $this->ftp->disconnect();
        }
    }

    /** wp_ajax_keycdn_preview_cdn_import */
    public function preview_cdn_import(): void {
        check_ajax_referer( 'keycdn_cdn_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        set_time_limit( 60 );
        $files     = [];
        $truncated = false;
        try {
            $this->ftp->connect();
            $this->collect_cdn_files( '/', $files, $truncated, 500 );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
        } finally {
            $this->ftp->disconnect();
        }

        $known      = array_flip( $this->manifest->get_all_remote_paths() );
        $total      = count( $files );
        $unimported = array_values( array_filter( $files, fn( $p ) => ! isset( $known[ $p ] ) ) );
        $samples    = array_slice( array_map( 'basename', $unimported ), 0, 8 );

        wp_send_json_success( [
            'total'      => $total,
            'imported'   => $total - count( $unimported ),
            'unimported' => count( $unimported ),
            'truncated'  => $truncated,
            'samples'    => $samples,
        ] );
    }

    /** wp_ajax_keycdn_start_cdn_import */
    public function start_cdn_import(): void {
        check_ajax_referer( 'keycdn_cdn_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            wp_send_json_error( [ 'message' => __( 'Action Scheduler is not active.', 'wp-keycdn-offload' ) ] );
            return;
        }
        as_enqueue_async_action(
            'keycdn_scan_cdn_page',
            [ 'remote_dir' => '/', 'page' => 1, 'per_page' => 50 ],
            'keycdn-offload'
        );
        wp_send_json_success( [ 'message' => 'Import queued.' ] );
    }

    /**
     * Recursively collect all file paths in a CDN directory tree, up to $limit entries.
     * Uses the same FTP connection — caller must connect/disconnect around this.
     */
    private function collect_cdn_files( string $dir, array &$files, bool &$truncated, int $limit ): void {
        if ( count( $files ) >= $limit ) {
            $truncated = true;
            return;
        }
        $entries = $this->ftp->list_dir( $dir );
        foreach ( $entries as $entry ) {
            if ( count( $files ) >= $limit ) {
                $truncated = true;
                break;
            }
            $name = $entry['name'] ?? null;
            if ( ! $name || in_array( $name, [ '.', '..' ], true ) ) {
                continue;
            }
            $path = trailingslashit( $dir ) . $name;
            $type = strtolower( $entry['type'] ?? 'file' );
            if ( in_array( $type, [ 'dir', 'cdir', 'pdir' ], true ) ) {
                $this->collect_cdn_files( $path, $files, $truncated, $limit );
            } else {
                $files[] = $path;
            }
        }
    }

    /** wp_ajax_keycdn_admin_status */
    public function get_admin_status(): void {
        check_ajax_referer( 'keycdn_admin_status', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }

        $ftp = get_transient( 'keycdn_ftp_status' ) ?: [ 'ok' => null, 'message' => 'Not yet tested', 'time' => null ];

        $bulk_progress = $this->bulk->get_progress();
        $bulk_jobs     = ( 'running' === $bulk_progress['status'] ) ? 1 : 0;

        $scan_jobs = 0;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            foreach ( [ 'pending', 'in-progress' ] as $as_status ) {
                $scan_jobs += count( as_get_scheduled_actions(
                    [ 'hook' => 'keycdn_scan_cdn_page', 'status' => $as_status, 'per_page' => 100 ],
                    'ids'
                ) );
            }
        }

        wp_send_json_success( [
            'ftp'           => $ftp,
            'bulk_jobs'     => $bulk_jobs,
            'bulk_progress' => $bulk_progress,
            'scan_jobs'     => $scan_jobs,
        ] );
    }

    /** wp_ajax_keycdn_bulk_progress */
    public function bulk_progress(): void {
        check_ajax_referer( 'keycdn_offload_bulk', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        wp_send_json_success( $this->bulk->get_progress() );
    }
}
