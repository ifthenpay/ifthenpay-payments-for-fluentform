<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Payment;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Modules\Payments\PaymentMethods\BaseProcessor;
use ifthenpay\FluentForm\Api\IfthenpayApiClient;
use ifthenpay\FluentForm\Storage\IfthenpaySettings;

/**
 * Handles the full payment lifecycle for ifthenpay:
 *   1. handlePaymentAction()       — form submit: creates PBL, redirects customer
 *   2. handleSessionRedirectBack() — browser return from hosted payment page
 *   3. processWebhook()            — server-to-server callback from ifthenpay
 *
 * Per-form settings (gateway_key, accounts, description, expire_days, default_method)
 * are stored in wp_fluentform_form_meta via the ifthenpayIntegration feed, and read
 * by getFormFeed() at payment time.
 */
class IfthenpayProcessor extends BaseProcessor {

	public $method = 'ifthenpay';

	public function init(): void {
		add_action( 'fluentform/process_payment_ifthenpay', array( $this, 'handlePaymentAction' ), 10, 6 );
		add_action( 'fluentform/payment_frameless_ifthenpay', array( $this, 'handleSessionRedirectBack' ) );
		add_action( 'fluentform/ipn_endpoint_ifthenpay', array( $this, 'processWebhook' ) );
		add_action( 'template_redirect', array( $this, 'handleCallbackEndpoint' ) );
	}

	public function handleCallbackEndpoint(): void {
		if ( get_query_var( IFTP_FF_CALLBACK_SLUG ) ) {
			$this->processWebhook();
		}
	}



