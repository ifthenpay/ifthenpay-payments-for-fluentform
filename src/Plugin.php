<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm;

defined( 'ABSPATH' ) || exit;

use ifthenpay\FluentForm\Frontend\IfthenpayInline;
use ifthenpay\FluentForm\Integration\IfthenpayIntegration;
use ifthenpay\FluentForm\Payment\IfthenpayHandler;

/**
 * Main plugin bootstrap.
 *
 * Checks FluentForm dependencies, then wires the two extension points:
 *   - IfthenpayIntegration (IntegrationManagerController) — feed management, global settings
 *   - IfthenpayHandler     (BasePaymentMethod)             — payment method + processor
 */
final class Plugin {

	public static function boot(): void {
		$dependenciesMet = defined( 'FLUENTFORM' )
			&& class_exists( 'FluentForm\App\Modules\Payments\PaymentMethods\BasePaymentMethod' )
			&& class_exists( 'FluentForm\App\Http\Controllers\IntegrationManagerController' );

		if ( ! $dependenciesMet ) {
			return;
		}


		add_action(
			'init',
			static function (): void {
				new IfthenpayIntegration( null );
			},
			9
		);


		( new IfthenpayHandler() )->init();

		( new IfthenpayInline() )->init();
	}

	private function __construct() {}
}
