<?php
/**
 * Plugin Name:       WP KeyCDN Media Offload
 * Plugin URI:        https://github.com/mjmiller41/wp-keycdn-plugin
 * Description:       Offloads WordPress media to a KeyCDN Push Zone via FTPS with async background processing.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Michael Miller
 * Author URI:        https://github.com/mjmiller41
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-keycdn-offload
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KEYCDN_OFFLOAD_VERSION',   '0.1.0' );
define( 'KEYCDN_OFFLOAD_FILE',      __FILE__ );
define( 'KEYCDN_OFFLOAD_PATH',      plugin_dir_path( __FILE__ ) );
define( 'KEYCDN_OFFLOAD_URL',       plugin_dir_url( __FILE__ ) );
define( 'KEYCDN_OFFLOAD_TRASH_DIR', WP_CONTENT_DIR . '/uploads/_cdn_trash' );

// Autoloader — use Composer if available, else hand-rolled PSR-4.
if ( file_exists( KEYCDN_OFFLOAD_PATH . 'vendor/autoload.php' ) ) {
    require_once KEYCDN_OFFLOAD_PATH . 'vendor/autoload.php';
} else {
    spl_autoload_register( function ( string $class ) {
        $prefix = 'KeyCDN\\Offload\\';
        if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $parts    = explode( '\\', $relative );
        $class_name = array_pop( $parts );
        $kebab      = strtolower( preg_replace( [ '/([A-Z]+)([A-Z][a-z])/', '/([a-z])([A-Z])/' ], '$1-$2', $class_name ) );
        $filename   = 'class-' . $kebab . '.php';
        $subdir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
        $path     = KEYCDN_OFFLOAD_PATH . 'includes/' . $subdir . $filename;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    } );
}

register_activation_hook( __FILE__, [ 'KeyCDN\\Offload\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'KeyCDN\\Offload\\Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    $plugin = new KeyCDN\Offload\Plugin();
    $plugin->run();
}, 0 );
