<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'cdn_offload_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

$options = [
    'keycdn_offload_version',
    'keycdn_offload_zone_url',
    'keycdn_offload_ftp_host',
    'keycdn_offload_ftp_user',
    'keycdn_offload_ftp_pass_enc',
    'keycdn_offload_auto_offload',
    'keycdn_offload_remove_local',
    'keycdn_offload_trash_ttl_days',
    'keycdn_offload_large_file_mb',
    'keycdn_offload_resumable',
    'keycdn_offload_woo_compat',
    'keycdn_offload_zone_subdir',
    'keycdn_offload_bulk_status',
    'keycdn_offload_bulk_total',
    'keycdn_offload_bulk_completed',
    'keycdn_offload_bulk_failed',
    'keycdn_offload_reconcile_interval',
];
foreach ( $options as $option ) {
    delete_option( $option );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( '', [], 'keycdn-offload' );
}
