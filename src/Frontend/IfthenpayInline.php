<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Frontend;

defined( 'ABSPATH' ) || exit;

use ifthenpay\FluentForm\Api\IfthenpayApiClient;
use ifthenpay\FluentForm\Storage\IfthenpaySettings;

/**
 * Frontend-only rendering for the ifthenpay payment method.
 *
 * When "ifthenpay" is the selected payment method on a form, Fluent Forms fires
 * `fluentform/payment_method_contents_ifthenpay` so add-ons can append inline
 * markup beneath the method radio (the same hook Stripe uses for its inline card
 * element — see StripeInline). We use it to render a branded box showing the
 * ifthenpay logo and the icons of the methods enabled in the form's feed, so the
 * customer sees what they can pay with before being redirected to the hosted page.
 *
 * The box mirrors the structure/visual weight of Stripe's `.ff_stripe_card_element`
 * (it carries `.ff-el-form-control` to inherit Fluent Forms' input styling) but is
 * display-only: there is no card input — payment happens on the ifthenpay page.
 */
final class IfthenpayInline {

	/** Shared with IfthenpayIntegration so the admin/frontend reuse one catalog fetch. */
	private const METHODS_TRANSIENT = 'iftp_ff_available_methods';

	public function init(): void {
		add_filter( 'fluentform/payment_method_contents_ifthenpay', array( $this, 'renderInlineContents' ), 10, 4 );
	}

