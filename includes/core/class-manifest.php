<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Manifest {

    private StateMachine $state_machine;

    public function __construct( StateMachine $state_machine ) {
        $this->state_machine = $state_machine;
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'cdn_offload_log';
    }

    public function insert( int $attachment_id, string $size_slug, string $remote_path, string $local_path, int $byte_size, string $md5, string $sha1 ): int {
        global $wpdb;
        $wpdb->insert(
            self::table_name(),
            [
                'attachment_id' => $attachment_id,
                'blog_id'       => get_current_blog_id(),
                'size_slug'     => $size_slug,
                'remote_path'   => $remote_path,
                'local_path'    => $local_path,
                'byte_size'     => $byte_size,
                'md5_checksum'  => $md5,
                'sha1_checksum' => $sha1,
                'state'         => StateMachine::PENDING,
                'retry_count'   => 0,
                'created_at'    => current_time( 'mysql', true ),
                'updated_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public function get_by_attachment( int $attachment_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE attachment_id = %d AND blog_id = %d',
                $attachment_id,
                get_current_blog_id()
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_by_id( int $row_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $row_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function get_by_state( string $state, int $limit = 100, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE state = %s AND blog_id = %d LIMIT %d OFFSET %d',
                $state,
                get_current_blog_id(),
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public function transition_state( int $row_id, string $new_state ): void {
        global $wpdb;
        $row = $this->get_by_id( $row_id );
        if ( ! $row ) {
            throw new \InvalidArgumentException( "Manifest row {$row_id} not found." );
        }
        $this->state_machine->transition( $row['state'], $new_state );
        $wpdb->update(
            self::table_name(),
            [ 'state' => $new_state, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id'    => $row_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public function increment_retry( int $row_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table_name() . ' SET retry_count = retry_count + 1, updated_at = %s WHERE id = %d',
                current_time( 'mysql', true ),
                $row_id
            )
        );
    }

    public function set_quarantine( int $row_id, string $quarantine_path ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'quarantine_path' => $quarantine_path,
                'quarantined_at'  => current_time( 'mysql', true ),
                'state'           => StateMachine::QUARANTINED,
                'updated_at'      => current_time( 'mysql', true ),
            ],
            [ 'id' => $row_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public function set_verified( int $row_id ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [ 'last_verified_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $row_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public function delete_by_attachment( int $attachment_id ): void {
        global $wpdb;
        $wpdb->delete(
            self::table_name(),
            [ 'attachment_id' => $attachment_id, 'blog_id' => get_current_blog_id() ],
            [ '%d', '%d' ]
        );
    }

    public function get_pending_reconcile( int $hours = 24 ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() .
                ' WHERE state = %s AND blog_id = %d AND (last_verified_at IS NULL OR last_verified_at < %s) LIMIT 200',
                StateMachine::CONFIRMED,
                get_current_blog_id(),
                $cutoff
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_state_counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT state, COUNT(*) as cnt FROM ' . self::table_name() . ' WHERE blog_id = %d GROUP BY state',
                get_current_blog_id()
            ),
            ARRAY_A
        );
        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row['state'] ] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Atomically insert or update a manifest row in the CONFIRMED state.
     * ON DUPLICATE KEY UPDATE handles concurrent scanner jobs racing on the same file.
     */
    public function upsert_confirmed( int $attachment_id, string $size_slug, string $remote_path, int $byte_size ): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::table_name() . '
                 (attachment_id, blog_id, size_slug, remote_path, local_path, byte_size,
                  md5_checksum, sha1_checksum, state, retry_count, created_at, updated_at, last_verified_at)
                 VALUES (%d, %d, %s, %s, %s, %d, %s, %s, %s, 0, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                   state            = VALUES(state),
                   remote_path      = VALUES(remote_path),
                   byte_size        = VALUES(byte_size),
                   last_verified_at = VALUES(last_verified_at),
                   updated_at       = VALUES(updated_at)',
                $attachment_id,
                get_current_blog_id(),
                $size_slug,
                $remote_path,
                '',
                $byte_size,
                '',
                '',
                StateMachine::CONFIRMED,
                $now,
                $now,
                $now
            )
        );
    }

    public function update_file_metadata( int $row_id, string $remote_path, string $local_path, int $byte_size, string $md5, string $sha1 ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'remote_path'   => $remote_path,
                'local_path'    => $local_path,
                'byte_size'     => $byte_size,
                'md5_checksum'  => $md5,
                'sha1_checksum' => $sha1,
                'updated_at'    => current_time( 'mysql', true ),
            ],
            [ 'id' => $row_id ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public function get_all_remote_paths(): array {
        global $wpdb;
        return $wpdb->get_col(
            $wpdb->prepare(
                'SELECT remote_path FROM ' . self::table_name() . ' WHERE blog_id = %d',
                get_current_blog_id()
            )
        ) ?: [];
    }

    /**
     * Create the manifest table. Safe to call multiple times (uses IF NOT EXISTS).
     */
    public static function create_table(): void {
        global $wpdb;
        $table      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            size_slug VARCHAR(64) NOT NULL,
            remote_path VARCHAR(1024) NOT NULL,
            local_path VARCHAR(1024) DEFAULT NULL,
            byte_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            md5_checksum CHAR(32) DEFAULT NULL,
            sha1_checksum CHAR(40) DEFAULT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'pending',
            retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            quarantine_path VARCHAR(1024) DEFAULT NULL,
            quarantined_at DATETIME DEFAULT NULL,
            last_verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_attachment_size (attachment_id, blog_id, size_slug),
            KEY idx_state (state),
            KEY idx_blog_attachment (blog_id, attachment_id),
            KEY idx_last_verified (last_verified_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
