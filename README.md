# GivePayments (Instant Peptides Fork)

A fork of the official **[GivePayments for WooCommerce](https://givepayments.com)** plugin, maintained by [Chris - Instant Peptides](https://instantpeptides.com).

[Download the latest release](https://github.com/instantpeptides/ip-givepayments/releases/latest)

## What this fork changes

Three behavior patches applied at the source. Everything else (PCI handling, tokenization, API surface, refund logic, credential storage and encryption) is the original GivePayments implementation, unmodified.

1. **`CREATED` order state maps to `pending`** instead of `processing`. Prevents the WooCommerce "processing" email from firing the moment GivePayments acknowledges the payment intent, before the customer's bank has actually moved money.
2. **`SETTLED` webhook does not auto-complete the order.** Completion is left to the normal WooCommerce fulfillment flow (manual or via a shipping integration). The stock plugin's auto-complete-on-settle behavior collided with our fulfillment process.
3. **Webhook resolver maps `payment.created` events to `pending` explicitly.** The stock plugin's resolver dispatched `payment.created` inconsistently depending on event ordering; this patch makes the mapping deterministic.

## Why this exists

This plugin pairs with the [Instant Payment Rotator](https://github.com/instantpeptides/instant-payment-rotator), which delegates all GivePayments credentials, webhook handling, refunds, and voids to whichever GP plugin is installed. The rotator requires *this* fork (not stock GivePayments) because the patches above are what make the rotator's order-state assumptions hold. The rotator detects this fork via the `IP_GIVEPAYMENTS_FORK_VERSION` constant.

## Quick install

1. Download `ip-givepayments.zip` from the [Releases page](https://github.com/instantpeptides/ip-givepayments/releases).
2. WordPress admin: Plugins, Add New, Upload Plugin, choose the zip.
3. Activate.
4. WooCommerce, Settings, Payments, GivePayments. Enter your API key and select your environment.

## Fingerprint

The plugin defines `IP_GIVEPAYMENTS_FORK_VERSION` so companion plugins can detect that this fork (not stock GP) is installed.

```php
if ( defined( 'IP_GIVEPAYMENTS_FORK_VERSION' ) ) {
    // This is the fork. The three behavior patches above are present.
}
```

## Credit and licensing

The vast majority of this codebase is the original work of [GivePayments](https://givepayments.com). This fork exists only to apply the three deterministic behavior patches described above, for compatibility with a specific WooCommerce setup. Licensed under GPLv2 or later, same as the upstream plugin.
