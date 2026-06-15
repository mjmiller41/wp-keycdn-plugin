<?php
namespace KeyCDN\Offload\Upload;

use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BulkOffload {

    private UploadManager $manager;
    private Manifest      $manifest;

    public function __construct( UploadManager $manager, Manifest $manifest ) {
        $this->manager  = $manager;
        $this->manifest = $manifest;
    }

    public function start( int $batch_size = 50 ): void {
        update_option( 'keycdn_offload_bulk_status',    'running' );
        update_option( 'keycdn_offload_bulk_completed', 0 );
        update_option( 'keycdn_offload_bulk_failed',    0 );

        // Snapshot the confirmed count at start so get_progress() can report per-run progress.
        update_option( 'keycdn_offload_bulk_initial_confirmed', count( $this->get_offloaded_attachment_ids() ) );

        // Count total un-offloaded attachments for the progress display.
        $total = $this->count_unoffloaded();
        update_option( 'keycdn_offload_bulk_total', $total );

        $run_id = uniqid( 'bulk_', true );
        as_enqueue_async_action(
            'keycdn_bulk_page',
            [ 'page' => 1, 'batch_size' => $batch_size, 'run_id' => $run_id ],
            'keycdn-offload'
        );
    }

    /**
     * Action Scheduler callback for 'keycdn_bulk_page'.
     *
     * Uses NOT EXISTS against the manifest so the exclude set never has to be loaded into PHP
     * memory — avoids the O(n²) cost of a growing post__not_in on large libraries.
     */
    public function handle_page( int $page, int $batch_size, string $run_id ): void {
        global $wpdb;
        $table   = Manifest::table_name();
        $blog_id = (int) get_current_blog_id();
        $states  = "'" . implode( "','", [
            StateMachine::PENDING,
            StateMachine::UPLOADING,
            StateMachine::VERIFYING,
            StateMachine::CONFIRMED,
            StateMachine::LOCAL_REMOVED,
        ] ) . "'";

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND NOT EXISTS (
                   SELECT 1 FROM {$table} m
                   WHERE m.attachment_id = p.ID
                     AND m.blog_id = %d
                     AND m.state IN ({$states})
               )
             ORDER BY p.ID
             LIMIT %d",
            $blog_id,
            $batch_size
        ) );

        if ( empty( $ids ) ) {
            update_option( 'keycdn_offload_bulk_status', 'complete' );
            return;
        }

        foreach ( $ids as $attachment_id ) {
            $this->manager->enqueue_attachment( (int) $attachment_id );
        }

        as_enqueue_async_action(
            'keycdn_bulk_page',
            [ 'page' => $page + 1, 'batch_size' => $batch_size, 'run_id' => $run_id ],
            'keycdn-offload'
        );
    }

    public function get_progress(): array {
        $status = get_option( 'keycdn_offload_bulk_status', 'idle' );
        $total  = (int) get_option( 'keycdn_offload_bulk_total', 0 );

        // Skip DB queries when no bulk run is active or complete.
        if ( 'idle' === $status ) {
            return [
                'status'    => 'idle',
                'total'     => 0,
                'completed' => 0,
                'failed'    => 0,
                'percent'   => 0.0,
            ];
        }

        global $wpdb;
        $table   = Manifest::table_name();
        $blog_id = (int) get_current_blog_id();
        $initial = (int) get_option( 'keycdn_offload_bulk_initial_confirmed', 0 );

        $confirmed_in = "'" . implode( "','", [ StateMachine::CONFIRMED, StateMachine::LOCAL_REMOVED ] ) . "'";
        $failed_in    = "'" . implode( "','", [ StateMachine::FAILED, StateMachine::QUARANTINED ] ) . "'";

        $current_confirmed = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE state IN ({$confirmed_in}) AND blog_id = {$blog_id}"
        );
        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE state IN ({$failed_in}) AND blog_id = {$blog_id}"
        );

        $completed = max( 0, min( $current_confirmed - $initial, $total ) );
        $percent   = $total > 0 ? round( ( $completed / $total ) * 100, 1 ) : 0.0;

        return [
            'status'    => $status,
            'total'     => $total,
            'completed' => $completed,
            'failed'    => $failed,
            'percent'   => $percent,
        ];
    }

    private function count_unoffloaded(): int {
        $offloaded = $this->get_offloaded_attachment_ids();
        $query     = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'post__not_in'   => $offloaded,
        ] );
        return (int) $query->found_posts;
    }

    private function get_offloaded_attachment_ids(): array {
        global $wpdb;
        $table  = Manifest::table_name();
        $states = implode( "','", [ StateMachine::CONFIRMED, StateMachine::LOCAL_REMOVED ] );
        $rows   = $wpdb->get_col(
            "SELECT DISTINCT attachment_id FROM {$table} WHERE state IN ('{$states}') AND blog_id = " . (int) get_current_blog_id()
        );
        return array_map( 'intval', $rows );
    }

    /**
     * IDs to exclude from bulk page queries: anything already processing or done.
     * FAILED and QUARANTINED are intentionally omitted so bulk retries them.
     */
    private function get_in_progress_or_offloaded_ids(): array {
        global $wpdb;
        $table  = Manifest::table_name();
        $states = implode( "','", [
            StateMachine::PENDING,
            StateMachine::UPLOADING,
            StateMachine::VERIFYING,
            StateMachine::CONFIRMED,
            StateMachine::LOCAL_REMOVED,
        ] );
        $rows = $wpdb->get_col(
            "SELECT DISTINCT attachment_id FROM {$table} WHERE state IN ('{$states}') AND blog_id = " . (int) get_current_blog_id()
        );
        return array_map( 'intval', $rows );
    }
}
