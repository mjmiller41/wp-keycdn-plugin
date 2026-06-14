<?php
namespace KeyCDN\Offload\CLI;

use KeyCDN\Offload\Upload\BulkOffload;
use KeyCDN\Offload\Cleanup\ReconcileJob;
use KeyCDN\Offload\Cleanup\TrashManager;
use KeyCDN\Offload\Core\Manifest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage KeyCDN media offload.
 *
 * @package wp-keycdn-offload
 */
class CliCommand extends \WP_CLI_Command {

    private BulkOffload  $bulk;
    private ReconcileJob $reconcile;
    private TrashManager $trash;
    private Manifest     $manifest;

    public function __construct( BulkOffload $bulk, ReconcileJob $reconcile, TrashManager $trash, Manifest $manifest ) {
        $this->bulk      = $bulk;
        $this->reconcile = $reconcile;
        $this->trash     = $trash;
        $this->manifest  = $manifest;
    }

    /**
     * Offload all un-offloaded media to KeyCDN.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Attachments per batch. Default: 50.
     *
     * ## EXAMPLES
     *
     *     wp keycdn-offload bulk-offload
     *     wp keycdn-offload bulk-offload --batch-size=100
     *
     * @subcommand bulk-offload
     */
    public function bulk_offload( array $args, array $assoc_args ): void {
        $batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
        $this->bulk->start( $batch_size );
        \WP_CLI::success( "Bulk offload queued. Run 'wp keycdn-offload status' to monitor progress." );
    }

    /**
     * Run the manifest reconciliation check immediately.
     *
     * ## EXAMPLES
     *
     *     wp keycdn-offload reconcile
     */
    public function reconcile(): void {
        \WP_CLI::log( 'Running reconciliation...' );
        $this->reconcile->handle();
        \WP_CLI::success( 'Reconciliation complete.' );
    }

    /**
     * Show current offload status and state counts.
     *
     * ## EXAMPLES
     *
     *     wp keycdn-offload status
     */
    public function status(): void {
        $counts = $this->manifest->get_state_counts();
        $total  = array_sum( $counts );
        \WP_CLI::log( sprintf( 'Total tracked files: %d', $total ) );
        $rows = [];
        foreach ( $counts as $state => $count ) {
            $rows[] = [ 'State' => $state, 'Count' => $count ];
        }
        \WP_CLI\Utils\format_items( 'table', $rows, [ 'State', 'Count' ] );
    }

    /**
     * Purge expired files from the quarantine trash directory.
     *
     * ## EXAMPLES
     *
     *     wp keycdn-offload purge-trash
     *
     * @subcommand purge-trash
     */
    public function purge_trash(): void {
        $this->trash->purge_expired();
        \WP_CLI::success( 'Expired quarantine files purged.' );
    }
}
