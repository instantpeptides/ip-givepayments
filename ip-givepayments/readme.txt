=== GivePayments for WooCommerce ===
Contributors: givecorporation
Tags: WooCommerce, payments, GivePayments, integration
Requires at least: 6.2
Tested up to: 6.9
WC requires at least: 9.0
WC tested up to: 9.5
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Requires Plugins: woocommerce

Integrate GivePayments as a secure payment gateway for your WooCommerce store with easy management from your WordPress dashboard.

== Description ==
GivePayments for WooCommerce is a robust and secure payment gateway plugin that integrates the GivePayments system with your WooCommerce store. This plugin provides a seamless transaction process and lets you manage your payment settings directly from your WordPress dashboard.

= Key features include: =
* **Seamless Integration:** Easily connect your WooCommerce store with the GivePayments system.
* **User-Friendly Configuration:** Configure payment options via a streamlined admin interface.
* **Enhanced Security:** Robust measures to ensure the protection of customer payment data.
* **Comprehensive Support:** Detailed documentation, FAQs, and dedicated support channels are available.

== Installation ==
1. Upload the entire `givepayments-for-woocommerce` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin via the **Plugins** menu in WordPress.
3. Navigate to **WooCommerce > Settings > Payments** and select the GivePayments gateway to configure your settings.

== Frequently Asked Questions ==
= How does GivePayments Gateway for WooCommerce work? =
Once activated, the plugin adds a new payment gateway option to your WooCommerce checkout, enabling customers to choose GivePayments during the payment process.

= How do I configure the plugin? =
After installation, go to **WooCommerce > Settings > Payments**, select the GivePayments option, and follow the on-screen instructions to complete the setup.


== External Services ==
This plugin connects to the GivePayments payment processing API to facilitate credit and debit card transactions for your WooCommerce store.
= GivePayments API =
* **Service purpose**: Processes credit and debit card payments for your WooCommerce store.
* **Data transmission**:
    - During connection testing: API key, merchant ID, and environment settings are sent to verify credentials.
    - During payment processing: Customer payment details (card information, billing information), order details, and transaction amounts are transmitted securely to GivePayments for processing.
    - All data is transmitted securely using SSL encryption.
* **Frequency**: Data is transmitted when:
    - A connection test is performed in the admin dashboard
    - A customer initiates a payment at checkout
    - A refund is processed through the WooCommerce order management system
