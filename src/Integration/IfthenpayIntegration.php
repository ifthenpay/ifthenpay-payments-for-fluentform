<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Integration;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use ifthenpay\FluentForm\Api\IfthenpayApiClient;
use ifthenpay\FluentForm\Mail\IfthenpayEmailHelper;
use ifthenpay\FluentForm\Storage\IfthenpaySettings;

/**
 * Registers ifthenpay as an Integration in the Fluent Forms Add-ons panel.
 *
 * Responsibilities:
 *   - Global add-on card (disabled by default — user enables via the toggle)
 *   - Backoffice Key connect / disconnect
 *   - Global settings page: connection status + webhook URL
 *   - Per-form feed: gateway_key, methods_config, default_method, description, expire_days
 *
 * Payment processing itself is handled by ifthenpayHandler + ifthenpayProcessor.
 */
class IfthenpayIntegration extends IntegrationManagerController {

	/** Shared with IfthenpayInline so the admin/frontend reuse one catalog fetch. */
	private const METHODS_TRANSIENT = 'iftp_ff_available_methods';

	/**
	 * Per-request memo for the gateway-key rows. null = not yet fetched this
	 * request. Ensures /gateway/get is hit once per request, never cached to DB.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $gatewayKeysMemo = null;

	/**
	 * Per-request memo for the visible methods catalog (keyed by entity).
	 * null = not yet fetched this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $availableMethodsMemo = null;

	public function __construct( $app ) {
		parent::__construct(
			$app,
			'ifthenpay | Pay by Link',
			'ifthenpay',
			'iftp_ff_global_settings',
			'ifthenpay_feeds',
			9
		);

		$this->logo        = IFTP_FF_URL . 'assets/img/ifthenpay.svg';
		$this->category    = 'payment';
		$this->description = __( 'Accept payments via ifthenpay Pay by Link directly through your Fluent Forms.', 'ifthenpay-payments-for-fluentform' );

		$this->registerAdminHooks();


		add_action( 'wp_ajax_iftp_ff_connect_backoffice', array( $this, 'ajaxConnect' ) );
		add_action( 'wp_ajax_iftp_ff_disconnect_backoffice', array( $this, 'ajaxDisconnect' ) );
		add_action( 'wp_ajax_iftp_ff_get_methods', array( $this, 'ajaxGetMethods' ) );
		add_action( 'wp_ajax_iftp_ff_activate_method', array( $this, 'ajaxActivateMethod' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );


		add_filter( 'fluentform/save_integration_settings_' . $this->integrationKey, array( $this, 'enforceSingleFeed' ), 20, 2 );


		add_rewrite_rule( IFTP_FF_CALLBACK_SLUG . '/?$', 'index.php?' . IFTP_FF_CALLBACK_SLUG . '=1', 'top' );
		add_filter( 'query_vars', array( $this, 'registerCallbackQueryVar' ) );
	}



	public function isConfigured(): bool {
		return IfthenpaySettings::isConnected();
	}

	/**
	 * @param array<string, mixed> $integrations
	 * @param int                  $formId
	 * @return array<string, mixed>
	 */
	public function pushIntegration( $integrations, $formId ): array {
		$integrations[ $this->integrationKey ] = array(
			'title'                 => __( 'ifthenpay | Pay by Link', 'ifthenpay-payments-for-fluentform' ),
			'logo'                  => $this->logo,
			'is_active'             => $this->isConfigured(),
			'configure_title'       => __( 'Backoffice Key not connected', 'ifthenpay-payments-for-fluentform' ),
			'global_configure_url'  => admin_url( 'admin.php?page=fluent_Form_settings#general-ifthenpay-settings' ),
			'configure_message'     => __( 'Please connect your ifthenpay Backoffice Key in the integration settings before adding a feed to this form.', 'ifthenpay-payments-for-fluentform' ),
			'configure_button_text' => __( 'Configure ifthenpay', 'ifthenpay-payments-for-fluentform' ),
		);

		return $integrations;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param int                  $formId
	 * @return array<string, mixed>
	 */
	public function getIntegrationDefaults( $settings, $formId ): array {
		return array(
			'name'           => '',
			'gateway_key'    => '',
			'methods_config' => '',
			'default_method' => '',
			'description'    => '',
			'expire_days'    => '3',
			'enabled'        => true,
			'conditionals'   => array(
				'status'     => false,
				'type'       => 1,
				'conditions' => array(),
			),
		);
	}

	/**
	 * Per-form feed settings fields.
	 * NOTE: Use 'component' (not 'type') -- Fluent Forms Vue picks the template by 'component'.
	 * NOTE: 'select' options must be ['value' => 'label'] associative (not [{label, value}] arrays).
	 *
	 * @param array<string, mixed> $settings
	 * @param int                  $formId
	 * @return array<string, mixed>
	 */
	public function getSettingsFields( $settings, $formId ): array {
		return array(
			'fields'              => array(
				array(
					'key'         => 'name',
					'label'       => __( 'Feed Name', 'ifthenpay-payments-for-fluentform' ),
					'placeholder' => __( 'Feed Name', 'ifthenpay-payments-for-fluentform' ),
					'required'    => true,
					'tips'        => __( 'A descriptive name for this payment feed.', 'ifthenpay-payments-for-fluentform' ),
					'component'   => 'text',
				),
				array(
					'key'       => 'gateway_key',
					'label'     => __( 'Gateway Key', 'ifthenpay-payments-for-fluentform' ),
					'required'  => true,
					'tips'      => __( 'Select the ifthenpay Pay by Link gateway for this form. The list is populated after you connect your Backoffice Key.', 'ifthenpay-payments-for-fluentform' ),
					'component' => 'select',
					'options'   => $this->buildGatewayKeyOptions(),
				),
				array(
					'key'         => 'methods_config',
					'label'       => __( 'Payment Methods', 'ifthenpay-payments-for-fluentform' ),
					'placeholder' => 'iftp_ff_methods_config',
					'tips'        => __( 'Enable the payment methods available on this gateway. Select a gateway key first to load them.', 'ifthenpay-payments-for-fluentform' ),
					'component'   => 'text',
				),
				array(
					'key'         => 'default_method',
					'label'       => __( 'Default Payment Method', 'ifthenpay-payments-for-fluentform' ),
					'placeholder' => 'iftp_ff_default_method',
					'tips'        => __( 'Pre-select a method on the ifthenpay hosted page. Enabled methods in the table above are selectable; others are shown grayed out.', 'ifthenpay-payments-for-fluentform' ),
					'component'   => 'text',
				),
				array(
					'key'         => 'description',
					'label'       => __( 'Payment Description', 'ifthenpay-payments-for-fluentform' ),
					'placeholder' => __( 'e.g. Order #{id}', 'ifthenpay-payments-for-fluentform' ),
					'tips'        => __( 'Description shown on the ifthenpay hosted payment page. Use {id} for the submission ID.', 'ifthenpay-payments-for-fluentform' ),
					'component'   => 'text',
				),
				array(
					'key'         => 'expire_days',
					'label'       => __( 'Expiry Days', 'ifthenpay-payments-for-fluentform' ),
					'placeholder' => '3',
					'tips'        => __( 'Days before the payment link expires (0-9). Relevant for Multibanco references. Leave blank to use the gateway default.', 'ifthenpay-payments-for-fluentform' ),
					'component'   => 'text',
				),
				array(
					'key'            => 'enabled',
					'label'          => __( 'Enable Feed', 'ifthenpay-payments-for-fluentform' ),
					'component'      => 'checkbox-single',
					'checkbox_label' => __( 'Enable this feed', 'ifthenpay-payments-for-fluentform' ),
				),
				array(
					'key'       => 'conditionals',
					'label'     => __( 'Conditional Logics', 'ifthenpay-payments-for-fluentform' ),
					'tips'      => __( 'Run this feed conditionally based on form field values.', 'ifthenpay-payments-for-fluentform' ),
					'component' => 'conditional_block',
					'sub_title' => __( 'Enable conditional logic for this feed', 'ifthenpay-payments-for-fluentform' ),
				),
			),
			'button_require_list' => false,
			'integration_title'   => $this->title,
		);
	}

	/**
	 * @param mixed $list
	 * @param mixed $listId
	 * @param int   $formId
	 * @return array<mixed>
	 */
	public function getMergeFields( $list, $listId, $formId ): array {
		return array();
	}

	/**
	 * Payment processing is handled by ifthenpayProcessor (BaseProcessor pipeline).
	 *
	 * @param array<string, mixed> $feed
	 * @param array<string, mixed> $formData
	 * @param object               $entry
	 * @param object               $form
	 */
	public function notify( $feed, $formData, $entry, $form ): void {}



	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function getGlobalSettings( $settings ): array {
		$isConnected = IfthenpaySettings::isConnected();

		return array(
			'backoffice_key' => $isConnected ? IfthenpaySettings::getbackoffice_key() : '',
			'status'         => $isConnected,
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public function getGlobalFields( $fields ): array {
		$webhookUrl = $this->buildWebhookUrl();

		return array(
			'logo'             => $this->logo,
			'menu_title'       => __( 'ifthenpay | Pay by Link Settings', 'ifthenpay-payments-for-fluentform' ),
			'menu_description' => __( 'Connect your ifthenpay account to start accepting payments via Pay by Link.', 'ifthenpay-payments-for-fluentform' ),
			'valid_message'    => __( 'Your Backoffice Key is connected.', 'ifthenpay-payments-for-fluentform' ),
			'invalid_message'  => __( 'Your Backoffice Key is not connected.', 'ifthenpay-payments-for-fluentform' ),
			'save_button_text' => __( 'Connect', 'ifthenpay-payments-for-fluentform' ),
			'fields'           => array(
				'backoffice_key' => array(
					'type'       => 'text',
					'label_tips' => __( 'Enter your ifthenpay Backoffice Key in the format XXXX-XXXX-XXXX-XXXX.<br>You can find it in your ifthenpay backoffice account.', 'ifthenpay-payments-for-fluentform' ),
					'label'      => __( 'Backoffice Key', 'ifthenpay-payments-for-fluentform' ),
				),
			),
			'hide_on_valid'    => true,
			'discard_settings' => array(
				'section_description' => __( 'Your ifthenpay Backoffice Key is active. Configure per-form payment options in the form Integrations tab.', 'ifthenpay-payments-for-fluentform' ),
				'button_text'         => __( 'Disconnect', 'ifthenpay-payments-for-fluentform' ),
				'data'                => array( 'backoffice_key' => '' ),
				'show_verify'         => true,
			),
		);
	}

	/**
	 * Validates the Backoffice Key against the ifthenpay API and persists it.
	 * An empty key disconnects the account.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function saveGlobalSettings( $settings ): void {
		$backoffice_key = sanitize_text_field( $settings['backoffice_key'] ?? '' );

		if ( ! $backoffice_key ) {
			IfthenpaySettings::deletebackoffice_key();
			wp_send_json_success(
				array(
					'message' => __( 'Backoffice Key disconnected.', 'ifthenpay-payments-for-fluentform' ),
					'status'  => false,
				),
				200
			);
			return;
		}

		if ( $backoffice_key === IfthenpaySettings::getbackoffice_key() ) {
			wp_send_json_success(
				array(
					'message' => __( 'Settings saved.', 'ifthenpay-payments-for-fluentform' ),
					'status'  => true,
				),
				200
			);
			return;
		}

		if ( ! preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid Backoffice Key', 'ifthenpay-payments-for-fluentform' ),
				),
				400
			);
			return;
		}

		$ifthenpay_client = new IfthenpayApiClient( $backoffice_key );

		$gateways = $ifthenpay_client->get_gateway_keys( 'fluentforms' );

		if ( empty( $gateways ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No FluentForm context gateways found. Please ensure a Pay by Link gateway is configured for FluentForm in your ifthenpay backoffice.', 'ifthenpay-payments-for-fluentform' ),
				),
				400
			);
			return;
		}

		IfthenpaySettings::savebackoffice_key( $backoffice_key );

		wp_send_json_success(
			array(
				'message' => __( 'Your Backoffice Key has been verified and connected.', 'ifthenpay-payments-for-fluentform' ),
				'status'  => true,
			),
			200
		);
	}



	/**
	 * When a feed is saved as enabled, disable all other ifthenpay feeds for that form.
	 * Runs at priority 20, after setMetaKey (priority 10).
	 *
	 * @param array<string, mixed> $data         Row data about to be written to fluentform_form_meta.
	 * @param int|null             $integrationId The ID of the feed row being saved (null = new).
	 * @return array<string, mixed>
	 */
	public function enforceSingleFeed( $data, $integrationId ): array {
		$feedValue = json_decode( (string) ( $data['value'] ?? '{}' ), true );

		if ( ! is_array( $feedValue ) || ! $this->isFeedEnabledValue( $feedValue['enabled'] ?? false ) ) {
			return $data;
		}


		$gwKey = sanitize_text_field( $feedValue['gateway_key'] ?? '' );
		if ( $gwKey !== '' ) {
			$this->activateCallbackForGateway( $gwKey );
		}

		$formId  = (int) ( $data['form_id'] ?? 0 );
		$metaKey = (string) ( $data['meta_key'] ?? $this->settingsKey );

		if ( ! $formId ) {
			return $data;
		}

		$existingFeeds = wpFluent()
			->table( 'fluentform_form_meta' )
			->where( 'form_id', $formId )
			->where( 'meta_key', $metaKey )
			->get();

		foreach ( $existingFeeds as $existingFeed ) {
			if ( $integrationId && (int) $existingFeed->id === (int) $integrationId ) {
				continue;
			}
			$existingValue = json_decode( $existingFeed->value, true );
			if ( is_array( $existingValue ) && $this->isFeedEnabledValue( $existingValue['enabled'] ?? false ) ) {
				$existingValue['enabled'] = false;
				wpFluent()
					->table( 'fluentform_form_meta' )
					->where( 'id', $existingFeed->id )
					->update( array( 'value' => wp_json_encode( $existingValue ) ) );
			}
		}

		return $data;
	}

	/**
	 * Fluent Forms stores 'enabled' inconsistently across code paths -- sometimes
	 * a real bool, sometimes the string 'true'/'false' (see its own
	 * IntegrationManagerController::prepareIntegrationFeed() normalizing the same
	 * ambiguity). A raw empty()/truthiness check misreads the string "false" as
	 * enabled, which would silently defeat single-feed enforcement.
	 *
	 * @param mixed $value
	 */
	private function isFeedEnabledValue( $value ): bool {
		if ( is_string( $value ) ) {
			return strtolower( trim( $value ) ) === 'true' || trim( $value ) === '1';
		}
		return (bool) $value;
	}



	public function ajaxConnect(): void {
		check_ajax_referer( 'iftp_ff_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-fluentform' ) ), 403 );
			return;
		}

		$rawKey = sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $rawKey ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Context Backoffice Key.', 'ifthenpay-payments-for-fluentform' ) ) );
			return;
		}