	/**
	 * @param int                  $submissionId
	 * @param array<string, mixed> $submissionData
	 * @param object               $form
	 * @param array<string, mixed> $methodSettings
	 * @param bool                 $hasSubscriptions
	 * @param int                  $totalPayable
	 */
	public function handlePaymentAction(
		$submissionId,
		$submissionData,
		$form,
		$methodSettings,
		$hasSubscriptions,
		$totalPayable
	): void {
		$this->setSubmissionId( $submissionId );
		$this->form = $form;

		$submission  = $this->getSubmission();
		$amountCents = $this->getAmountTotal();

		if ( ! $amountCents && ! $hasSubscriptions ) {
			return;
		}


		$feed          = $this->getFormFeed( (int) $form->id );
		$gatewayKey    = sanitize_text_field( $feed['gateway_key'] ?? '' );
		$methodsRaw    = $feed['methods_config'] ?? '';
		$methodsConfig = is_string( $methodsRaw ) ? (array) json_decode( $methodsRaw, true ) : (array) $methodsRaw;
		$defaultMethod = sanitize_text_field( $feed['default_method'] ?? '' );
		$expireDays    = min( 9, max( 0, (int) ( $feed['expire_days'] ?? 3 ) ) );

		$rawDesc     = $feed['description'] ?? '';
		$descClean   = sanitize_text_field( $rawDesc );
		$description = $descClean !== ''
			? str_replace( '{id}', (string) $submission->id, $descClean )
			: $this->buildDescription( $form->title, $submission->id );

		if ( $gatewayKey === '' ) {
			wp_send_json(
				array( 'errors' => __( 'No ifthenpay gateway key configured for this form. Please add an ifthenpay integration feed.', 'ifthenpay-payments-for-fluentform' ) ),
				400
			);
			return;
		}

		$transaction = $this->createInitialPendingTransaction( $submission, $hasSubscriptions );

		$returnBase = array(
			'fluentform_payment' => $submission->id,
			'payment_method'     => 'ifthenpay',
			'transaction_hash'   => $transaction->transaction_hash,
		);

		$successUrl = add_query_arg(
			array_merge( $returnBase, array( 'type' => 'success', 'sig' => $this->signReturn( $transaction->transaction_hash, 'success' ) ) ),
			site_url( 'index.php' )
		);
		$errorUrl = add_query_arg(
			array_merge( $returnBase, array( 'type' => 'error', 'sig' => $this->signReturn( $transaction->transaction_hash, 'error' ) ) ),
			site_url( 'index.php' )
		);
		$cancelUrl = add_query_arg(
			array_merge( $returnBase, array( 'type' => 'cancel', 'sig' => $this->signReturn( $transaction->transaction_hash, 'cancel' ) ) ),
			site_url( 'index.php' )
		);

		$payload = array(
			'id'          => (string) $submission->id,
			'amount'      => IfthenpayApiClient::formatAmount( $amountCents ),
			'description' => $description,
			'success_url' => $successUrl,
			'error_url'   => $errorUrl,
			'cancel_url'  => $cancelUrl,
			'otp'         => 'true',
			'lang'        => $this->mapLocaleToLang( get_locale() ),
		);

		$accountParts = array();
		foreach ( $methodsConfig as $_entity => $cfg ) {
			if ( ! empty( $cfg['enabled'] ) && ! empty( $cfg['account'] ) ) {

				$accountParts[] = preg_replace( '/\s*\|\s*/', '|', sanitize_text_field( (string) $cfg['account'] ) );
			}
		}
		if ( ! empty( $accountParts ) ) {
			$payload['accounts'] = implode( ';', $accountParts );
		}

		if ( $expireDays > 0 ) {
			$payload['expiredate'] = gmdate( 'Ymd', (int) gmdate( 'U' ) + $expireDays * DAY_IN_SECONDS );
		}

		if ( $defaultMethod !== '' ) {
			$position = $this->resolveMethodPosition( $defaultMethod );
			if ( $position > 0 ) {
				$payload['selected_method'] = (string) $position;
			}
		}

		$email = $this->resolveCustomerEmail( $submission );
		if ( $email !== '' ) {
			$payload['email'] = $email;
		}

		try {
			$pbl         = IfthenpayApiClient::create_pay_by_link( $gatewayKey, $payload );
			$redirectUrl = $pbl['redirect_url'];
			$pinCode     = $pbl['pin_code'];
		} catch ( \Exception $e ) {
			wp_send_json(
				array( 'errors' => esc_html( $e->getMessage() ) ),
				423
			);
			return;
		}

		if ( $pinCode !== '' ) {
			$this->updateTransaction(
				$transaction->id,
				array( 'charge_id' => $pinCode )
			);
		}

		/* - Using Fluent Forms core action hook - */
		do_action(
			'fluentform/log_data',
			array(
				'parent_source_id' => $submission->form_id,
				'source_type'      => 'submission_item',
				'source_id'        => $submission->id,
				'component'        => 'Payment',
				'status'           => 'info',
				'title'            => __( 'Redirect to ifthenpay', 'ifthenpay-payments-for-fluentform' ),
				'description'      => __( 'Customer redirected to ifthenpay hosted payment page.', 'ifthenpay-payments-for-fluentform' ),
			)
		);

		wp_send_json_success(
			array(
				'result' => array(
					'insert_id'   => $submission->id,
					'redirectUrl' => $redirectUrl,

					'message'     => __( 'Redirecting to payment page…', 'ifthenpay-payments-for-fluentform' ),
				),
			),
			200
		);
	}



