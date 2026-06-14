<?php
namespace KeyCDN\Offload;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {

    public static function deactivate(): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }
        $jobs = [
            'keycdn_reconcile_manifest',
            'keycdn_purge_trash',
        ];
        foreach ( $jobs as $job ) {
            as_unschedule_all_actions( $job, [], 'keycdn-offload' );
        }
    }
}
