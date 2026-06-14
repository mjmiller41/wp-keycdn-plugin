<?php
namespace KeyCDN\Offload\Admin;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Upload\BulkOffload;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AjaxHandler {

    private BulkOffload $bulk;
    private FtpClient   $ftp;

    public function __construct( BulkOffload $bulk, FtpClient $ftp ) {
        $this->bulk = $bulk;
        $this->ftp  = $ftp;
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