		$client   = new IfthenpayApiClient( $rawKey );
		$gateways = $client->get_gateway_keys( 'fluentforms' );

		if ( empty( $gateways ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No FluentForm context gateways found for this key. Please ensure you have a Pay by Link gateway configured for FluentForm in your ifthenpay backoffice.', 'ifthenpay-payments-for-fluentform' ),
				)
			);
			return;
		}

		IfthenpaySettings::savebackoffice_key( $rawKey );

		wp_send_json_success( array( 'message' => __( 'Backoffice Key connected successfully.', 'ifthenpay-payments-for-fluentform' ) ) );
	}

	public function ajaxDisconnect(): void {
		check_ajax_referer( 'iftp_ff_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-fluentform' ) ), 403 );
			return;
		}

		IfthenpaySettings::deletebackoffice_key();

		wp_send_json_success( array( 'message' => __( 'Backoffice Key disconnected.', 'ifthenpay-payments-for-fluentform' ) ) );
	}

	/**
	 * AJAX: render the methods table HTML for a given gateway key.
	 * JS sends the gateway label/alias (el-select shows the alias, not the raw key).
	 * PHP resolves alias→key and renders the table server-side.
	 */
	public function ajaxGetMethods(): void {
		check_ajax_referer( 'iftp_ff_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-fluentform' ) ), 403 );
			return;
		}

		$gatewayKeyOrAlias = sanitize_text_field( wp_unslash( $_POST['gateway_key'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every field is sanitized in sanitizeMethodsConfig() below, not at the raw-string stage.
		$savedConfigRaw    = isset( $_POST['methods_config'] ) ? wp_unslash( (string) $_POST['methods_config'] ) : '{}';
		$decodedConfig     = json_decode( $savedConfigRaw, true );
		$savedConfig       = is_array( $decodedConfig ) ? $this->sanitizeMethodsConfig( $decodedConfig ) : array();

		[ $rows, $resolvedKey ] = $this->buildMethodRowsForGateway( $gatewayKeyOrAlias );

		$html        = empty( $rows )
			? '<p class="iftp-ff-methods-empty">' . esc_html__( 'No payment methods found for this gateway.', 'ifthenpay-payments-for-fluentform' ) . '</p>'
			: $this->renderMethodsHtml( $resolvedKey, $rows, $savedConfig );
		$methodsMeta = array_values(
			array_map(
				static fn( array $r ): array => array(
					'entity'  => (string) ( $r['entity'] ?? '' ),
					'label'   => (string) ( $r['label'] ?? '' ),
					'account' => (string) ( $r['account'] ?? '' ),
				),
				$rows
			)
		);

		wp_send_json_success(
			array(
				'html'              => $html,
				'methods_meta'      => $methodsMeta,
				'suggested_default' => $this->suggestDefaultMethod( $rows ),
			)
		);
	}

	/**
	 * AJAX: send activation e-mail to ifthenpay support for a non-provisioned method.
	 * 24-hour cooldown per gateway+entity pair.
	 */
	public function ajaxActivateMethod(): void {
		check_ajax_referer( 'iftp_ff_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-fluentform' ) ), 403 );
			return;
		}

		$entity     = strtoupper( sanitize_text_field( wp_unslash( $_POST['entity'] ?? '' ) ) );
		$gatewayKey = sanitize_text_field( wp_unslash( $_POST['gateway_key'] ?? '' ) );

		if ( $entity === '' || $gatewayKey === '' ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'ifthenpay-payments-for-fluentform' ) ) );
			return;
		}

		$cooldownKey = 'iftp_ff_activation_' . md5( $gatewayKey . '_' . $entity );
		if ( get_transient( $cooldownKey ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'An activation request was already sent for this method in the last 24 hours.', 'ifthenpay-payments-for-fluentform' ),
				)
			);
			return;
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'    => $gatewayKey,
				'entity'         => $entity,
				'backoffice_key' => IfthenpaySettings::getbackoffice_key(),
				'customer_email' => (string) get_option( 'admin_email', '' ),
				'site_url'       => home_url( '/' ),
				'site_name'      => (string) get_option( 'blogname', '' ),
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'ff_version'     => defined( 'FLUENTFORM_VERSION' ) ? FLUENTFORM_VERSION : '',
				'plugin_version' => IFTP_FF_VERSION,
			)
		);

		if ( $sent ) {
			set_transient( $cooldownKey, 1, DAY_IN_SECONDS );
			wp_send_json_success(
				array(
					'message' => __( 'Activation request sent. ifthenpay support will contact you shortly.', 'ifthenpay-payments-for-fluentform' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to send activation request. Please contact ifthenpay support directly.', 'ifthenpay-payments-for-fluentform' ),
				)
			);
		}
	}



	public function enqueueAdminScripts( string $hook ): void {
		if ( strpos( $hook, 'fluent_form' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'iftp-ff-admin',
			IFTP_FF_URL . 'assets/css/admin.css',
			array(),
			IFTP_FF_VERSION
		);

		wp_enqueue_script(
			'iftp-ff-admin',
			IFTP_FF_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			IFTP_FF_VERSION,
			true
		);

		wp_localize_script(
			'iftp-ff-admin',
			'iftpFfAdmin',
			array(
				'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
				'nonce'                    => wp_create_nonce( 'iftp_ff_admin' ),
				'isConnected'              => IfthenpaySettings::isConnected(),
				'iconUrl'                  => IFTP_FF_URL . 'assets/img/logo-color.svg',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification, no state change
				'methodIcons'              => ( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) === 'fluent_forms_payment_entries' )
					? $this->buildMethodIconMap()
					: array(),
				'methodsConfigPlaceholder' => 'iftp_ff_methods_config',
				'defaultMethodPlaceholder' => 'iftp_ff_default_method',
				'i18n'                     => array(
					'connecting'         => __( 'Connecting...', 'ifthenpay-payments-for-fluentform' ),
					'disconnecting'      => __( 'Disconnecting...', 'ifthenpay-payments-for-fluentform' ),
					'error'              => __( 'An error occurred. Please try again.', 'ifthenpay-payments-for-fluentform' ),
					'connect'            => __( 'Connect', 'ifthenpay-payments-for-fluentform' ),
					'disconnect'         => __( 'Disconnect', 'ifthenpay-payments-for-fluentform' ),
					'selectGatewayFirst' => __( 'Select a gateway key above to load available payment methods.', 'ifthenpay-payments-for-fluentform' ),
					'loadingMethods'     => __( 'Loading payment methods…', 'ifthenpay-payments-for-fluentform' ),
					'noMethods'          => __( 'No payment methods found for this gateway.', 'ifthenpay-payments-for-fluentform' ),
					'activate'           => __( 'Activate', 'ifthenpay-payments-for-fluentform' ),
					'activating'         => __( 'Sending…', 'ifthenpay-payments-for-fluentform' ),
					'activationSent'     => __( 'Request sent!', 'ifthenpay-payments-for-fluentform' ),
				),
			)
		);
	}



	/**
	 * Recursively sanitizes a decoded methods_config payload: entity keys are
	 * forced through sanitize_key(), 'account' values through
	 * sanitize_text_field(), and 'enabled' is cast to a strict bool. Anything
	 * that isn't a per-entity array is dropped.
	 *
	 * @param array<mixed, mixed> $config
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitizeMethodsConfig( array $config ): array {
		$clean = array();

		foreach ( $config as $entity => $cfg ) {
			if ( ! is_array( $cfg ) ) {
				continue;
			}

			$key = strtoupper( sanitize_key( (string) $entity ) );
			if ( $key === '' ) {
				continue;
			}
			$clean[ $key ] = array(
				'enabled' => ! empty( $cfg['enabled'] ),
				'account' => isset( $cfg['account'] ) ? sanitize_text_field( (string) $cfg['account'] ) : '',
			);
		}

		return $clean;
	}

	public function registerCallbackQueryVar( array $vars ): array {
		$vars[] = IFTP_FF_CALLBACK_SLUG;
		return $vars;
	}

	private function buildWebhookUrl(): string {
		return home_url( '/' . IFTP_FF_CALLBACK_SLUG );
	}

	/**
	 * Build gateway key dropdown options for the per-form feed 'select' component.
	 * Must return an associative array ['gatewayKey' => 'Alias label'] -- NOT [{label, value}].
	 * The 'select' Vue component iterates: value = key, label = value.
	 *
	 * @return array<string, string>
	 */
	private function buildGatewayKeyOptions(): array {
		$gateways = $this->getGatewayKeys();

		if ( empty( $gateways ) ) {
			return array( '' => __( '-- No gateway keys found --', 'ifthenpay-payments-for-fluentform' ) );
		}

		$options = array( '' => __( '-- Select a Gateway Key --', 'ifthenpay-payments-for-fluentform' ) );

		foreach ( $gateways as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key   = (string) ( $row['GatewayKey'] ?? '' );
			$alias = (string) ( $row['Alias'] ?? $key );
			if ( $key !== '' ) {
				$options[ $key ] = $alias !== '' ? $alias : $key;
			}
		}

		return $options;
	}

	/**
	 * Returns the gateway-key rows for the connected backoffice key.
	 *
	 * Always hits /gateway/get (no persistent cache), but memoizes the result for
	 * the lifetime of the current request so a single render — where both the
	 * dropdown options and the methods table need the list — triggers exactly one
	 * API call instead of one per caller.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getGatewayKeys(): array {
		if ( $this->gatewayKeysMemo !== null ) {
			return $this->gatewayKeysMemo;
		}

		$backoffice_key = IfthenpaySettings::getbackoffice_key();
		if ( $backoffice_key === '' ) {
			return $this->gatewayKeysMemo = array();
		}

		try {
			return $this->gatewayKeysMemo = ( new IfthenpayApiClient( $backoffice_key ) )->get_gateway_keys();
		} catch ( \Throwable $e ) {
			return $this->gatewayKeysMemo = array();
		}
	}

	/**
	 * Returns the visible payment-method catalog keyed by entity.
	 *
	 * Always hits /gateway/methods/available (no persistent cache), but memoizes
	 * the result per request so it is fetched once even if several methods-table
	 * rows are built in the same request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchAvailableMethodsKeyed(): array {
		if ( $this->availableMethodsMemo !== null ) {
			return $this->availableMethodsMemo;
		}

		try {
			$raw = IfthenpayApiClient::get_available_methods();
		} catch ( \Throwable $e ) {
			return $this->availableMethodsMemo = array();
		}

		$keyed = array();
		foreach ( $raw as $method ) {
			if ( empty( $method['Entity'] ) || empty( $method['IsVisible'] ) ) {
				continue;
			}
			$entity           = strtoupper( (string) $method['Entity'] );
			$keyed[ $entity ] = array(
				'entity'    => $entity,
				'label'     => (string) ( $method['Method'] ?? $entity ),
				'image_url' => (string) ( $method['SmallImageUrl'] ?? $method['ImageUrl'] ?? '' ),
				'position'  => (int) ( $method['Position'] ?? 0 ),
			);
		}

		uasort( $keyed, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return $this->availableMethodsMemo = $keyed;
	}

	/**
	 * Returns the visible methods catalog keyed by lowercase entity (e.g. 'mbway'),
	 * mapping to each method's icon URL. Used only on the Payment Entries admin
	 * page so its JS can draw the method icon next to the ifthenpay pill — see
	 * the transaction.card_brand write in IfthenpayProcessor::processWebhook().
	 *
	 * Cached via the transient shared with IfthenpayInline::getMethodCatalog()
	 * to avoid hitting /gateway/methods/available on every page load.
	 *
	 * @return array<string, string>
	 */
	private function buildMethodIconMap(): array {

		$keyed = get_transient( self::METHODS_TRANSIENT );
		if ( ! is_array( $keyed ) ) {
			try {
				$raw = IfthenpayApiClient::get_available_methods();
			} catch ( \Throwable $e ) {
				$raw = array();
			}

			$keyed = array();
			foreach ( $raw as $method ) {
				if ( empty( $method['Entity'] ) || empty( $method['IsVisible'] ) ) {
					continue;
				}
				$entity           = strtoupper( (string) $method['Entity'] );
				$keyed[ $entity ] = array(
					'entity'         => $entity,
					'label'          => (string) ( $method['Method'] ?? $entity ),
					'image_url'      => (string) ( $method['SmallImageUrl'] ?? $method['ImageUrl'] ?? '' ),
					'image_url_dark' => (string) ( $method['SmallImageUrlDark'] ?? '' ),
					'position'       => (int) ( $method['Position'] ?? 0 ),
				);
			}

			set_transient( self::METHODS_TRANSIENT, $keyed, 5 * MINUTE_IN_SECONDS );
		}

		$icons = array();
		foreach ( $keyed as $entity => $entry ) {
			$image = (string) ( $entry['image_url'] ?? '' );
			if ( $image !== '' ) {
				$icons[ strtolower( (string) $entity ) ] = $image;
			}
		}

		return $icons;
	}

	/**
	 * Resolves a gateway key-or-alias to a rows array and the real GatewayKey.
	 * JS sends whatever text the el-select displays (alias/label), not necessarily the raw key.
	 * Matches by GatewayKey (exact) first, then by Alias (case-sensitive).
	 *
	 * @return array{0: array<int, array<string, string>>, 1: string}
	 */
	private function buildMethodRowsForGateway( string $gatewayKeyOrAlias ): array {
		if ( $gatewayKeyOrAlias === '' ) {
			return array( array(), '' );
		}

		$gateways = $this->getGatewayKeys();
		if ( empty( $gateways ) ) {
			return array( array(), '' );
		}

		$gatewayRow  = null;
		$resolvedKey = '';
		foreach ( (array) $gateways as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rowKey   = (string) ( $row['GatewayKey'] ?? '' );
			$rowAlias = (string) ( $row['Alias'] ?? '' );
			if ( $rowKey === $gatewayKeyOrAlias || ( $rowAlias !== '' && $rowAlias === $gatewayKeyOrAlias ) ) {
				$gatewayRow  = $row;
				$resolvedKey = $rowKey;
				break;
			}
		}

		if ( $gatewayRow === null ) {
			return array( array(), '' );
		}

		$methods = array();
		foreach ( $this->fetchAvailableMethodsKeyed() as $entity => $entry ) {
			$account   = $this->resolveAccountInRow( $gatewayRow, $entity, (string) ( $entry['label'] ?? '' ) );
			$methods[] = array(
				'entity'    => $entity,
				'label'     => (string) ( $entry['label'] ?? $entity ),
				'account'   => $account,
				'image_url' => (string) ( $entry['image_url'] ?? '' ),
			);
		}

		return array( $methods, $resolvedKey );
	}

	/**
	 * Server-renders the payment methods table HTML.
	 * Non-provisioned methods show an Activate button instead of "Not activated" text.
	 *
	 * @param array<int, array<string, string>>   $rows
	 * @param array<string, array<string, mixed>> $savedConfig  Decoded methods_config from the feed.
	 */
	private function renderMethodsHtml( string $gatewayKey, array $rows, array $savedConfig ): string {
		$html = '<div class="iftp-ff-methods-list">';

		foreach ( $rows as $row ) {
			$entity        = (string) ( $row['entity'] ?? '' );
			$label         = (string) ( $row['label'] ?? $entity );
			$account       = (string) ( $row['account'] ?? '' );
			$imageUrl      = (string) ( $row['image_url'] ?? '' );
			$isProvisioned = $account !== '';
			$savedEntry    = is_array( $savedConfig[ $entity ] ?? null ) ? $savedConfig[ $entity ] : array();
			$isChecked     = $isProvisioned && ! empty( $savedEntry['enabled'] );

			$itemClass  = 'iftp-ff-method-item' . ( $isChecked ? ' iftp-ff-method-item--checked' : '' ) . ( ! $isProvisioned ? ' iftp-ff-method-item--disabled' : '' );
			$checkClass = 'el-checkbox' . ( $isChecked ? ' is-checked' : '' );
			$inputClass = 'el-checkbox__input' . ( $isChecked ? ' is-checked' : '' );

			$html .= '<div class="' . esc_attr( $itemClass ) . '" data-entity="' . esc_attr( $entity ) . '">';
			$html .= '<label class="' . esc_attr( $checkClass ) . '">';
			$html .= '<span class="' . esc_attr( $inputClass ) . '">';
			$html .= '<input type="checkbox" class="el-checkbox__original iftp-ff-method-checkbox"';
			$html .= ' data-entity="' . esc_attr( $entity ) . '"';
			$html .= ' data-account="' . esc_attr( $account ) . '"';
			if ( $isChecked ) {
				$html .= ' checked';
			}
			if ( ! $isProvisioned ) {
				$html .= ' disabled';
			}
			$html .= '><span class="el-checkbox__inner"></span></span>';

			if ( $imageUrl !== '' ) {
				$html .= '<span class="iftp-ff-method-icon">'
					. '<img src="' . esc_url( $imageUrl ) . '" alt="' . esc_attr( $label ) . '" loading="lazy">'
					. '</span>';
			}

			$html .= '<span class="el-checkbox__label iftp-ff-method-name">' . esc_html( $label ) . '</span>';
			$html .= '</label>';

			if ( $isProvisioned ) {
				$html .= '<span class="iftp-ff-method-account">' . esc_html( $account ) . '</span>';
			} else {
				$html .= '<button type="button" class="button button-small iftp-ff-activate-btn"'
					. ' data-entity="' . esc_attr( $entity ) . '"'
					. ' data-gateway-key="' . esc_attr( $gatewayKey ) . '">'
					. esc_html__( 'Activate', 'ifthenpay-payments-for-fluentform' )
					. '</button>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Returns the entity to auto-select as default method.
	 * Prefers CCARD (Visa/Mastercard); falls back to the first provisioned method.
	 *
	 * @param array<int, array<string, string>> $rows
	 */
	private function suggestDefaultMethod( array $rows ): string {
		$provisioned = array_filter( $rows, static fn( array $r ): bool => $r['account'] !== '' );

		foreach ( $provisioned as $row ) {
			if ( strtoupper( (string) ( $row['entity'] ?? '' ) ) === 'CCARD' ) {
				return 'CCARD';
			}
		}

		$first = reset( $provisioned );
		return $first !== false ? (string) ( $first['entity'] ?? '' ) : '';
	}

	private function resolveAccountInRow( array $row, string $entity, string $methodLabel = '' ): string {
		$candidates = array_unique(
			array_filter(
				array(
					$entity,
					strtoupper( $entity ),
					strtolower( $entity ),
					$methodLabel,
					strtoupper( $methodLabel ),
					strtolower( $methodLabel ),
				)
			)
		);

		if ( strtoupper( $entity ) === 'MB' || strtoupper( $methodLabel ) === 'MULTIBANCO' ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ( $candidates as $key ) {
			if ( $key === '' || ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $row[ $key ] );
			if ( $value !== '' ) {
				return $value;
			}
		}

		return '';
	}

	private function activateCallbackForGateway( string $gatewayKey ): void {
		if ( $gatewayKey === '' ) {
			return;
		}
		IfthenpayApiClient::activate_callback( $gatewayKey, $this->buildWebhookUrl() );
	}
}
