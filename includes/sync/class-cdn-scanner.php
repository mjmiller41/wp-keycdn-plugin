<?php
namespace KeyCDN\Offload\Sync;

use KeyCDN\Offload\Core\FtpClient;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CdnScanner {

    private FtpClient $ftp;
    private Manifest  $manifest;

    public function __construct( FtpClient $ftp, Manifest $manifest ) {
        $this->ftp      = $ftp;
        $this->manifest = $manifest;
    }

    /**
     * Action Scheduler callback for 'keycdn_scan_cdn_page'.
     * Walks a CDN directory page and maps files to WP attachment records.
     */
    public function handle( string $remote_dir, int $page, int $per_page ): void {
        set_time_limit( 0 );
        $this->ftp->connect();
        $entries = $this->ftp->list_dir( $remote_dir );
        $this->ftp->disconnect();

        $offset  = ( $page - 1 ) * $per_page;
        $slice   = array_slice( $entries, $offset, $per_page );

        foreach ( $slice as $entry ) {
            $name = $entry['name'] ?? null;
            if ( ! $name || in_array( $name, [ '.', '..' ], true ) ) {
                continue;
            }
            $remote_path = trailingslashit( $remote_dir ) . $name;
            $this->map_cdn_file_to_attachment( $remote_path, $name, (int) ( $entry['size'] ?? 0 ) );
        }

        // If there may be more entries, enqueue the next page.
        if ( count( $slice ) === $per_page ) {
            as_enqueue_async_action(
                'keycdn_scan_cdn_page',
                [ 'remote_dir' => $remote_dir, 'page' => $page + 1, 'per_page' => $per_page ],
                'keycdn-offload'
            );
        }
    }

    private function map_cdn_file_to_attachment( string $remote_path, string $filename, int $byte_size ): void {
        global $wpdb;

        // Check if already in manifest.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Manifest::table_name() . ' WHERE remote_path = %s',
                $remote_path
            )
        );
        if ( $exists ) {
            return;
        }

        // Try to find an existing attachment by filename.
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'attachment'
                   AND pm.meta_key = '_wp_attached_file'
                   AND pm.meta_value LIKE %s",
                '%' . $wpdb->esc_like( $filename )
            )
        );

        if ( ! $attachment_id ) {
            // Import as new attachment with no local file.
            $zone_url  = get_option( 'keycdn_offload_zone_url', '' );
            $cdn_url   = rtrim( $zone_url, '/' ) . '/' . ltrim( $remote_path, '/' );
            $args      = [
                'post_title'   => pathinfo( $filename, PATHINFO_FILENAME ),
                'post_status'  => 'inherit',
                'post_type'    => 'attachment',
                'guid'         => $cdn_url,
                'post_mime_type' => wp_check_filetype( $filename )['type'] ?: 'application/octet-stream',
            ];
            $attachment_id = wp_insert_post( $args );
            if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                return;
            }
            update_post_meta( $attachment_id, '_keycdn_offloaded_url', $cdn_url );
        }

        // Insert manifest row as already confirmed.
        $row_id = $this->manifest->insert( (int) $attachment_id, 'full', $remote_path, '', $byte_size, '', '' );
        global $wpdb;
        $wpdb->update(
            Manifest::table_name(),
            [ 'state' => StateMachine::CONFIRMED, 'last_verified_at' => current_time( 'mysql', true ) ],
            [ 'id'    => $row_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }
}
