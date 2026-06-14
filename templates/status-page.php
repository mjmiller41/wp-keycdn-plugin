<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/** @var array $counts */
$total = array_sum( $counts );
$state_labels = [
    'pending'       => __( 'Pending', 'wp-keycdn-offload' ),
    'uploading'     => __( 'Uploading', 'wp-keycdn-offload' ),
    'verifying'     => __( 'Verifying', 'wp-keycdn-offload' ),
    'confirmed'     => __( 'Confirmed on CDN', 'wp-keycdn-offload' ),
    'local_removed' => __( 'Local File Removed', 'wp-keycdn-offload' ),
    'quarantined'   => __( 'Quarantined', 'wp-keycdn-offload' ),
    'failed'        => __( 'Failed', 'wp-keycdn-offload' ),
];
?>
<div class="wrap">
    <h1><?php esc_html_e( 'KeyCDN Offload — Status Log', 'wp-keycdn-offload' ); ?></h1>
    <p><?php printf( esc_html__( 'Total tracked file variants: %d', 'wp-keycdn-offload' ), $total ); ?></p>

    <table class="widefat striped" style="max-width:500px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'State', 'wp-keycdn-offload' ); ?></th>
                <th><?php esc_html_e( 'Count', 'wp-keycdn-offload' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $state_labels as $state => $label ) : ?>
            <tr>
                <td><?php echo esc_html( $label ); ?></td>
                <td><?php echo esc_html( $counts[ $state ] ?? 0 ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
