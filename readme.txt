WooCommerce Checkout Tracker
============================
Version: 1.5.1

Features
- Creates a checkout session when a customer opens WooCommerce checkout.
- Captures checkout form values as the customer types/changes fields.
- Excludes passwords, card/bank fields, CVV/CVC, PINs, tokens and other secrets (by field name, autocomplete type and payment-gateway area), and redacts card-like numbers typed into any field. Checked in the browser and again on the server.
- Stores a snapshot of cart products, variations, quantities and prices.
- Admin screen under WooCommerce > Checkout Sessions.
- Detail view for captured fields and cart items.
- Links converted checkout sessions to WooCommerce orders (classic checkout and Checkout block).
- Compatible with WooCommerce High-Performance Order Storage (HPOS).
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


= 1.5.1 =
* 

= 1.5.0 =
* Session details open in a responsive modal (Overview / Checkout fields / Cart / Technical tabs, field filter) instead of below the list; ?view=ID links open it directly.
* One-time background cleanup after upgrading (also runs for sites on an early 1.4.0 build): deletes previously stored credential fields and redacts card numbers in other stored values.
* Plugin assets are versioned by file modification time, so browser and plugin caches never serve an outdated stylesheet or script.

= 1.4.0 =
* Checkout block (Store API) orders are now marked Converted.
* A returning customer whose previous session converted gets a new session instead of overwriting the old one.
* Session cookie is issued with the checkout page and requests are serialized, preventing duplicate sessions.
* Only changed fields are sent; the 3-second poll is replaced by an activity heartbeat, batched field writes and cart writes only when the cart changes.
* Abandoned alerts: once per session, only with contact details and cart items, skipped if that email has ordered since, claimed atomically, max 25 per run.
* HPOS: compatibility declared, order meta via WC_Order, admin order links via OrderUtil.
* Stronger sensitive-field filtering and card-number redaction, also applied to email alerts and the admin view.

== 1.3.0 ==
* Added configurable abandoned checkout timeout (5–10080 minutes).
* Added scheduled maintenance every 15 minutes for abandoned-status updates and retention cleanup.
* Returning visitors resume an abandoned session as Initiated until conversion.
* Scheduled event is cleared on plugin deactivation and rescheduled when settings are saved.


= 1.3.0 =
* Added configurable abandoned-checkout email alerts.
* Added recipient list setting and one-alert-per-session protection.
* Alert email includes customer details, captured checkout fields, cart items, total, and WP-Admin session link.
