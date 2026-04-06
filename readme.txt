=== DominicN Lipa na Mpesa STK Push Checkout for WooCommerce ===
Contributors: Dominic_N
Tags: woocommerce mpesa, mpesa paybill, lipa na mpesa, mobile money, stk push
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.0.4
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 4.0
WC tested up to: 8.8

Accept M-Pesa Paybill and Till Number payments directly in WooCommerce via STK Push — free for life, no hidden fees.


== Description ==

**DG Lipa na Mpesa Checkout for WooCommerce** is a 100% free, no-strings-attached payment gateway plugin that lets your customers pay using Safaricom's M-Pesa STK Push prompt. No monthly subscription. No hidden charges. No locked features. Free forever — for every WooCommerce store.

Whether your business uses a **Paybill number** or a **Till Number (Buy Goods)**, this plugin has you covered.

= How It Works =

1. Customer selects M-Pesa at checkout and enters their phone number.
2. An STK Push notification is sent to their phone instantly.
3. The customer enters their M-Pesa PIN to complete payment.
4. Your WooCommerce order is automatically updated in real time.

= Key Features =

* ✅ **Paybill & Till Number support** — works with both M-Pesa Paybill and Buy Goods (Till Number) accounts.
* ✅ **STK Push** — frictionless payment prompt sent directly to the customer's phone.
* ✅ **Real-time order updates** — order status changes automatically on payment confirmation via callback.
* ✅ **Analytics Dashboard** — view revenue totals, transaction counts, success rate, and a 30-day revenue chart right from your WP admin.
* ✅ **Block Checkout compatible** — works with both the classic WooCommerce checkout and the modern Gutenberg Block Checkout.
* ✅ **Sandbox & Live modes** — test safely with the Safaricom Sandbox before going live.
* ✅ **Secure** — all requests use nonce verification, input sanitisation, and HTTPS callbacks.
* ✅ **Free for life** — no payment required, no upsells that lock core features.

= Who Is This For? =

Any Kenyan WooCommerce store owner who wants to accept M-Pesa payments with zero cost and zero complexity.

= Requirements =

* An active Safaricom Daraja API account (free at [developer.safaricom.co.ke](https://developer.safaricom.co.ke)).
* A valid SSL certificate on your site (HTTPS required for M-Pesa callbacks).
* WooCommerce 4.0 or higher.


== Installation ==

**Minimum Requirements:**

* WordPress 5.0 or higher
* WooCommerce 4.0 or higher
* PHP 7.2 or higher
* A valid SSL Certificate (HTTPS)

**Setup Instructions:**

1. Upload the plugin folder to `/wp-content/plugins/` or install it directly from the WordPress plugin repository.
2. Activate the plugin via the **Plugins** menu in WordPress.
3. In your WP Admin, go to **Lipa na Mpesa → Settings Guide**.
4. Follow the step-by-step guide to enter your Daraja API credentials.
5. Set your environment to **Sandbox** for testing, then switch to **Live** when ready.


== Frequently Asked Questions ==

= Is this plugin really free? =

Yes — completely free. There is no premium version, no licence key, and no features locked behind a paywall. It is free for life for all WooCommerce users.

= Does it support Paybill numbers? =

Yes. Enter your Paybill short code and passkey in the plugin settings and M-Pesa will prompt customers to pay to your Paybill.

= Does it support Till Numbers (Buy Goods)? =

Yes. Enter your Buy Goods Till Number as the short code. Make sure you use the correct passkey associated with that Till from Safaricom Daraja.

= Where do I get API credentials? =

Register for free at [developer.safaricom.co.ke](https://developer.safaricom.co.ke), create an app, and use the Lipa na M-Pesa Online credentials (Consumer Key, Consumer Secret, Short Code, Passkey).

= Does it work with the new WooCommerce Block Checkout? =

Yes. The plugin is fully compatible with both the classic shortcode checkout and the modern Gutenberg Block Checkout introduced in WooCommerce 8+.

= My site uses shared hosting — will it work? =

Yes, as long as your server supports outbound HTTPS requests and you have a valid SSL certificate for M-Pesa to send callbacks.

= What happens if a customer closes the browser before payment? =

Your order stays in **On Hold** status. If the customer completes the STK Push on their phone, the callback from M-Pesa will still arrive and update the order automatically.

= Is it safe? =

Yes. All form submissions use WordPress nonces, all inputs are sanitised, and the M-Pesa callback endpoint validates the incoming payload before processing.

== External Services ==

This plugin connects to the **Safaricom Daraja API** to facilitate M-Pesa payments. This external service is essential for processing STK Push payments in WooCommerce.

**Service Provider:** Safaricom Limited

**What It Is Used For:** Processing M-Pesa STK Push payment requests and receiving real-time payment status callbacks to update WooCommerce order statuses automatically.

**Data Sent to the Service:**
* Customer phone number (when initiating STK Push)
* Transaction amount in Kenyan Shillings (KES)
* Transaction reference/order details
* Your configured Paybill Number or Till Number
* Your merchant API credentials (Consumer Key, Consumer Secret)

**When Data Is Sent:**
* During checkout when a customer selects M-Pesa as the payment method and submits their phone number
* The payment gateway initiates an STK Push to the customer's phone
* M-Pesa sends a callback to your site with the payment status and transaction details

**Terms and Privacy:**
* Safaricom Daraja API Terms: [https://developer.safaricom.co.ke/terms](https://developer.safaricom.co.ke/terms)
* Safaricom Privacy Policy: [https://www.safaricom.co.ke/dataprivacystatement/](https://www.safaricom.co.ke/dataprivacystatement/)

== Changelog ==

= 1.0.2 =
* Initial public release.
* Added full WooCommerce Block Checkout compatibility.
* New Analytics Dashboard with revenue KPIs, 30-day chart, and paginated transaction log.
* M-Pesa STK Push integration for WooCommerce.
* Sandbox and Live environment support.
* Real-time order status updates via M-Pesa callback.

== 1.0.3 ==
* Fixed mismatching textdomainn name to be dgmpesa-extension.

== 1.0.4 ==
* Updated the plugin name and slug to avoid trademark and project name confusion
* Updated the chartjs version to the latest version 4.5.0
* Added woocommerce as required plugin
* Changed the prefix from dg to domilina to ensure it complies with wordpress length requirements
* Added 3rd Party documentaion and their terms and privacy policy
* Internationalization Fixing Text Domain to mactch plugin slug dominicn-lipa-na-mpesa-stk-push-checkout