* **Terms of Service**: [GivePayments Terms of Service](https://portal.givepayments.com/terms_of_service)
* **Privacy Policy**: [GivePayments Privacy Policy](https://portal.givepayments.com/privacy)

Note: You will need a GivePayments merchant account to use this plugin. Visit [GivePayments Merchant Portal](https://givepaid.givepayments.com/signup) to sign up or access your account.

== Changelog ==
= 1.0.10 =
* Fixed: duplicate charge prevention, a cross-order dedup sentinel now blocks repeat submissions when a payment API timeout leaves the charge state ambiguous (cURL error 28 / `WP_Error`).
* Fixed: WooCommerce Blocks draft-order session pointer is now cleared on API timeout and on synchronous declines so the next checkout attempt creates a clean order instead of reusing the stale pending one.
* Fixed: orders marked with `_givepayments_pending_timeout` are now excluded from the WooCommerce auto-cancel unpaid orders cron, preventing the "sale went through then failed" email sequence.
* Fixed: $0 order guard, order totals are recalculated before the payment request and, if a prior transaction exists, the confirmed amount is recovered from the API before blocking the submission.
* Fixed: per-order checkout mutex prevents concurrent payment submissions for the same order from duplicate Blocks events or browser back-navigation.
* Fixed: card brand is now detected from the IIN/BIN and included in the dedup key, preventing false-positive blocks when two cards share the same last-4 digits but differ in network (e.g. Visa vs Mastercard).
* Fixed: encryption key generation uses an atomic `add_option()` call instead of a read-then-write pattern, eliminating a race condition that could overwrite the key under concurrent plugin activations.
* Fixed: synchronous payment declines (2xx with terminal `processingState`) now clear the Blocks session pointer, matching the behaviour already applied to HTTP 4xx declines.
* Fixed: checkout payment errors now follow Stripe-style handling by returning checkout failure with a shopper-visible notice and clearing stale checkout order pointers before retry.
* Fixed: HTTP 422 `Invalid Input` payment failures now surface provider field-level validation details when present, instead of only showing a generic invalid input message.
* Fixed: failed payment order notes now include the backend/provider error message or explicitly state when no provider message was returned.
* Fixed: invalid billing phone numbers are now blocked during checkout when provided, while optional empty phone values remain allowed.
* Added: `givepayments_api_timeout` filter allowing merchants and developers to override the HTTP timeout per endpoint and method without patching plugin code.
* Improved: POST `/payments` timeout raised to 45 s to reduce false-timeout errors on slower acquiring-network responses.
* Improved: dedup sentinel TTL reduced to 5 minutes (was 10) to shorten the customer retry window when a genuine server error occurs with no charge.
* Improved: payment error messages are now normalised through a dedicated `GIVEPAYMENTS_Api_Response::parse_error()` pipeline, field-validation errors (`invalid_input`) are surfaced to customers with human-readable field labels; all other provider error codes and messages are written to order notes only to prevent leakage of fraud scores or internal rule names.
* Improved: codebase refactored into focused domain classes (`Payment`, `Refund`, `Webhook`, `Checkout`, `Http`, `Crypto`, `Subscription`) for improved maintainability and testability.

= 1.0.9 =
* Changed: successful in-flight POST `/payments` responses (`created`, `authorized`, `captured`, `processing`, `processing_issue`) now move orders to WooCommerce `processing` immediately while webhooks remain authoritative for final reconciliation.
* Changed: `refund.created` and `refund.pending` webhooks now mark orders `refunded` in WooCommerce per merchant policy and mirror WooCommerce refund accounting for custom captured / processing_issue void actions.
* Changed: refund webhooks that confirm plugin-originated `Void` actions now use payment reversal wording in order notes.
* Fixed: captured webhooks now add a visible captured order note even when the order was already `processing`.
* Changed: admin actions now show `Void` for `authorized`, `captured`, and `processing_issue` payments; native WooCommerce `Refund` is reserved for `settled` payments.
* Fixed: duplicate pending refund notes and duplicate refunded status-transition notes from closely timed or repeated refund webhooks are suppressed.
* Fixed: native WooCommerce Refund button no longer briefly flashes before being hidden on GivePayments order screens where it is not available.

= 1.0.8 =
* Tested up to WordPress 6.9.
* Changed: `payment.created` and `payment.authorized` webhook events now move orders into WooCommerce `processing` immediately instead of `pending`, while still allowing later `payment.failed` / `payment.declined` events to transition the order to `failed`.
* Fixed: shipping addresses for countries without state/subdivision fields (e.g., Czech Republic, Netherlands) no longer cause GivePayments API validation errors at checkout.
* Fixed: WooCommerce Blocks checkout now surfaces the actual GivePayments error message (e.g., fraud blocks, declines) instead of the generic "Something went wrong" message.
* Fixed: race condition that could display a stale void amount of 0.
* Improved: payment description is now sourced from gateway settings with a safe empty-string fallback.
* Improved: production API URL handling and admin UI cleanup.

= 1.0.7 =
* Maintenance release with internal fixes and version alignment.

= 1.0.6 =
* Added: subscription adapter registry with MVP adapters for WooCommerce Subscriptions, WP Swings SFW, YITH, and Flexible Subscriptions by WP Desk, enabling card tokenization and renewal payment routing for supported subscription plugins.
* Fixed: duplicate void/refund handling and several race conditions when refunds or voids were initiated from WooCommerce.
* Fixed: refunds created through the GivePayments API are now correctly marked as `refunded` immediately, and voided transactions display a 0 amount.
* Fixed: declined transactions now correctly transition the order to `failed`.
* Fixed: native WooCommerce refund button is hidden when only a void action is allowed, preventing conflicting actions.
* Fixed: re-register webhook button label and checkout failure edge cases.

= 1.0.5 =
* Added: recurring payments phase 1 groundwork.
* Added: re-register webhook button in the gateway settings.
* Added: void action for authorized transactions.
* Improved: webhook signature verification, registration handling, and logging for stale entries.
* Improved: status lifecycle now relies on the raw provider processing state, with refund/chargeback events no longer overwriting the underlying payment state.
* Improved: security hardening, additional sanitization, and redaction of CVV / card number values from logs.
* Fixed: webhook transaction ID resolution for voids and refunds, including fallback matching.
* Fixed: declined transaction status mapping and refund UI visibility for failed transactions.
* Fixed: production and sandbox API base URLs.

= 1.0.4 =
* Internal release rolled up into 1.0.5.

= 1.0.3 =
* Fixed API key save/toggle behavior when switching between Sandbox and Production environments.
* Improved admin key handling to prevent invalid re-encryption and intermittent 400 payment errors.

= 1.0.2 =
* Added direct card payment flow improvements for checkout and checkout blocks.
* Added native WooCommerce refund integration with GivePayments API.
* Improved payment/refund error handling and logging for easier troubleshooting.
* Hardened legacy return handling checks for safer order updates.

= 1.0.1 =
* Patch release

= 1.0.0 =
* Initial release of GivePayments for WooCommerce.
* Seamless integration of the GivePayments payment gateway.
* Implementation of user-friendly configuration and enhanced security features.

== Upgrade Notice ==
= 1.0.10 =
Recommended update: fixes duplicate charge risk on API timeouts, prevents WooCommerce from auto-cancelling timed-out pending orders, adds a $0 order guard, and hardens the checkout session cleanup on declines. Also includes a `givepayments_api_timeout` filter and a full codebase refactor into domain classes.

= 1.0.9 =
Recommended update: orders now show `processing` immediately for successful in-flight payment responses, refund created/pending webhooks mark WooCommerce orders refunded, and refund/void admin actions and duplicate webhook notes are handled more reliably.

= 1.0.8 =
Recommended update: webhook lifecycle now moves created/authorized payments to `processing`, fixes non-US shipping address checkout failures, and surfaces real GivePayments error messages on Blocks checkout.

= 1.0.7 =
Maintenance release.

= 1.0.6 =
Recommended update with subscription adapter foundation and significant refund/void reliability fixes.

= 1.0.5 =
Recommended update with webhook signature, status lifecycle, and security hardening improvements.

= 1.0.4 =
Internal release rolled up into 1.0.5.

= 1.0.3 =
Recommended update to fix environment API key switching reliability.

= 1.0.2 =
Recommended update with direct checkout and refund flow improvements.

= 1.0.1 =
Minor patch release.

= 1.0.0 =
This is the initial release of GivePayments Gateway for WooCommerce.

== Support ==
For support, documentation, or troubleshooting, please visit: [GivePayments Support Page](https://merchants.givepayments.com/support/home)

Alternatively, you can email us at support@givepayments.com.
