<?php
namespace KeyCDN\Offload\Cleanup;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\FtpException;
use KeyCDN\Offload\Core\Manifest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DeleteJob {

    private FtpClient    $ftp;
    private Manifest     $manifest;
    private TrashManager $trash;

    public function __construct( FtpClient $ftp, Manifest $manifest, TrashManager $trash ) {
        $this->ftp     = $ftp;
        $this->manifest = $manifest;
        $this->trash   = $trash;
    }

    /**
     * Hook: delete_attachment (priority 10, 1 arg)
     * Enqueues async CDN delete job before WP removes the DB record.
     */
    public function on_delete_attachment( int $attachment_id ): void {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return;
        }
        as_enqueue_async_action(
            'keycdn_delete_attachment',
            [ 'attachment_id' => $attachment_id ],
            'keycdn-offload'
        );
    }

    /**
     * Action Scheduler callback for 'keycdn_delete_attachment'.
     */
    public function handle( int $attachment_id ): void {
        set_time_limit( 0 );
        $rows = $this->manifest->get_by_attachment( $attachment_id );
        if ( empty( $rows ) ) {
            return;
        }
        try {
            $this->ftp->connect();
        } catch ( FtpException $e ) {
            throw new \RuntimeException( 'FTP connect failed during delete: ' . $e->getMessage(), 0, $e );
        }
        try {
            foreach ( $rows as $row ) {
                try {
                    $this->ftp->delete( $row['remote_path'] );
                } catch ( FtpException $e ) {
                    // Log but continue — file may already be gone from CDN.
                    error_log( sprintf( 'KeyCDN Offload: FTP delete failed for %s: %s', $row['remote_path'], $e->getMessage() ) );
                }
                // Quarantine any remaining local file.
                if ( ! empty( $row['local_path'] ) && file_exists( $row['local_path'] ) ) {
                    $this->trash->quarantine( (int) $row['id'], $row['local_path'] );
                }
            }
        } finally {
            $this->ftp->disconnect();
        }
        $this->manifest->delete_by_attachment( $attachment_id );
    }
}
