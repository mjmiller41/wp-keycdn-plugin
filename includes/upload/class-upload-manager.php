<?php
namespace KeyCDN\Offload\Upload;

use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UploadManager {

    private Manifest $manifest;

    public function __construct( Manifest $manifest ) {
        $this->manifest = $manifest;
    }

    /**
     * Hook: wp_generate_attachment_metadata (priority 999)
     * Hook: wp_update_attachment_metadata (priority 999, covers WooCommerce regenerate)
     */
    public function on_attachment_metadata( $metadata, int $attachment_id ) {
        if ( ! get_option( 'keycdn_offload_auto_offload', true ) ) {
            return $metadata;
        }
        $this->enqueue_attachment( $attachment_id );
        return $metadata;
    }

    public function enqueue_attachment( int $attachment_id ): void {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return;
        }
        // Skip if already confirmed or actively processing.
        $rows = $this->manifest->get_by_attachment( $attachment_id );
        foreach ( $rows as $row ) {
            if ( in_array( $row['state'], [ StateMachine::UPLOADING, StateMachine::VERIFYING, StateMachine::CONFIRMED, StateMachine::LOCAL_REMOVED ], true ) ) {
                return;
            }
        }
        // Skip if an AS job is already pending or running for this attachment.
        // Checking both statuses prevents a second bulk page from duplicating jobs
        // that are currently being processed by a concurrent AS worker.
        foreach ( [ 'pending', 'in-progress' ] as $status ) {
            $found = as_get_scheduled_actions(
                [
                    'hook'     => 'keycdn_upload_attachment',
                    'args'     => [ 'attachment_id' => $attachment_id ],
                    'status'   => $status,
                    'per_page' => 1,
                ],
                'ids'
            );
            if ( ! empty( $found ) ) {
                return;
            }
        }
        as_enqueue_async_action(
            'keycdn_upload_attachment',
            [ 'attachment_id' => $attachment_id ],
            'keycdn-offload'
        );
    }

    /**
     * Build the remote FTP path for a given local file path.
     * Maps wp-content/uploads/YYYY/MM/filename.ext → /YYYY/MM/filename.ext (plus optional subdir prefix).
     */
    public static function build_remote_path( string $local_path ): string {
        $upload_dir  = wp_upload_dir();
        $base_dir    = trailingslashit( $upload_dir['basedir'] );
        $subdir      = get_option( 'keycdn_offload_zone_subdir', '' );
        $relative    = str_replace( $base_dir, '', $local_path );
        $prefix      = $subdir ? trailingslashit( $subdir ) : '';
        return '/' . ltrim( $prefix . $relative, '/' );
    }
}
