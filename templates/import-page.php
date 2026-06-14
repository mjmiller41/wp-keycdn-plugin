<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Import from CDN', 'wp-keycdn-offload' ); ?></h1>
    <p><?php esc_html_e( 'Scan your KeyCDN Push Zone for images that exist on the CDN but have not been imported into your WordPress Media Library.', 'wp-keycdn-offload' ); ?></p>

    <button type="button" id="keycdn-scan-cdn" class="button button-secondary">
        <?php esc_html_e( 'Scan CDN', 'wp-keycdn-offload' ); ?>
    </button>

    <div id="keycdn-scan-results" style="display:none;margin-top:20px;">

        <table class="widefat striped" style="max-width:480px;">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Files found on CDN', 'wp-keycdn-offload' ); ?></th>
                    <td id="keycdn-count-total">—</td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Already in Media Library', 'wp-keycdn-offload' ); ?></th>
                    <td id="keycdn-count-imported">—</td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Not yet imported', 'wp-keycdn-offload' ); ?></th>
                    <td id="keycdn-count-unimported"><strong>—</strong></td>
                </tr>
            </tbody>
        </table>

        <p id="keycdn-scan-truncated" style="display:none;">
            <em><?php esc_html_e( 'Scan limited to 500 files — additional files will still be imported.', 'wp-keycdn-offload' ); ?></em>
        </p>

        <p id="keycdn-sample-files" style="display:none;color:#555;"></p>

        <div id="keycdn-import-actions" style="margin-top:16px;display:none;">
            <button type="button" id="keycdn-start-import" class="button button-primary"></button>
            <p class="description" style="margin-top:6px;">
                <?php esc_html_e( 'Files are imported in the background via Action Scheduler and will appear in your Media Library as they are processed.', 'wp-keycdn-offload' ); ?>
            </p>
        </div>

        <div id="keycdn-import-queued" class="notice notice-success inline" style="display:none;margin-top:16px;padding:10px 14px;">
            <p>
                <?php esc_html_e( 'Import queued! Files will appear in your Media Library as they are processed.', 'wp-keycdn-offload' ); ?>
                &nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=keycdn-offload-status' ) ); ?>">
                    <?php esc_html_e( 'View Status Log →', 'wp-keycdn-offload' ); ?>
                </a>
            </p>
        </div>

        <div id="keycdn-scan-error" class="notice notice-error inline" style="display:none;margin-top:16px;padding:10px 14px;">
            <p id="keycdn-scan-error-msg"></p>
        </div>

    </div>
</div>
