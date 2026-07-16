<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Payment;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Modules\Payments\PaymentMethods\BasePaymentMethod;
use ifthenpay\FluentForm\Storage\IfthenpaySettings;

/**
 * Registers "ifthenpay" as a payment method in the Fluent Forms form builder.
 *
 * Credential management and per-form feed settings live in ifthenpayIntegration
 * (IntegrationManagerController). This class only hooks the payment method into
 * Fluent Forms' Payment tab and instantiates the processor.
 */
class IfthenpayHandler extends BasePaymentMethod {

	public function __construct() {
		parent::__construct( 'ifthenpay' );
	}

	public function init(): void {

		add_filter( 'fluentform/payment_method_settings_validation_ifthenpay', array( $this, 'validateSettings' ), 10, 2 );
		add_filter( 'fluentform/payment_method_settings_save_ifthenpay', array( $this, 'sanitizeSettings' ), 10, 1 );


		if ( ! IfthenpaySettings::isEnabled() ) {
			return;
		}

		add_filter( 'fluentform/available_payment_methods', array( $this, 'pushPaymentMethodToForm' ), 10, 1 );

		( new IfthenpayProcessor() )->init();
	}



	/**
	 * Fields shown under Global Settings → Payment Settings → ifthenpay tab.
	 * Credential settings live in the Integration panel; this page only shows
	 * a status summary and the webhook URL.
	 *
	 * @return array<string, mixed>
	 */
	public function getGlobalFields(): array {
		$webhookUrl = home_url( '/' . IFTP_FF_CALLBACK_SLUG );

		$integrationUrl = esc_url( admin_url( 'admin.php?page=fluent_Form_settings#general-ifthenpay-settings' ) );

		$statusHtml = IfthenpaySettings::isConnected()
			? '<span style="color:#46b450">&#10003; ' . esc_html__( 'Backoffice Key connected', 'ifthenpay-payments-for-fluentform' ) . '</span>'
			: '<span style="color:#dc3232">&#10007; ' . esc_html__( 'Backoffice Key not connected', 'ifthenpay-payments-for-fluentform' ) . '</span>'
			. ' &mdash; <a href="' . $integrationUrl . '">'
			. esc_html__( 'Configure in Integrations', 'ifthenpay-payments-for-fluentform' )
			. '</a>';

		return array(
			'title'    => __( 'ifthenpay | Pay by Link', 'ifthenpay-payments-for-fluentform' ),
			'logo'     => IFTP_FF_URL . 'assets/img/ifthenpay.svg',
			'type'     => 'general',
			'class'    => '',
			'settings' => array(
				array(
					'key'   => '_status',
					'label' => __( 'Connection Status', 'ifthenpay-payments-for-fluentform' ),
					'type'  => 'html',
					'value' => $statusHtml,
				),
				array(
					'key'   => '_webhook_url',
					'label' => __( 'Webhook URL', 'ifthenpay-payments-for-fluentform' ),
					'type'  => 'html',
					'value' => '<code style="user-select:all;word-break:break-all">' . esc_html( $webhookUrl ) . '</code>'
						. '<p style="margin-top:4px;font-size:12px;color:#888">'
						. esc_html__( 'Provide this URL to ifthenpay support to enable server-to-server payment confirmation.', 'ifthenpay-payments-for-fluentform' )
						. '</p>',
				),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function getGlobalSettings(): array {
		return array(
			'is_connected' => IfthenpaySettings::isConnected() ? 'yes' : 'no',
		);
	}



	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed>  $settings
	 * @return array<string, string>
	 */
	public function validateSettings( array $errors, array $settings ): array {
		return $errors;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function sanitizeSettings( array $settings ): array {
		return $settings;
	}



	/**
	 * @param array<string, mixed> $methods
	 * @return array<string, mixed>
	 */
	public function pushPaymentMethodToForm( array $methods ): array {
		$methods['ifthenpay'] = array(
			'title'        => __( 'ifthenpay | Pay by Link', 'ifthenpay-payments-for-fluentform' ),
			'enabled'      => 'yes',
			'method_value' => 'ifthenpay',
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => __( 'Pay with ifthenpay', 'ifthenpay-payments-for-fluentform' ),
					'label'    => __( 'Method Label', 'ifthenpay-payments-for-fluentform' ),
				),
			),
		);

		return $methods;
	}
}
