<?php
namespace KeyCDN\Offload\Cleanup;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\FtpException;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReconcileJob {

    private FtpClient    $ftp;
    private Manifest     $manifest;
    private TrashManager $trash;

    public function __construct( FtpClient $ftp, Manifest $manifest, TrashManager $trash ) {
        $this->ftp      = $ftp;
        $this->manifest = $manifest;
        $this->trash    = $trash;
    }

    /**
     * Action Scheduler recurring callback for 'keycdn_reconcile_manifest'.
     */
    public function handle(): void {
        set_time_limit( 0 );
        $rows = $this->manifest->get_pending_reconcile( 24 );
        if ( empty( $rows ) ) {
            $this->trash->purge_expired();
            return;
        }

        try {
            $this->ftp->connect();
        } catch ( FtpException $e ) {
            throw new \RuntimeException( 'FTP connect failed during reconcile: ' . $e->getMessage(), 0, $e );
        }

        foreach ( $rows as $row ) {
            $row_id        = (int) $row['id'];
            $expected_size = (int) $row['byte_size'];

            if ( $this->ftp->verify( $row['remote_path'], $expected_size ) ) {
                $this->manifest->set_verified( $row_id );
            } else {
                // CDN copy is missing or corrupted — attempt re-upload.
                $local_source = ! empty( $row['quarantine_path'] ) && file_exists( $row['quarantine_path'] )
                    ? $row['quarantine_path']
                    : ( ! empty( $row['local_path'] ) && file_exists( $row['local_path'] ) ? $row['local_path'] : null );

                if ( $local_source ) {
                    // Reset state and re-enqueue upload.
                    global $wpdb;
                    $wpdb->update(
                        Manifest::table_name(),
                        [ 'state' => StateMachine::PENDING, 'updated_at' => current_time( 'mysql', true ) ],
                        [ 'id'    => $row_id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    as_enqueue_async_action(
                        'keycdn_upload_attachment',
                        [ 'attachment_id' => (int) $row['attachment_id'] ],
                        'keycdn-offload'
                    );
                } else {
                    error_log( sprintf(
                        'KeyCDN Offload: Cannot recover attachment %d size %s — no local or quarantine copy.',
                        $row['attachment_id'],
                        $row['size_slug']
                    ) );
                }
            }
        }

        $this->ftp->disconnect();
        $this->trash->purge_expired();
    }
}