	/**
	 * Appends the ifthenpay inline box to the payment method markup.
	 *
	 * @param string               $inlineContents Markup accumulated by earlier filters.
	 * @param array<string, mixed> $method        The payment method definition (+ is_default flag).
	 * @param array<string, mixed> $data          Field render data (attributes, settings).
	 * @param object               $form          The form being rendered.
	 */
	public function renderInlineContents( $inlineContents, $method, $data, $form ): string {
		$formId  = (int) ( $form->id ?? 0 );
		$methods = $this->getActiveMethods( $formId );

		$this->enqueueAssets();

		$label = (string) ( $method['settings']['option_label']['value']
			?? __( 'Pay with ifthenpay', 'ifthenpay-payments-for-fluentform' ) );


		$display = ! empty( $method['is_default'] ) ? 'block' : 'none';

		$name      = (string) ( $data['attributes']['name'] ?? 'ifthenpay' );
		$instance  = (int) ( $form->instance_index ?? 1 );
		$elementId = $name . '_' . $formId . '_' . $instance . '_ifthenpay_inline';

		$logoUrl     = IFTP_FF_URL . 'assets/img/ifthenpay.svg';
		$logoUrlDark = IFTP_FF_URL . 'assets/img/ifthenpay-dark.svg';

		ob_start();
		?>
		<div class="iftp-ff-inline ff_pay_inline" style="display: <?php echo esc_attr( $display ); ?>">
			<div class="ff-el-input--label">
				<label for="<?php echo esc_attr( $elementId ); ?>"><?php echo esc_html( $label ); ?></label>
			</div>
			<div
				id="<?php echo esc_attr( $elementId ); ?>"
				class="iftp-ff-inline-box ff-el-form-control<?php echo empty( $methods ) ? ' iftp-ff-inline-box--solo' : ''; ?>"
				data-wpf_payment_method="ifthenpay"
				data-checkout_style="embedded_form"
			>
				<span class="iftp-ff-inline-brand">
					<img
						class="iftp-ff-inline-logo"
						src="<?php echo esc_url( $logoUrl ); ?>"
						data-src-light="<?php echo esc_url( $logoUrl ); ?>"
						data-src-dark="<?php echo esc_url( $logoUrlDark ); ?>"
						alt="ifthenpay"
						width="28"
						height="28"
					/>
				</span>
				<?php if ( ! empty( $methods ) ) : ?>
					<span class="iftp-ff-inline-methods" role="list" aria-label="<?php esc_attr_e( 'Available payment methods', 'ifthenpay-payments-for-fluentform' ); ?>">
						<?php foreach ( $methods as $activeMethod ) : ?>
							<?php if ( $activeMethod['image_url'] !== '' ) : ?>
								<?php $darkImageUrl = $activeMethod['image_url_dark'] !== '' ? $activeMethod['image_url_dark'] : $activeMethod['image_url']; ?>
								<img
									class="iftp-ff-inline-method"
									role="listitem"
									src="<?php echo esc_url( $activeMethod['image_url'] ); ?>"
									data-src-light="<?php echo esc_url( $activeMethod['image_url'] ); ?>"
									data-src-dark="<?php echo esc_url( $darkImageUrl ); ?>"
									alt="<?php echo esc_attr( $activeMethod['label'] ); ?>"
									title="<?php echo esc_attr( $activeMethod['label'] ); ?>"
									loading="lazy"
								/>
							<?php else : ?>
								<span class="iftp-ff-inline-method iftp-ff-inline-method--text" role="listitem"><?php echo esc_html( $activeMethod['label'] ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</div>
			<p class="iftp-ff-inline-hint">
				<?php esc_html_e( 'You will be securely redirected to ifthenpay to complete your payment.', 'ifthenpay-payments-for-fluentform' ); ?>
			</p>
		</div>
		<?php
		return $inlineContents . (string) ob_get_clean();
	}



	/**
	 * Enqueues the frontend assets. Called from the render filter (after
	 * wp_enqueue_scripts) so it only loads on pages that actually render an
	 * ifthenpay payment method. Late style/script enqueues print in the footer.
	 */
	private function enqueueAssets(): void {
		if ( wp_script_is( 'iftp-ff-checkout', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'iftp-ff-checkout',
			IFTP_FF_URL . 'assets/css/checkout.css',
			array(),
			IFTP_FF_VERSION
		);

		wp_enqueue_script(
			'iftp-ff-checkout',
			IFTP_FF_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			IFTP_FF_VERSION,
			true
		);
	}

	/**
	 * Builds the ordered list of methods enabled on the form's feed, each paired
	 * with its label and icon from the ifthenpay methods catalog. Methods are
	 * returned in catalog (Position) order for a stable, brand-consistent layout.
	 *
	 * @return array<int, array{entity: string, label: string, image_url: string, image_url_dark: string}>
	 */
	private function getActiveMethods( int $formId ): array {
		$feed   = $this->getFormFeed( $formId );
		$raw    = $feed['methods_config'] ?? '';
		$config = is_string( $raw ) ? (array) json_decode( $raw, true ) : (array) $raw;

		if ( empty( $config ) ) {
			return array();
		}

		$catalog = $this->getMethodCatalog();
		$active  = array();


		foreach ( $catalog as $entity => $entry ) {
			$cfg = $config[ $entity ] ?? null;
			if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
				continue;
			}
			$active[] = array(
				'entity'         => (string) $entity,
				'label'          => (string) ( $entry['label'] ?? $entity ),
				'image_url'      => (string) ( $entry['image_url'] ?? '' ),
				'image_url_dark' => (string) ( $entry['image_url_dark'] ?? '' ),
			);
		}


		foreach ( $config as $entity => $cfg ) {
			if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || isset( $catalog[ $entity ] ) ) {
				continue;
			}
			$active[] = array(
				'entity'         => (string) $entity,
				'label'          => (string) $entity,
				'image_url'      => '',
				'image_url_dark' => '',
			);
		}

		return $active;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getFormFeed( int $formId ): array {
		return IfthenpaySettings::getActiveFeed( $formId );
	}

	/**
	 * Returns the ifthenpay methods catalog keyed by entity, with label + icon.
	 * Served from a short-lived transient shared with the admin methods table.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function getMethodCatalog(): array {
		$cached = get_transient( self::METHODS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$raw = IfthenpayApiClient::get_available_methods();
		} catch ( \Throwable $e ) {
			return array();
		}

		$keyed = array();
		foreach ( $raw as $entry ) {
			if ( empty( $entry['Entity'] ) || empty( $entry['IsVisible'] ) ) {
				continue;
			}
			$entity           = strtoupper( (string) $entry['Entity'] );
			$keyed[ $entity ] = array(
				'entity'         => $entity,
				'label'          => (string) ( $entry['Method'] ?? $entity ),
				'image_url'      => (string) ( $entry['SmallImageUrl'] ?? $entry['ImageUrl'] ?? '' ),
				'image_url_dark' => (string) ( $entry['SmallImageUrlDark'] ?? '' ),
				'position'       => (int) ( $entry['Position'] ?? 0 ),
			);
		}

		uasort( $keyed, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		set_transient( self::METHODS_TRANSIENT, $keyed, 5 * MINUTE_IN_SECONDS );

		return $keyed;
	}
}
