=== ifthenpay | Payments for FluentForms ===
Contributors: ifthenpay
Tags: fluentforms, ifthenpay, payment, mbway, multibanco, payshop
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: fluentform

Adds ifthenpay payment methods to FluentForms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

== Description ==

This plugin integrates the ifthenpay payment gateway with FluentForms to enable seamless payment collection directly from your forms.

Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website.

After form submission, users are redirected to a secure ifthenpay payment page where they complete the transaction. ifthenpay then sends a server-side callback to automatically update the payment status inside FluentForms.

In plain terms you get:

* One-time payments directly from FluentForms
* Secure automatic payment confirmations
* Payment status synchronization inside FluentForms
* Support for cards, wallets, transfers, and local payment methods
* Merchant backoffice management on web and mobile
* No card numbers stored on your website

All settings are configured directly in FluentForms and the ifthenpay Backoffice.

The plugin is designed so site owners can manage payments without requiring deep technical knowledge.

= Key Features =

1. Full integration with FluentForms payment fields
2. Secure transactions via ifthenpay pay-by-link
3. Automatic payment confirmation through callbacks
4. Multiple payment methods support:
   * Multibanco
   * MB WAY
   * Payshop
   * Credit Card
   * Cofidis
   * Google Pay
   * Apple Pay
   * Pix
5. Real-time payment status updates
6. Support for coupons and automatic totals
7. Multi-language support (EN, ES, FR, PT)
8. Security-first approach — no sensitive payment data stored
9. HTTPS-only communication with ifthenpay APIs

== Requirements ==

* An active ifthenpay merchant account.
* A FluentForms Gateway Key (request this from ifthenpay support/helpdesk).
* FluentForms installed and activated.
* Payment methods enabled in your ifthenpay Gateway Key.
* WordPress 6.5+.
* PHP 8.2+.
* HTTPS (SSL) enabled.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload, or install from WordPress.org and Activate.
2. Make sure your ifthenpay account has an active FluentForms Gateway Key.
3. Go to FluentForms → Integrations → Enable ifthenpay.
4. Go to FluentForms → Global Settings → Connect your Backoffice Key.
5. Create or edit a form.
6. Open Integrations → Add New Integration → ifthenpay.
7. Configure your Gateway Key, payment methods, and options.
8. Save the form and start accepting payments.

== Frequently Asked Questions ==

= Does this plugin require FluentForms? =

Yes. FluentForms must be installed and active to use this plugin.

Without FluentForms, the plugin cannot process payments.

= Does it support recurring payments? =

No. This version supports one-time payments through ifthenpay pay-by-link only.

= Are payment details stored? =

No.

The plugin does not store card numbers or full banking information.

Only the minimum payment references required for matching and status updates are stored.

= Which payment methods are supported? =

Any payment method enabled on your ifthenpay Gateway Key, including:

* Multibanco
* MB WAY
* Payshop
* Credit Card
* Cofidis
* Google Pay
* Apple Pay
* Pix

= How does the payment process work? =

After submitting a form, the customer is redirected to the secure ifthenpay payment page.

After payment completion, ifthenpay sends a callback and FluentForms updates the payment status automatically.

= What happens if a payment fails? =

The FluentForms entry is marked as Failed.

= Can I customize the payment experience? =

Yes.

You can configure payment descriptions, labels, display options, and styling directly inside FluentForms.

= Is there a sandbox / test mode? =

ifthenpay may provide test entities for development.

If unavailable, testing with a low-value live payment is recommended.

= How secure is the integration? =

All requests are encrypted using HTTPS.

No sensitive payment data is stored on your website.

= Why are payment links failing or timing out? =

Your server firewall, hosting provider, or VPN may block outbound HTTPS requests.

Make sure your server can communicate with:

* api.ifthenpay.com
* ifthenpay.com

== External Services ==

This plugin connects to ifthenpay to generate payment links and validate transactions.

ifthenpay is a third-party payment provider supporting cards, wallets, and local payment methods.

= FluentForms =

FluentForms is the form builder used to create forms and collect payment information.

This plugin extends FluentForms by adding ifthenpay payment processing.

= ifthenpay Backoffice & API =

The ifthenpay Backoffice is used to configure merchant settings and payment methods.

The plugin communicates with ifthenpay APIs to create payment links and confirm transactions.

Data sent during setup:

* Backoffice Key
* Gateway Key

Data sent during payment processing:

* Transaction ID
* Amount
* Description
* Enabled payment methods
* Return URLs
* Language
* Optional customer email
* Optional customer name
* Optional form fields

Callback data received:

* Payment status
* Transaction ID
* Payment method

All communication happens server-side over HTTPS.

No raw card or bank details are stored.

== Screenshots ==

1. (Admin Only) Enabling ifthenpay
2. (Admin Only) Backoffice Synchronization
3. (Admin Only) Form Settings
4. (Admin Only) FluentForms's Form Feed Settings
5. (Admin Only) ifthenpay's Gateway Configuration on a Feed Setting
6. (Admin Only) Adding ifthenpay to your form
8. (Customers Experience) Active payment methods
8. (Customers Experience) Payment Window
9. (Admin Only) Checking ifthenpay's created entries
10. (Admin Only) Checking ifthenpay's created Payment Details

== Changelog ==

= 1.0.0 =

* Initial release.
* FluentForms payment integration.
* ifthenpay pay-by-link support.
* Multi-payment method support.
* Automatic payment callbacks.

== Upgrade Notice ==

= 1.0.0 =

Initial release.

Review your ifthenpay Gateway Key configuration before going live.

== License ==

This plugin is licensed under the GPLv3.

== Support ==

For assistance use the WordPress.org support forum:

https://wordpress.org/support/

Pre-checks before posting:

* Payment method enabled on Gateway Key
* Gateway Key connected correctly
* Current recommended versions of WordPress, PHP, and FluentForms installed

Commercial helpdesk available:

https://helpdesk.ifthenpay.com/

ifthenpay support:

suporte@ifthenpay.com

FluentForms documentation:

https://fluentforms.com/docs/
