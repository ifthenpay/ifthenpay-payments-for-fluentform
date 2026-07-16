<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the ifthenpay Backoffice Key stored in wp_options.
 */
final class IfthenpaySettings {

	private const BACKOFFICE_KEY_OPTION = 'iftp_ff_backoffice_key';

	public static function getbackoffice_key(): string {
		return (string) get_option( self::BACKOFFICE_KEY_OPTION, '' );
	}

	public static function savebackoffice_key( string $key ): void {
		update_option( self::BACKOFFICE_KEY_OPTION, $key, false );
	}

	public static function deletebackoffice_key(): void {
		delete_option( self::BACKOFFICE_KEY_OPTION );
	}

	public static function isConnected(): bool {
		return self::getbackoffice_key() !== '';
	}

	/**
	 * True when the integration is enabled by the admin AND the backoffice key is connected.
	 * Used by ifthenpayHandler to decide whether to register the payment method.
	 */
	public static function isEnabled(): bool {
		$modulesStatus = get_option( 'fluentform_global_modules_status', array() );
		$isOn          = is_array( $modulesStatus )
			&& ! empty( $modulesStatus['ifthenpay'] )
			&& $modulesStatus['ifthenpay'] === 'yes';

		return $isOn && self::isConnected();
	}

	/**
	 * Reads the first enabled ifthenpay feed for the given form from
	 * wp_fluentform_form_meta (meta_key = 'ifthenpay_feeds'). The form enforces
	 * a single active feed (see IfthenpayIntegration::enforceSingleFeed()), so
	 * the first enabled row found is authoritative.
	 *
	 * Shared by IfthenpayProcessor (payment time) and IfthenpayInline (frontend
	 * rendering) so both read the exact same feed with a single implementation.
	 *
	 * @return array<string, mixed>
	 */
	public static function getActiveFeed( int $formId ): array {
		if ( ! $formId ) {
			return array();
		}

		$metas = wpFluent()
			->table( 'fluentform_form_meta' )
			->where( 'form_id', $formId )
			->where( 'meta_key', 'ifthenpay_feeds' )
			->get();

		foreach ( $metas as $meta ) {
			$feed = json_decode( (string) $meta->value, true );
			if ( is_array( $feed ) && ! empty( $feed['enabled'] ) ) {
				return $feed;
			}
		}

		return array();
	}

	private function __construct() {}
}