	/**
	 * @param array<string, mixed> $data  raw $_GET contents, unslashed here; sanitise at each read site
	 */
	public function handleSessionRedirectBack( $data ): void {
		$data         = wp_unslash( (array) $data );
		$submissionId = absint( $data['fluentform_payment'] ?? 0 );
		if ( ! $submissionId ) {
			return;
		}

		$this->setSubmissionId( $submissionId );
		$submission      = $this->getSubmission();
		$transactionHash = sanitize_text_field( $data['transaction_hash'] ?? '' );
		$transaction     = $this->getTransaction( $transactionHash, 'transaction_hash' );

		if ( ! $submission || ! $transaction ) {
			return;
		}

		$type = sanitize_text_field( $data['type'] ?? 'success' );
		$sig  = sanitize_text_field( $data['sig'] ?? '' );
		$form = $this->getForm();


		$signatureValid = $sig !== '' && hash_equals( $this->signReturn( $transactionHash, $type ), $sig );

		if ( $transaction->status === 'paid' ) {
			$returnData           = $this->getReturnData();
			$returnData['is_new'] = false;
		} elseif ( $type === 'cancel' && $signatureValid ) {

			$this->markFailureFromRedirect( $transaction, 'cancelled' );

			$returnData = array(
				'insert_id' => $submission->id,

				'title'     => apply_filters(
					/* - Using Fluent Forms core action hook - */
					'fluentform/payment_failed_title',
					__( 'Payment Failed', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'result'    => false,
				'error'     => apply_filters(
					'iftp_ff_payment_cancelled_message',
					__( 'Your payment was cancelled. Please try again.', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'is_new'    => false,
			);
		} elseif ( $type === 'error' && $signatureValid ) {

			$this->markFailureFromRedirect( $transaction, 'failed' );

			$returnData = array(
				'insert_id' => $submission->id,
				'title'     => apply_filters(
					/* - Using Fluent Forms core action hook - */
					'fluentform/payment_failed_title',
					__( 'Payment Failed', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'result'    => false,
				'error'     => apply_filters(
					'iftp_ff_payment_error_message',
					__( 'An error occurred with your payment. Please try again.', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'is_new'    => false,
			);
		} else {

			$returnData = array(
				'insert_id' => $submission->id,
				'title'     => apply_filters(
					'fluentform/payment_pending_title',
					__( 'Payment Pending', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'result'    => false,
				'error'     => apply_filters(
					'fluentform/payment_pending_message',
					__( 'Your payment is being processed. You will receive a confirmation once the payment is completed.', 'ifthenpay-payments-for-fluentform' ),
					$submission,
					$form
				),
				'is_new'    => false,
			);
		}


		$returnData['type'] = 'success';
		$this->showPaymentView( $returnData );
	}

	/**
	 * Process the ifthenpay webhook callback.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Webhook from ifthenpay; authenticated by APK anti-phishing key (base64 of the per-form gateway key)
	 */
	public function processWebhook(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Input validated sequentially below
		$request = wp_unslash( array_merge( (array) $_GET, (array) $_POST ) );

		$submissionId = absint( $request['ref'] ?? 0 );
		$apk          = sanitize_text_field( $request['apk'] ?? '' );
		$status       = sanitize_text_field( $request['status'] ?? '' );

		if ( ! $submissionId || $apk === '' ) {
			status_header( 400 );
			exit;
		}

		$this->setSubmissionId( $submissionId );
		$submission = $this->getSubmission();

		if ( ! $submission ) {
			status_header( 404 );
			exit;
		}


		$feed       = $this->getFormFeed( (int) $submission->form_id );
		$gatewayKey = sanitize_text_field( $feed['gateway_key'] ?? '' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required by ifthenpay anti-phishing specification
		if ( $gatewayKey === '' || ! hash_equals( $gatewayKey, (string) base64_decode( $apk ) ) ) {
			status_header( 403 );
			exit;
		}


		if ( $status === 'cancelled' || $status === 'error' ) {
			$transaction = $this->getLastTransaction( $submissionId );


			if ( ! $transaction || $transaction->payment_method !== $this->method ) {
				status_header( 404 );
				exit;
			}

			if ( $transaction->status !== 'paid' ) {
				$newStatus = $status === 'cancelled' ? 'cancelled' : 'failed';
				$this->changeTransactionStatus( $transaction->id, $newStatus );
				$this->changeSubmissionPaymentStatus( $newStatus );
			}

			status_header( 200 );
			exit;
		}


		$val = sanitize_text_field( $request['val'] ?? '' );
		$mtd = sanitize_text_field( $request['mtd'] ?? '' );
		$req = sanitize_text_field( $request['req'] ?? '' );

		if ( $val === '' ) {
			status_header( 400 );
			exit;
		}

		$transaction = $this->getLastTransaction( $submissionId );


		if ( ! $transaction || $transaction->payment_method !== $this->method ) {
			status_header( 404 );
			exit;
		}


		if ( $transaction->status === 'paid' ) {
			status_header( 200 );
			exit;
		}


		$expectedAmount = IfthenpayApiClient::formatAmount( (int) $transaction->payment_total );
		if ( number_format( (float) $val, 2, '.', '' ) !== $expectedAmount ) {
			status_header( 400 );
			exit;
		}

		$this->changeTransactionStatus( $transaction->id, 'paid' );
		$this->updateTransaction(
			$transaction->id,
			array(
				'charge_id'    => sanitize_text_field( $req ),

				'card_brand'   => strtolower( sanitize_text_field( $mtd ) ),
				'payment_note' => sprintf(
					'Method: %s | Request ID: %s',
					sanitize_text_field( $mtd ),
					sanitize_text_field( $req )
				),
			)
		);
		$this->changeSubmissionPaymentStatus( 'paid' );

		/* - Using Fluent Forms core action hook - */
		do_action(
			'fluentform/log_data',
			array(
				'parent_source_id' => $this->getForm()->id,
				'source_type'      => 'submission_item',
				'source_id'        => $submissionId,
				'component'        => 'Payment',
				'status'           => 'success',
				'title'            => __( 'Payment Confirmed by ifthenpay', 'ifthenpay-payments-for-fluentform' ),
				'description'      => sprintf(
					/* translators: 1: payment method code, 2: formatted amount */
					__( 'Payment of %2$s received via %1$s.', 'ifthenpay-payments-for-fluentform' ),
					sanitize_text_field( $mtd ),
					$expectedAmount
				),
			)
		);

		$this->completePaymentSubmission( false );
		$this->recalculatePaidTotal();

		status_header( 200 );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended



	public function getPaymentMode(): string {
		return 'live';
	}



	/**
	 * Signs a browser-return URL's (transaction_hash, type) pair with an
	 * HMAC derived from the site's auth salt, so `handleSessionRedirectBack()`
	 * can tell an ifthenpay-issued redirect apart from a forged one — the
	 * transaction_hash itself is not a secret, since it is embedded in the
	 * public success/error/cancel URLs.
	 */
	private function signReturn( string $transactionHash, string $type ): string {
		return hash_hmac( 'sha256', $transactionHash . '|' . $type, wp_salt( 'auth' ) );
	}

	/**
	 * Records a cancelled/failed outcome reported by the browser return URL.
	 *
	 * ifthenpay only sends a server-to-server webhook for *successful* payments,
	 * so for cancellations/failures the redirect back to the site is the only
	 * notification we get. We trust it to move a still-pending transaction to a
	 * terminal failure state, but never to overwrite a payment the secure webhook
	 * has already confirmed as paid.
	 *
	 * @param object $transaction
	 * @param string $newStatus    'cancelled' or 'failed'
	 */
	private function markFailureFromRedirect( $transaction, string $newStatus ): void {
		if ( $transaction->status === 'paid' || $transaction->status === $newStatus ) {
			return;
		}

		$this->changeTransactionStatus( $transaction->id, $newStatus );
		$this->changeSubmissionPaymentStatus( $newStatus );
	}

	/**
	 * Resolves an entity name (e.g. "CCARD") to its Position number from the
	 * get_available_methods API (e.g. 4). Result is cached for 1 hour.
	 */
	private function resolveMethodPosition( string $entity ): int {
		$cacheKey  = 'iftp_ff_method_positions';
		$positions = get_transient( $cacheKey );

		if ( ! is_array( $positions ) ) {
			try {
				$methods = IfthenpayApiClient::get_available_methods();
			} catch ( \Throwable $_ ) {
				return 0;
			}
			$positions = array();
			foreach ( $methods as $method ) {
				if ( ! empty( $method['Entity'] ) ) {
					$positions[ strtoupper( (string) $method['Entity'] ) ] = (int) ( $method['Position'] ?? 0 );
				}
			}
			set_transient( $cacheKey, $positions, HOUR_IN_SECONDS );
		}

		return (int) ( $positions[ strtoupper( $entity ) ] ?? 0 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getFormFeed( int $formId ): array {
		return IfthenpaySettings::getActiveFeed( $formId );
	}

	/** @param object $submission */
	private function resolveCustomerEmail( $submission ): string {
		if ( ! empty( $submission->response ) && is_array( $submission->response ) ) {
			foreach ( $submission->response as $value ) {
				if ( is_string( $value ) && is_email( $value ) ) {
					return sanitize_email( $value );
				}
			}
		}
		return '';
	}

	private function buildDescription( string $formTitle, int $submissionId ): string {
		$title = sanitize_text_field( $formTitle );
		if ( $title !== '' ) {
			/* translators: 1: submission ID, 2: form title */
			return sprintf( __( 'Order #%1$d — %2$s', 'ifthenpay-payments-for-fluentform' ), $submissionId, $title );
		}
		/* translators: %d: submission ID */
		return sprintf( __( 'Order #%d', 'ifthenpay-payments-for-fluentform' ), $submissionId );
	}

	private function mapLocaleToLang( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );
		if ( in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ) {
			return $prefix;
		}
		return 'en';
	}
}
