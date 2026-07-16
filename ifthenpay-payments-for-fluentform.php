<?php
/**
 * Plugin Name:       ifthenpay | Payments for Fluent Forms
 * Plugin URI:        https://ifthenpay.com
 * Description:       ifthenpay Pay by Link integration for Fluent Forms.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-fluentform
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_FF_VERSION', '1.0.0' );
define( 'IFTP_FF_FILE', __FILE__ );
define( 'IFTP_FF_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_FF_URL', plugin_dir_url( __FILE__ ) );


define( 'IFTP_FF_CALLBACK_SLUG', 'iftp_ff_' . str_replace( '.', '_', IFTP_FF_VERSION ) );


spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'ifthenpay\\FluentForm\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = IFTP_FF_DIR . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'ifthenpay\\FluentForm\\Plugin', 'boot' ), 11 );

register_activation_hook(
	__FILE__,
	static function (): void {
		add_rewrite_rule( IFTP_FF_CALLBACK_SLUG . '/?$', 'index.php?' . IFTP_FF_CALLBACK_SLUG . '=1', 'top' );
		flush_rewrite_rules();
	}
);
