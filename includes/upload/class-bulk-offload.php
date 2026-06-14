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
     * Always queries from offset 0 and excludes already-queued or already-offloaded IDs.
     * Using paged+offset with a growing post__not_in causes attachments to be skipped
     * as the exclude list grows and the result set shrinks between pages.
     */
    public function handle_page( int $page, int $batch_size, string $run_id ): void {
        $exclude_ids = $this->get_in_progress_or_offloaded_ids();
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => $batch_size,
            'post__not_in'   => $exclude_ids,
        ] );

        if ( empty( $query->posts ) ) {
            update_option( 'keycdn_offload_bulk_status', 'complete' );
            return;
        }

        foreach ( $query->posts as $attachment_id ) {
            $this->manager->enqueue_attachment( (int) $attachment_id );
        }

        as_enqueue_async_action(
            'keycdn_bulk_page',
            [ 'page' => $page + 1, 'batch_size' => $batch_size, 'run_id' => $run_id ],
            'keycdn-offload'
        );
    }

    public function get_progress(): array {
        return [
            'status'    => get_option( 'keycdn_offload_bulk_status', 'idle' ),
            'total'     => (int) get_option( 'keycdn_offload_bulk_total', 0 ),
            'completed' => (int) get_option( 'keycdn_offload_bulk_completed', 0 ),
            'failed'    => (int) get_option( 'keycdn_offload_bulk_failed', 0 ),
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
