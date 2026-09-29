WooCommerce Checkout Tracker
============================
Version: 1.7.3

Documentation: WooCommerce > Checkout Tracker > Documentation (inside WP Admin).

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


= 1.7.3 =
* 

= 1.7.2 =
* WhatsApp + Coupon message: each cart product is on its own line. The default coupon message now reads "You left these items in your cart at {site_name}:" followed by the list; an unedited copy of the old default is upgraded automatically.
* Coupon offer popup: a single OK button that only closes the popup (no Buy now / Proceed button, no close icon). The restored cart and coupon stay in place.

= 1.7.1 =
* Fixed: a PHP syntax error in class-wct-recovery.php (1.7.0) that caused "There has been a critical error" on every page.
* Safe loading: if a plugin file is missing or damaged, the plugin is installed twice, or PHP/WooCommerce is too old, the plugin now disables itself with an admin notice instead of taking the site down.
* New Documentation tab under WooCommerce > Checkout Tracker (also linked from the Plugins screen and the Checkout Sessions page).
* npm run lint: syntax check for every PHP/JS file; runs automatically before npm version.

= 1.7.0 =
* Recovery coupons for abandoned checkout sessions: one-time WooCommerce coupons (percentage or fixed, optional minimum cart and maximum discount, expiry) generated per session from Add Coupon, with defaults in the new Recovery Coupon settings tab.
* A coupon only works in the browser that opened its personal link; one active coupon per session; marked Used when an order with it is placed and stays Used even if that order is cancelled or fails (never usable twice); protected against concurrent checkouts.
* Minimum cart value is re-checked on every cart change: the coupon is removed with a message below it and re-applied with a message when the cart qualifies again.
* WhatsApp + Coupon button with its own template ({coupon_code}, {coupon_discount}, {coupon_expires}, {coupon_restore_url}); the normal WhatsApp button and template are unchanged.
* Coupon offer popup after a coupon link restores the cart (current cart, discount, new total).
* Recovery coupons are individual use: they cannot be combined with other coupons; a coupon link replaces another coupon in the cart (with a message), and automatic re-apply never removes a coupon the customer added.
* Full coupon history (generated, sent, applied, removed/re-applied, used, order cancelled/failed, expired, revoked) and converted-with/without-coupon tracking.
* Link-open tracking for every WhatsApp restore link: opened or not, how often, first/last open, device, opens after expiry.

= 1.6.0 =
* WhatsApp button per checkout session (table and session modal): opens WhatsApp with the customer number and a message from your template; records who contacted the customer and when.
* WhatsApp & Cart Recovery settings tab: on/off, default country code, message template with placeholders and live preview, cart restore destination (Checkout or Cart), restore link lifetime.
* {cart_restore_url}: secure, expiring, cross-device link that rebuilds the saved cart with current products, prices and stock, merges into an existing cart without removing anything, and redirects straight to the chosen page. Orders placed afterwards are linked to the original session.
* {cart_items} and {cart_total} only include items the restore link can bring back (deleted, unpublished and out-of-stock products are left out, quantities capped to stock, total at current prices).

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
