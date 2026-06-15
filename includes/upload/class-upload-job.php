<?php
namespace KeyCDN\Offload\Upload;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\FtpException;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UploadJob {

    private FtpClient $ftp;
    private Manifest  $manifest;

    public function __construct( FtpClient $ftp, Manifest $manifest ) {
        $this->ftp      = $ftp;
        $this->manifest = $manifest;
    }

    /**
     * Action Scheduler callback for 'keycdn_upload_attachment'.
     * Throwing an exception causes AS to mark the job as failed and enables retry.
     */
    public function handle( int $attachment_id ): void {
        set_time_limit( 0 );

        $meta       = wp_get_attachment_metadata( $attachment_id );
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );

        // Collect all files: original + all size variants.
        $files = $this->collect_files( $attachment_id, $meta, $base_dir );

        if ( empty( $files ) ) {
            return;
        }

        // connect() is needed for verify() (ftp_size uses control channel only).
        // put() uses cURL independently and does not need the PHP FTP connection.
        try {
            $this->ftp->connect();
        } catch ( FtpException $e ) {
            throw new \RuntimeException( 'FTP connect failed: ' . $e->getMessage(), 0, $e );
        }

        try {
            foreach ( $files as $size_slug => $local_path ) {
                $this->upload_file( $attachment_id, $size_slug, $local_path );
            }
        } finally {
            $this->ftp->disconnect();
        }
    }

    private function collect_files( int $attachment_id, $meta, string $base_dir ): array {
        $files = [];

        // Original file.
        $original = get_attached_file( $attachment_id );
        if ( $original && file_exists( $original ) ) {
            $files['full'] = $original;
        }

        // All generated size variants from metadata.
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            $subdir = '';
            if ( ! empty( $meta['file'] ) ) {
                $subdir = trailingslashit( dirname( $base_dir . $meta['file'] ) );
            } elseif ( ! empty( $meta['sizes'] ) ) {
                $subdir = trailingslashit( dirname( $original ) );
            }
            foreach ( $meta['sizes'] as $slug => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $path = $subdir . $size_data['file'];
                    if ( file_exists( $path ) ) {
                        $files[ $slug ] = $path;
                    }
                }
            }
        }

        return $files;
    }

    private function upload_file( int $attachment_id, string $size_slug, string $local_path ): void {
        $remote_path = UploadManager::build_remote_path( $local_path );
        $byte_size   = (int) filesize( $local_path );
        $md5         = md5_file( $local_path ) ?: '';
        $sha1        = sha1_file( $local_path ) ?: '';

        // Check for existing row or insert.
        $existing_rows = $this->manifest->get_by_attachment( $attachment_id );
        $row_id        = null;
        $current_state = null;
        foreach ( $existing_rows as $row ) {
            if ( $row['size_slug'] === $size_slug ) {
                $row_id        = (int) $row['id'];
                $current_state = $row['state'];
                break;
            }
        }
        if ( null === $row_id ) {
            $row_id = $this->manifest->insert( $attachment_id, $size_slug, $remote_path, $local_path, $byte_size, $md5, $sha1 );
        } elseif ( $current_state === StateMachine::CONFIRMED || $current_state === StateMachine::LOCAL_REMOVED ) {
            return; // Already done — nothing to upload.
        } elseif ( $current_state === StateMachine::PENDING ) {
            // Row was left in PENDING by a prior crashed job. Refresh its stale file metadata
            // before re-uploading in case the file on disk changed since the original insert.
            $this->manifest->update_file_metadata( $row_id, $remote_path, $local_path, $byte_size, $md5, $sha1 );
        } elseif ( $current_state === StateMachine::UPLOADING ) {
            // Row is stuck in UPLOADING from a prior job that crashed before writing FAILED.
            // Reset to FAILED so the FAILED → UPLOADING transition below is valid.
            $this->manifest->transition_state( $row_id, StateMachine::FAILED );
        } elseif ( $current_state === StateMachine::VERIFYING ) {
            // Row is stuck in VERIFYING from a prior job that crashed after ftp_size.
            // VERIFYING → UPLOADING is not a valid transition; go through FAILED first.
            $this->manifest->transition_state( $row_id, StateMachine::FAILED );
        }

        $this->manifest->transition_state( $row_id, StateMachine::UPLOADING );

        try {
            $this->ftp->put( $local_path, $remote_path );
        } catch ( FtpException $e ) {
            $this->manifest->increment_retry( $row_id );
            $this->manifest->transition_state( $row_id, StateMachine::FAILED );
            // Rethrow so AS marks the whole job as failed and retries.
            throw new \RuntimeException( "Upload failed [{$size_slug}]: " . $e->getMessage(), 0, $e );
        }

        $this->manifest->transition_state( $row_id, StateMachine::VERIFYING );

        // Verify the remote file size matches.
        if ( $this->ftp->verify( $remote_path, $byte_size ) ) {
            $this->manifest->transition_state( $row_id, StateMachine::CONFIRMED );
            $this->manifest->set_verified( $row_id );

            // Optionally soft-delete the local file in the background.
            if ( get_option( 'keycdn_offload_remove_local', false ) && 'full' !== $size_slug ) {
                as_enqueue_async_action(
                    'keycdn_remove_local',
                    [ 'attachment_id' => $attachment_id, 'row_id' => $row_id, 'local_path' => $local_path ],
                    'keycdn-offload'
                );
            }
        } else {
            $this->manifest->increment_retry( $row_id );
            $this->manifest->transition_state( $row_id, StateMachine::FAILED );
            throw new \RuntimeException( "Remote size verification failed for {$remote_path}" );
        }
    }
}
