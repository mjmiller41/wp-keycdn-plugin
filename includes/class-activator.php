<?php
namespace KeyCDN\Offload;

use KeyCDN\Offload\Core\Manifest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    public static function activate(): void {
        Manifest::create_table();
        self::create_trash_directory();
        self::set_default_options();
        self::schedule_recurring_jobs();
        update_option( 'keycdn_offload_version', KEYCDN_OFFLOAD_VERSION );
        set_transient( 'keycdn_offload_activated', true, 60 );
    }

    private static function create_trash_directory(): void {
        $dir = KEYCDN_OFFLOAD_TRASH_DIR;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Prevent direct browsing.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'Deny from all' );
        }
    }

    private static function set_default_options(): void {
        $defaults = [
            'keycdn_offload_ftp_host'           => 'ftp.keycdn.com',
            'keycdn_offload_auto_offload'        => true,
            'keycdn_offload_remove_local'        => false,
            'keycdn_offload_trash_ttl_days'      => 30,
            'keycdn_offload_resumable'           => false,
            'keycdn_offload_woo_compat'          => true,
            'keycdn_offload_bulk_status'         => 'idle',
            'keycdn_offload_bulk_total'          => 0,
            'keycdn_offload_bulk_completed'      => 0,
            'keycdn_offload_bulk_failed'         => 0,
            'keycdn_offload_reconcile_interval'  => DAY_IN_SECONDS,
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value );
            }
        }
    }

    private static function schedule_recurring_jobs(): void {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }
        if ( ! as_next_scheduled_action( 'keycdn_reconcile_manifest' ) ) {
            as_schedule_recurring_action(
                time() + DAY_IN_SECONDS,
                (int) get_option( 'keycdn_offload_reconcile_interval', DAY_IN_SECONDS ),
                'keycdn_reconcile_manifest',
                [],
                'keycdn-offload'
            );
        }
        if ( ! as_next_scheduled_action( 'keycdn_purge_trash' ) ) {
            as_schedule_recurring_action(
                time() + DAY_IN_SECONDS,
                DAY_IN_SECONDS,
                'keycdn_purge_trash',
                [],
                'keycdn-offload'
            );
        }
    }
}
