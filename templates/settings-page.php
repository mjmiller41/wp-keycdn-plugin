<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/** @var bool $using_constants */
?>
<div class="wrap">
    <h1><?php esc_html_e( 'KeyCDN Offload — Settings', 'wp-keycdn-offload' ); ?></h1>

    <?php if ( $using_constants ) : ?>
        <div class="notice notice-info inline">
            <p>
                <?php esc_html_e( 'FTP credentials and Zone URL are defined as constants in wp-config.php and cannot be edited here.', 'wp-keycdn-offload' ); ?>
                <?php if ( $credentials->is_configured() ) : ?>
                    <span style="color:#46b450;font-weight:600;">&#10003; <?php esc_html_e( 'Credentials configured.', 'wp-keycdn-offload' ); ?></span>
                <?php else : ?>
                    <span style="color:#dc3232;font-weight:600;">&#10007; <?php esc_html_e( 'Credentials incomplete — check KEYCDN_ZONE_URL, KEYCDN_FTP_USER, and KEYCDN_FTP_PASS in wp-config.php.', 'wp-keycdn-offload' ); ?></span>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'keycdn_offload_settings' ); ?>

        <h2><?php esc_html_e( 'CDN Connection', 'wp-keycdn-offload' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="keycdn_zone_url"><?php esc_html_e( 'Zone URL', 'wp-keycdn-offload' ); ?></label></th>
                <td>
                    <input type="url" id="keycdn_zone_url" name="keycdn_offload_zone_url"
                        value="<?php echo esc_attr( ( defined( 'KEYCDN_ZONE_URL' ) && '' !== KEYCDN_ZONE_URL ) ? KEYCDN_ZONE_URL : get_option( 'keycdn_offload_zone_url', '' ) ); ?>"
                        class="regular-text" <?php echo ( defined( 'KEYCDN_ZONE_URL' ) && '' !== KEYCDN_ZONE_URL ) ? 'readonly' : ''; ?>>
                    <p class="description"><?php esc_html_e( 'e.g. https://yourzone-xyz.kxcdn.com', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="keycdn_ftp_host"><?php esc_html_e( 'FTP Host', 'wp-keycdn-offload' ); ?></label></th>
                <td><input type="text" id="keycdn_ftp_host" name="keycdn_offload_ftp_host"
                    value="<?php echo esc_attr( get_option( 'keycdn_offload_ftp_host', 'ftp.keycdn.com' ) ); ?>"
                    class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="keycdn_ftp_user"><?php esc_html_e( 'FTP Username', 'wp-keycdn-offload' ); ?></label></th>
                <td><input type="text" id="keycdn_ftp_user" name="keycdn_offload_ftp_user"
                    value="<?php echo esc_attr( ( defined( 'KEYCDN_FTP_USER' ) && '' !== KEYCDN_FTP_USER ) ? KEYCDN_FTP_USER : get_option( 'keycdn_offload_ftp_user', '' ) ); ?>"
                    class="regular-text" <?php echo ( defined( 'KEYCDN_FTP_USER' ) && '' !== KEYCDN_FTP_USER ) ? 'readonly' : ''; ?> autocomplete="off"></td>
            </tr>
            <?php if ( ! ( defined( 'KEYCDN_FTP_PASS' ) && '' !== KEYCDN_FTP_PASS ) ) : ?>
            <tr>
                <th scope="row"><label for="keycdn_ftp_pass_new"><?php esc_html_e( 'FTP Password', 'wp-keycdn-offload' ); ?></label></th>
                <td>
                    <input type="password" id="keycdn_ftp_pass_new" name="keycdn_offload_ftp_pass_new"
                        value="" class="regular-text" autocomplete="new-password">
                    <p class="description"><?php esc_html_e( 'Leave blank to keep the existing password.', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Test Connection', 'wp-keycdn-offload' ); ?></th>
                <td>
                    <button type="button" id="keycdn-test-connection" class="button">
                        <?php esc_html_e( 'Test Connection', 'wp-keycdn-offload' ); ?>
                    </button>
                    <div id="keycdn-test-result" class="notice notice-inline" style="display:none;margin-top:8px;padding:6px 12px;"></div>
                    <p class="description"><?php esc_html_e( 'Save your settings first, then click to verify the FTP connection.', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="keycdn_zone_subdir"><?php esc_html_e( 'Zone Subdirectory', 'wp-keycdn-offload' ); ?></label></th>
                <td><input type="text" id="keycdn_zone_subdir" name="keycdn_offload_zone_subdir"
                    value="<?php echo esc_attr( get_option( 'keycdn_offload_zone_subdir', '' ) ); ?>"
                    class="regular-text" placeholder="wp-content/uploads">
                    <p class="description"><?php esc_html_e( 'Optional path prefix on the CDN zone. Leave blank to mirror local upload paths.', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Upload Behaviour', 'wp-keycdn-offload' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Auto-Offload on Upload', 'wp-keycdn-offload' ); ?></th>
                <td><label>
                    <input type="checkbox" name="keycdn_offload_auto_offload" value="1" <?php checked( get_option( 'keycdn_offload_auto_offload', true ) ); ?>>
                    <?php esc_html_e( 'Automatically queue files for CDN offload when uploaded to the Media Library', 'wp-keycdn-offload' ); ?>
                </label></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Remove Local Files', 'wp-keycdn-offload' ); ?></th>
                <td><label>
                    <input type="checkbox" name="keycdn_offload_remove_local" value="1" <?php checked( get_option( 'keycdn_offload_remove_local', false ) ); ?>>
                    <?php esc_html_e( 'Move local files to quarantine after confirmed CDN upload (recommended: leave off until you have verified the CDN is working)', 'wp-keycdn-offload' ); ?>
                </label></td>
            </tr>
            <tr>
                <th scope="row"><label for="keycdn_large_file_mb"><?php esc_html_e( 'Large File Threshold (MB)', 'wp-keycdn-offload' ); ?></label></th>
                <td><input type="number" id="keycdn_large_file_mb" name="keycdn_offload_large_file_mb" min="1" max="5000"
                    value="<?php echo esc_attr( get_option( 'keycdn_offload_large_file_mb', 50 ) ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Files above this size are streamed via ftp_fput() to avoid memory limits.', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="keycdn_trash_ttl"><?php esc_html_e( 'Quarantine TTL (days)', 'wp-keycdn-offload' ); ?></label></th>
                <td><input type="number" id="keycdn_trash_ttl" name="keycdn_offload_trash_ttl_days" min="1" max="365"
                    value="<?php echo esc_attr( get_option( 'keycdn_offload_trash_ttl_days', 30 ) ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Days to keep quarantined local files before permanent deletion.', 'wp-keycdn-offload' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'WooCommerce Compatibility', 'wp-keycdn-offload' ); ?></th>
                <td><label>
                    <input type="checkbox" name="keycdn_offload_woo_compat" value="1" <?php checked( get_option( 'keycdn_offload_woo_compat', true ) ); ?>>
                    <?php esc_html_e( 'Rewrite WooCommerce product image URLs', 'wp-keycdn-offload' ); ?>
                </label></td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
