<?php
namespace KeyCDN\Offload\Cleanup;

use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrashManager {

    private Manifest $manifest;

    public function __construct( Manifest $manifest ) {
        $this->manifest = $manifest;
    }

    /**
     * Move a local file to the quarantine trash directory.
     * Called only after CDN upload is confirmed.
     */
    public function quarantine( int $row_id, string $local_path ): void {
        if ( ! file_exists( $local_path ) ) {
            return;
        }
        $trash_dir = KEYCDN_OFFLOAD_TRASH_DIR . '/' . md5( $local_path );
        if ( ! is_dir( $trash_dir ) ) {
            wp_mkdir_p( $trash_dir );
        }
        $dest = $trash_dir . '/' . basename( $local_path );
        if ( rename( $local_path, $dest ) ) {
            $this->manifest->set_quarantine( $row_id, $dest );
        }
    }

    /**
     * Action Scheduler callback for 'keycdn_remove_local'.
     * Soft-deletes local file after confirming CDN row is in confirmed state.
     */
    public function handle_remove_local( int $attachment_id, int $row_id, string $local_path ): void {
        $row = $this->manifest->get_by_id( $row_id );
        if ( ! $row || $row['state'] !== StateMachine::CONFIRMED ) {
            return;
        }
        $this->quarantine( $row_id, $local_path );
    }

    /**
     * Action Scheduler callback for 'keycdn_purge_trash'.
     * Hard-deletes files in trash older than TTL.
     */
    public function purge_expired(): void {
        $ttl_days = (int) get_option( 'keycdn_offload_trash_ttl_days', 30 );
        $cutoff   = time() - ( $ttl_days * DAY_IN_SECONDS );
        $pattern  = KEYCDN_OFFLOAD_TRASH_DIR . '/*/*';
        $files    = glob( $pattern );
        if ( ! $files ) {
            return;
        }
        foreach ( $files as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
                @unlink( $file );
            }
        }
        // Remove empty sub-directories.
        foreach ( glob( KEYCDN_OFFLOAD_TRASH_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
            if ( 0 === count( glob( $dir . '/*' ) ) ) {
                @rmdir( $dir );
            }
        }
    }
}
