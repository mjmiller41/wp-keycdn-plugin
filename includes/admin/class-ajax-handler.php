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
        try {
            $this->ftp->connect();
            $entries = $this->ftp->list_dir( '/' );
            $this->ftp->disconnect();
            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %d: number of entries found in the CDN zone root */
                    __( 'Connection successful. Zone root contains %d entries.', 'wp-keycdn-offload' ),
                    count( $entries )
                ),
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /** wp_ajax_keycdn_preview_cdn_import */
    public function preview_cdn_import(): void {
        check_ajax_referer( 'keycdn_cdn_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        set_time_limit( 60 );
        try {
            $this->ftp->connect();
            $files     = [];
            $truncated = false;
            $this->collect_cdn_files( '/', $files, $truncated, 500 );
            $this->ftp->disconnect();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
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

    /** wp_ajax_keycdn_bulk_progress */
    public function bulk_progress(): void {
        check_ajax_referer( 'keycdn_offload_bulk', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $progress          = $this->bulk->get_progress();
        $total             = max( 1, $progress['total'] );
        $progress['percent'] = round( ( $progress['completed'] / $total ) * 100, 1 );
        wp_send_json_success( $progress );
    }
}
