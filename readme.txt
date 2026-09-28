WooCommerce Checkout Tracker
============================
Version: 1.3.0

Features
- Creates a checkout session when a customer opens WooCommerce checkout.
- Captures checkout form values as the customer types/changes fields.
- Excludes passwords, payment/card fields, CVV/CVC, tokens and similar sensitive fields.
- Stores a snapshot of cart products, variations, quantities and prices.
- Admin screen under WooCommerce > Checkout Sessions.
- Detail view for captured fields and cart items.
- Links converted checkout sessions to WooCommerce orders.
- Status: Initiated / Converted. Abandoned sessions can be identified by inactivity and can be extended in a future version with automatic status rules.
- Configurable capture toggle and retention days under WooCommerce > Checkout Tracker.
- Daily cleanup of records older than the configured retention period.
- IP is stored as a one-way HMAC hash, not as a raw IP address.

Installation
1. WordPress Admin > Plugins > Add New > Upload Plugin.
2. Upload the ZIP and activate.
3. Make sure WooCommerce is active.
4. Go to WooCommerce > Checkout Tracker and review retention/capture settings.
5. View captured sessions under WooCommerce > Checkout Sessions.

Privacy
This plugin stores customer checkout information. Configure retention and provide any required privacy notice/consent for your jurisdiction. Never modify the plugin to collect payment card data, CVV/CVC, passwords or payment tokens.


== 1.3.0 ==
* Added configurable abandoned checkout timeout (5–10080 minutes).
* Added scheduled maintenance every 15 minutes for abandoned-status updates and retention cleanup.
* Returning visitors resume an abandoned session as Initiated until conversion.
* Scheduled event is cleared on plugin deactivation and rescheduled when settings are saved.


= 1.3.0 =
* Added configurable abandoned-checkout email alerts.
* Added recipient list setting and one-alert-per-session protection.
* Alert email includes customer details, captured checkout fields, cart items, total, and WP-Admin session link.
