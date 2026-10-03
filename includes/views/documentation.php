<?php
/**
 * Documentation tab (WooCommerce → Checkout Tracker → Documentation).
 * Written for store staff. Values in "Current" columns come from this site's settings ($s).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$s   = WCT_Tracker::settings();
$cur = esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) );
$on  = function ( $v ) { return $v ? '<span class="wct-doc-on">On</span>' : '<span class="wct-doc-off">Off</span>'; };
$val = function ( $v, $empty = 'None' ) { return '' === (string) $v ? '<span class="wct-muted">' . esc_html( $empty ) . '</span>' : esc_html( $v ); };
$link = function ( $tab, $label ) { return '<a href="' . esc_url( admin_url( 'admin.php?page=wct-settings' . ( $tab ? '&tab=' . $tab : '' ) ) ) . '">' . esc_html( $label ) . '</a>'; };
$sessions = '<a href="' . esc_url( admin_url( 'admin.php?page=wct-checkouts' ) ) . '">WooCommerce → Checkout Sessions</a>';
$toc = array(
    'overview' => 'What this plugin is', 'quick-start' => 'Quick start', 'lifecycle' => 'How a checkout session works',
    'data' => 'What is captured (and what never is)', 'sessions-screen' => 'The Checkout Sessions screen', 'settings' => 'Settings reference',
    'whatsapp' => 'Following up by WhatsApp', 'restore' => 'The cart restore link', 'coupons' => 'Recovery coupons',
    'tracking' => 'Tracking and history', 'alerts' => 'Abandoned email alerts', 'scheduled' => 'Background tasks',
    'faq' => 'Troubleshooting and FAQ', 'privacy' => 'Privacy and good practice', 'uninstall' => 'Deactivating and uninstalling',
    'requirements' => 'Requirements and compatibility', 'developers' => 'For developers',
);
?>
<div class="wct-doc">
<nav class="wct-doc__toc" aria-label="Documentation contents">
    <strong>Contents</strong>
    <ol><?php foreach ( $toc as $id => $label ) echo '<li><a href="#wct-doc-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a></li>'; ?></ol>
    <p class="wct-muted">Version <?php echo esc_html( WCT_VERSION ); ?></p>
</nav>
<div class="wct-doc__body">

<section id="wct-doc-overview">
<h2>1. What this plugin is</h2>
<p><strong>WooCommerce Checkout Tracker</strong> records what shoppers do on your checkout page before they order or leave, and gives you the tools to win back the ones who left.</p>
<ul>
    <li><strong>See every checkout attempt</strong>: who started checkout, what they typed (name, phone, email, address, notes and other fields), what was in their cart, and whether they ordered.</li>
    <li><strong>Spot abandoned checkouts</strong>: a checkout with no activity for a while is marked <em>Abandoned</em>, and you can be emailed about it.</li>
    <li><strong>Follow up by WhatsApp</strong>: one click opens WhatsApp with the customer's number and a ready-made message that contains a personal link. The link rebuilds their cart on any phone or computer.</li>
    <li><strong>Offer a recovery coupon</strong>: create a one-time discount for one customer, send it by WhatsApp, and track whether they opened it and used it.</li>
</ul>
<p>Everything is managed from two screens: <?php echo $sessions; ?> (the day-to-day list) and <strong>WooCommerce → Checkout Tracker</strong> (settings and this documentation). Only users who can manage WooCommerce (Shop Managers and Administrators) can see them.</p>
</section>

<section id="wct-doc-quick-start">
<h2>2. Quick start</h2>
<ol class="wct-doc-steps">
    <li>Make sure WooCommerce is active. The plugin starts tracking checkouts as soon as it is activated.</li>
    <li>Open <?php echo $link( '', 'General settings' ); ?>: choose after how many minutes an idle checkout counts as abandoned (default 60), and optionally turn on abandoned email alerts.</li>
    <li>Open <?php echo $link( 'whatsapp', 'WhatsApp & Cart Recovery' ); ?>: check the default country code (for Bangladesh <code>880</code>), adjust the message template, and choose where the restore link lands (Checkout or Cart).</li>
    <li>Optionally open <?php echo $link( 'coupon', 'Recovery Coupon' ); ?>: set your default discount and write the coupon message.</li>
    <li>Add a short notice to your checkout page telling customers that checkout details may be saved to help them complete their order (see <a href="#wct-doc-privacy">Privacy</a>).</li>
    <li>Go to <?php echo $sessions; ?>. Abandoned checkouts appear there; click <strong>WhatsApp</strong> to follow up.</li>
</ol>
</section>

<section id="wct-doc-lifecycle">
<h2>3. How a checkout session works</h2>
<p>A <strong>checkout session</strong> is one shopper's visit to your checkout page. Every session has one status:</p>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Status</th><th>Meaning</th><th>How it changes</th></tr></thead>
<tbody>
<tr><td><span class="wct-status wct-initiated">Initiated</span></td><td>The shopper is on checkout, or was recently active there.</td><td>Starts when the checkout page opens.</td></tr>
<tr><td><span class="wct-status wct-abandoned">Abandoned</span></td><td>No checkout activity for longer than your timeout (currently <strong><?php echo esc_html( $s['abandon_timeout_minutes'] ); ?> minutes</strong>).</td><td>Set by the background task that runs every 15 minutes. If the shopper comes back, the session becomes Initiated again.</td></tr>
<tr><td><span class="wct-status wct-converted">Converted</span></td><td>An order was placed from this session.</td><td>Set the moment the order is created (classic checkout and Checkout block). It is labelled <em>with coupon</em> or <em>without coupon</em>, and shows <em>order cancelled</em> / <em>payment failed</em> if that order did not complete.</td></tr>
</tbody></table>
<ul>
    <li><strong>Activity</strong> means typing or changing fields, cart changes, or using the page (mouse, keyboard, scrolling). An open tab that nobody touches, or a hidden tab, is not activity.</li>
    <li><strong>A returning customer</strong> who already ordered starts a <em>new</em> session on their next checkout, so an order's record is never overwritten.</li>
    <li>A session is linked to the shopper's browser by a cookie for 30 days. A restore link (see <a href="#wct-doc-restore">section 8</a>) can attach another device to the same session.</li>
</ul>
</section>

<section id="wct-doc-data">
<h2>4. What is captured (and what never is)</h2>
<h3>Captured</h3>
<ul>
    <li>Every field on the checkout page as the shopper types it, including browser autofill: name, email, phone, addresses, order notes, the chosen payment method, delivery options and fields added by other plugins.</li>
    <li>The cart: products, variations (size, colour…), quantities, prices and total.</li>
    <li><strong>Logged-in customers</strong>: the session is flagged <em>Logged in</em> and linked to the customer's account. Their name, email and phone are filled in from their account (WooCommerce billing details, or the account email and name) even if they never touch a checkout field. Anything they type at checkout replaces the account value and is tracked as usual. Guests are tracked from what they type (and browser autofill) only.</li>
    <li>Timestamps (started, last activity, abandoned, converted), the order number, the browser type, and a one-way hash of the IP address (the IP itself is not stored).</li>
</ul>
<h3>Never stored or emailed</h3>
<ul>
    <li>Card numbers, CVV/CVC, expiry dates, PINs, passwords, bank account details, payment tokens, one-time codes and CAPTCHA answers. These are filtered out in the browser and again on the server.</li>
    <li>Anything typed inside a payment gateway's own box.</li>
    <li>A number that looks like a card number typed into any other field (for example the order notes) is replaced with <code>[redacted]</code>. Phone fields are exempt, so long phone numbers are not affected.</li>
</ul>
<p class="wct-doc-note">Old records are deleted automatically after the retention period (currently <strong><?php echo esc_html( $s['retention_days'] ); ?> days</strong>). Recovery coupon history is kept, because orders refer to it.</p>
</section>

<section id="wct-doc-sessions-screen">
<h2>5. The Checkout Sessions screen</h2>
<p><?php echo $sessions; ?> shows totals for each status at the top, a search box (name, phone, email or session key), a status filter and a customer filter (<em>All customers</em>, <em>Logged-in customers</em>, <em>Guests</em>), then the list, newest activity first, 25 per page. On narrow screens the table scrolls sideways.</p>
<h3>Columns</h3>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Column</th><th>What it shows</th></tr></thead>
<tbody>
<tr><td>Session</td><td>The session ID and start time. Click it (or <strong>View</strong>) to open the details popup.</td></tr>
<tr><td>Customer / Contact</td><td>Name, email and phone as typed at checkout (or from the order once converted), with a <span class="wct-ctype wct-ctype--account">Logged in</span> or <span class="wct-ctype">Guest</span> badge. Details tagged <span class="wct-src">account</span> came from a logged-in customer's account rather than from the checkout form.</td></tr>
<tr><td>Items / Total</td><td>Number of items and the cart total when last seen.</td></tr>
<tr><td>Status</td><td>Initiated / Abandoned / Converted, plus <em>with coupon RCV-…</em> or <em>without coupon</em>, and <em>order cancelled</em> or <em>payment failed</em> when relevant.</td></tr>
<tr><td>Last Activity / Order</td><td>When the shopper was last active; the order number with a link to the order.</td></tr>
<tr><td>Coupon</td><td><strong>Add Coupon</strong>, or the latest coupon's code, discount, expiry and status with a <strong>Revoke</strong> link while it is active. Shown only when recovery coupons are on.</td></tr>
<tr><td>WhatsApp</td><td><strong>WhatsApp</strong> and (once a coupon exists) <strong>WhatsApp + Coupon</strong> buttons, how often each was sent and by whom, and whether the customer opened the link. <em>No phone</em> means there is no valid number; <em>—</em> means the session already converted.</td></tr>
</tbody></table>
<h3>The details popup</h3>
<p>Opens over the list without leaving the page (a link like <code>…&amp;view=12</code>, for example from an alert email, opens it directly). It closes only with the <strong>×</strong> button. Tabs:</p>
<ul>
    <li><strong>Overview</strong>: customer (including <em>Customer type</em>: logged-in customer or guest, and a link to the account), session timeline, order and cart summary.</li>
    <li><strong>Checkout fields</strong>: everything captured, grouped into Billing &amp; contact, Shipping, and Order &amp; other, with a filter box. Sensitive values show as <em>Redacted</em>.</li>
    <li><strong>Cart</strong>: products, options, quantities and prices.</li>
    <li><strong>WhatsApp</strong>: the number used, a preview of the normal message, the send buttons, and every link sent with its open history.</li>
    <li><strong>Coupon</strong>: the active coupon with Add / Revoke, and the full history of every coupon for this session.</li>
    <li><strong>Technical</strong>: session key, hashed IP, browser, and record dates.</li>
</ul>
<p>Times are shown in your site's time zone; hover a time to see it in UTC.</p>
</section>

<section id="wct-doc-settings">
<h2>6. Settings reference</h2>
<p>All settings are under <strong>WooCommerce → Checkout Tracker</strong>. Each tab saves only its own settings.</p>

<h3>General</h3>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Setting</th><th>What it does</th><th>Default</th><th>Current</th></tr></thead>
<tbody>
<tr><td>Capture checkout sessions</td><td>Turns tracking on the checkout page on or off. Existing records stay.</td><td>On</td><td><?php echo $on( ! empty( $s['enabled'] ) ); ?></td></tr>
<tr><td>Abandoned checkout timeout</td><td>Minutes without activity before an Initiated session becomes Abandoned (5 to 10,080).</td><td>60</td><td><?php echo esc_html( $s['abandon_timeout_minutes'] ); ?></td></tr>
<tr><td>Abandoned email alerts</td><td>Emails the recipients when a session becomes Abandoned (rules in <a href="#wct-doc-alerts">section 11</a>).</td><td>Off</td><td><?php echo $on( ! empty( $s['email_alerts_enabled'] ) ); ?></td></tr>
<tr><td>Alert recipients</td><td>One or more addresses separated by commas, semicolons or spaces.</td><td>Site admin email</td><td><?php echo $val( $s['email_alert_recipients'] ); ?></td></tr>
<tr><td>Retention (days)</td><td>Sessions older than this are deleted automatically (1 to 3,650).</td><td>90</td><td><?php echo esc_html( $s['retention_days'] ); ?></td></tr>
</tbody></table>

<h3>WhatsApp &amp; Cart Recovery</h3>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Setting</th><th>What it does</th><th>Default</th><th>Current</th></tr></thead>
<tbody>
<tr><td>WhatsApp messages</td><td>Shows the WhatsApp buttons in the list and popup.</td><td>On</td><td><?php echo $on( ! empty( $s['whatsapp_enabled'] ) ); ?></td></tr>
<tr><td>Default country code</td><td>Added to numbers typed without one (<code>01670019801</code> → <code>+8801670019801</code>). If the customer chose a billing/shipping country, that country's code is used instead.</td><td>880</td><td>+<?php echo esc_html( $s['whatsapp_country_code'] ); ?></td></tr>
<tr><td>Message template</td><td>The normal WhatsApp message. Use the placeholders below; the preview shows sample data.</td><td>Short reminder with the restore link</td><td><?php echo $link( 'whatsapp', 'View / edit' ); ?></td></tr>
<tr><td>Cart restore destination</td><td>Where customers land after opening a restore link: Checkout or Cart. Takes effect immediately, including for links already sent.</td><td>Checkout</td><td><?php echo esc_html( 'cart' === $s['restore_destination'] ? 'Cart' : 'Checkout' ); ?></td></tr>
<tr><td>Restore link valid for</td><td>Days a newly sent link keeps working (1 to 30). Links already sent keep their own expiry.</td><td>7</td><td><?php echo esc_html( $s['restore_link_days'] ); ?> days</td></tr>
</tbody></table>
<h4>Placeholders for the WhatsApp message</h4>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Placeholder</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td><code>{first_name}</code></td><td>The customer's first name, or "there" if unknown ("Hi there").</td></tr>
<tr><td><code>{customer_name}</code></td><td>Full name.</td></tr>
<tr><td><code>{cart_items}</code></td><td>Items the link can still restore, e.g. "2 × T-shirt (M, Blue), 1 × Mug". Sold-out, deleted or hidden products are left out, quantities are capped to stock, and after 5 products it adds "and N more".</td></tr>
<tr><td><code>{cart_total}</code></td><td>Total of those items at today's prices.</td></tr>
<tr><td><code>{cart_restore_url}</code></td><td>The customer's personal restore link (<a href="#wct-doc-restore">section 8</a>).</td></tr>
<tr><td><code>{site_name}</code></td><td>Your store name.</td></tr>
</tbody></table>

<h3>Recovery Coupon</h3>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Setting</th><th>What it does</th><th>Default</th><th>Current</th></tr></thead>
<tbody>
<tr><td>Recovery coupons</td><td>Shows the Coupon column, Add Coupon and the WhatsApp + Coupon button.</td><td>On</td><td><?php echo $on( ! empty( $s['coupon_enabled'] ) ); ?></td></tr>
<tr><td>Default coupon type</td><td>Percentage or fixed amount.</td><td>Percentage</td><td><?php echo esc_html( 'fixed' === $s['coupon_type'] ? 'Fixed amount' : 'Percentage' ); ?></td></tr>
<tr><td>Default discount value</td><td>The percentage (up to 100) or amount in <?php echo $cur; ?>.</td><td>10</td><td><?php echo esc_html( $s['coupon_amount'] ); ?></td></tr>
<tr><td>Minimum cart value</td><td>Optional. Below it the coupon does not apply (<a href="#wct-doc-coupons">section 9</a>).</td><td>None</td><td><?php echo $val( $s['coupon_min_cart'] ); ?></td></tr>
<tr><td>Maximum discount</td><td>Optional, percentage coupons only: the most the coupon can take off.</td><td>None</td><td><?php echo $val( $s['coupon_max_discount'] ); ?></td></tr>
<tr><td>Default validity</td><td>Hours a new coupon stays valid (1 to 720).</td><td>24</td><td><?php echo esc_html( $s['coupon_validity_hours'] ); ?> hours</td></tr>
<tr><td>Coupon WhatsApp message</td><td>Used only by <strong>WhatsApp + Coupon</strong>; separate from the normal message.</td><td>Offer with code, expiry and link</td><td><?php echo $link( 'coupon', 'View / edit' ); ?></td></tr>
<tr><td>Coupon offer popup</td><td>After a coupon link restores the cart, shows the customer their cart amount, discount and new total.</td><td>On</td><td><?php echo $on( ! empty( $s['coupon_offer_modal'] ) ); ?></td></tr>
</tbody></table>
<p>These are only <strong>defaults</strong>: each time you click Add Coupon you can change the values for that one customer.</p>
<h4>Extra placeholders for the coupon message</h4>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Placeholder</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td><code>{coupon_code}</code></td><td>The code, e.g. <code>RCV-X82K9QPA</code>.</td></tr>
<tr><td><code>{coupon_discount}</code></td><td>"10%", "10% (up to <?php echo $cur; ?>500.00)" or "<?php echo $cur; ?>200.00".</td></tr>
<tr><td><code>{coupon_expires}</code></td><td>When the coupon expires, in your site's time.</td></tr>
<tr><td><code>{coupon_restore_url}</code></td><td>The personal link that restores the cart <em>and</em> applies the coupon.</td></tr>
</tbody></table>
<p>All normal placeholders work in the coupon message too. In the coupon message <code>{cart_items}</code> lists <strong>one product per line</strong> (after 5 products it adds "+ N more"), so put it on its own line, as the default message does:</p>
<pre class="wct-doc-pre">You left these items in your cart at {site_name}:
{cart_items}

Here is {coupon_discount} off, just for you: {coupon_code}</pre>
</section>

<section id="wct-doc-whatsapp">
<h2>7. Following up by WhatsApp</h2>
<ol class="wct-doc-steps">
    <li>In <?php echo $sessions; ?>, filter by <strong>Abandoned</strong>.</li>
    <li>Click <strong>WhatsApp</strong> on a session. A new tab opens WhatsApp (Web, Desktop app or phone) with the number and your message filled in.</li>
    <li>Read the message, edit it if you like, and press <strong>Send</strong> in WhatsApp. The plugin cannot send it for you.</li>
    <li>Back in the list you will see "WhatsApp: 1× by <em>your name</em>", and later "Link opened …" once the customer opens it.</li>
</ol>
<ul>
    <li>Each click creates a new personal link; earlier links keep working until they expire.</li>
    <li>If a popup blocker stops the new tab, allow popups for your admin site.</li>
    <li>No button? The session has no valid phone number, it already converted, or WhatsApp is turned off in settings.</li>
</ul>
</section>

<section id="wct-doc-restore">
<h2>8. The cart restore link</h2>
<p>The link in your message (for example <code><?php echo esc_html( home_url( '/?wct-restore=…' ) ); ?></code>) rebuilds the customer's cart and takes them straight to the <?php echo esc_html( 'cart' === $s['restore_destination'] ? 'Cart' : 'Checkout' ); ?> page. There is no extra page to click through.</p>
<ul>
    <li><strong>Works anywhere</strong>: on another phone, computer or browser, and without logging in.</li>
    <li><strong>Private and safe</strong>: the link holds only a long random code. It does not reveal the customer's details, cannot be guessed, and expires.</li>
    <li><strong>Today's prices and stock</strong>: products come back at their current price. If there is less stock than they wanted, the quantity is reduced and the customer is told.</li>
    <li><strong>Unavailable products</strong> (sold out, deleted, hidden) are skipped with a message; the rest are restored. If nothing can be restored, the customer lands on the cart page with an explanation.</li>
    <li><strong>An existing cart is kept</strong>: items already in their cart stay. For a product in both, the quantity becomes the larger of the two, so opening the link twice never doubles anything.</li>
    <li><strong>Orders are credited correctly</strong>: an order placed after using the link is linked to the original abandoned session.</li>
    <li><strong>After an order</strong>, the link no longer restores anything. It shows "This order has already been placed. Thank you!", or explains that the order was cancelled or its payment failed.</li>
    <li><strong>Link previews</strong> (WhatsApp, Facebook, Telegram and similar apps fetch links to build a preview) never restore a cart and are not counted as opens.</li>
    <li><strong>Testing as staff</strong>: if you open a link while logged in as a shop manager, your cart is filled, but it is not counted as the customer opening it and does not touch their session.</li>
</ul>
</section>

<section id="wct-doc-coupons">
<h2>9. Recovery coupons</h2>
<h3>Creating and sending a coupon</h3>
<ol class="wct-doc-steps">
    <li>Click <strong>Add Coupon</strong> in the Coupon column (or in the popup's Coupon tab).</li>
    <li>The form is filled from your defaults. Change the type, discount, minimum, maximum or validity for this customer if needed, then click <strong>Generate coupon</strong>.</li>
    <li>A code like <code>RCV-X82K9QPA</code> is created and <strong>WhatsApp + Coupon</strong> appears next to WhatsApp. The normal WhatsApp button stays exactly as it was.</li>
    <li>Click <strong>WhatsApp + Coupon</strong> and press Send in WhatsApp. When the customer opens that link, their cart is restored, the coupon is applied, and (if enabled) they see the offer popup.</li>
</ol>
<h3>Rules</h3>
<ul>
    <li><strong>One active coupon per session.</strong> To give a different one, click <strong>Revoke</strong> first. An expired coupon frees the slot automatically.</li>
    <li><strong>One use only</strong>, even if two orders are placed at the same moment.</li>
    <li><strong>Personal</strong>: the code works only in a browser that opened this customer's coupon link. Typed anywhere else it is refused with "This coupon only works through the personal link it was sent with."</li>
    <li><strong>Cannot be combined</strong> with any other coupon. The coupon link replaces another coupon already in the cart and tells the customer.</li>
    <li><strong>Always on the current cart</strong>: the discount is calculated on the restored cart at today's prices, and recalculated whenever the cart changes. A maximum discount is re-checked on every change (10% of <?php echo $cur; ?>3,400 capped at <?php echo $cur; ?>300 gives <?php echo $cur; ?>300; of <?php echo $cur; ?>2,900 it gives <?php echo $cur; ?>290).</li>
    <li><strong>Minimum cart value</strong>: if the cart drops below it, the coupon is removed and the customer is told how much more to add. When the cart qualifies again, it is re-applied automatically with a message. If the customer removed the coupon themselves, or added a different coupon meanwhile, it is not forced back.</li>
    <li><strong>Expiry</strong>: after the validity period it stops working. A coupon link opened after that still restores the cart, without the discount, and says the offer has ended.</li>
    <li><strong>Used in an order</strong>: it becomes Used when the order is placed, and <strong>stays Used even if that order is cancelled or fails</strong>, so it can never be used twice. For a failed payment it remains valid only for paying that same order again.</li>
</ul>
<h3>Statuses</h3>
<table class="widefat striped wct-doc-table">
<thead><tr><th>Status</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><span class="wct-cstatus wct-cstatus--generated">Generated</span></td><td>Created, not yet sent.</td></tr>
<tr><td><span class="wct-cstatus wct-cstatus--sent">Sent</span></td><td>WhatsApp + Coupon was clicked at least once.</td></tr>
<tr><td><span class="wct-cstatus wct-cstatus--applied">Applied</span></td><td>The customer opened the link and the coupon is in their cart.</td></tr>
<tr><td><span class="wct-cstatus wct-cstatus--used">Used</span></td><td>An order was placed with it (the order and discount given are recorded).</td></tr>
<tr><td><span class="wct-cstatus wct-cstatus--expired">Expired</span></td><td>Its validity ran out before it was used.</td></tr>
<tr><td><span class="wct-cstatus wct-cstatus--revoked">Revoked</span></td><td>Ended early by staff.</td></tr>
</tbody></table>
<h3>The offer popup</h3>
<p>Shown once, after a coupon link restores the cart: <em>Cart <?php echo $cur; ?>3,400 · Discount 10% / <?php echo $cur; ?>300 · New total <?php echo $cur; ?>3,100</em>, with one <strong>OK</strong> button that simply closes the popup: the customer stays on the page, and the restored cart and coupon stay in place. The figures are the live cart before shipping. If the cart is below the minimum it says how much more to add instead.</p>
<p class="wct-doc-warn">Recovery coupons also appear in <strong>Marketing → Coupons</strong>, described as "Recovery coupon for checkout session #N". Do not edit or delete them there; manage them from the Checkout Sessions screen.</p>
</section>

<section id="wct-doc-tracking">
<h2>10. Tracking and history</h2>
<ul>
    <li><strong>WhatsApp sends</strong>: in the list, "WhatsApp: 2× by Admin · 3 hours ago" and "WhatsApp + Coupon: 1× by Staff · …".</li>
    <li><strong>Link opens</strong>: "Link opened 3× · last 5 min ago" or "Link not opened yet", plus "(tried N× after it expired)" when the customer used an old link. The popup's WhatsApp tab lists every link with first and last open and the device, e.g. <em>Android · Chrome</em>.</li>
    <li><strong>Coupon history</strong> (popup → Coupon tab): generated (with its settings and who created it), sent by WhatsApp, link opened, applied, removed or re-applied for the minimum, used in order #…, order cancelled or failed, expired, revoked.</li>
    <li><strong>Conversions</strong>: Converted <em>with coupon</em> or <em>without coupon</em>; for coupon conversions the coupon, discount, order and time are recorded.</li>
</ul>
<p class="wct-doc-note">"Sent" means the WhatsApp window was opened from here; the plugin cannot see whether you actually pressed Send. "Opened" means the customer's browser opened the link.</p>
</section>

<section id="wct-doc-alerts">
<h2>11. Abandoned email alerts</h2>
<p>When turned on, an email goes to the recipients when a session becomes Abandoned. To avoid noise, an alert is sent only if:</p>
<ul>
    <li>the session has an email or phone number and at least one cart item;</li>
    <li>it became Abandoned within the last 24 hours;</li>
    <li>that email address has not placed a paid or on-hold order since; and</li>
    <li>it has not been alerted before (one alert per session, even if the shopper comes back and leaves again).</li>
</ul>
<p>At most 25 alerts go out per run; the rest follow on the next run. Each email lists the customer, captured fields (sensitive ones removed), cart and a link that opens the session. Emails are sent with WordPress's mailer, so if they do not arrive, set up an SMTP plugin.</p>
</section>

<section id="wct-doc-scheduled">
<h2>12. Background tasks</h2>
<p>Every 15 minutes the plugin marks idle sessions Abandoned, sends alerts, expires coupons and removes records past the retention period. This uses WordPress's built-in scheduler, which runs when someone visits the site. On a quiet site tasks can run late; ask your host to set up a real "cron job" that calls <code>wp-cron.php</code> every 5 to 15 minutes.</p>
</section>

<section id="wct-doc-faq">
<h2>13. Troubleshooting and FAQ</h2>
<dl class="wct-doc-faq">
<dt>A session shows "No phone" instead of a WhatsApp button.</dt><dd>The number is missing or too short or long to be a real number. Check the Checkout fields tab. Numbers without a country code get your default code (currently +<?php echo esc_html( $s['whatsapp_country_code'] ); ?>).</dd>
<dt>The WhatsApp tab opens but the message is empty or the wrong person.</dt><dd>Check the template in <?php echo $link( 'whatsapp', 'WhatsApp & Cart Recovery' ); ?> and the phone number the customer typed. You can edit the message in WhatsApp before sending.</dd>
<dt>The customer says the link shows "invalid or has expired".</dt><dd>Links last <?php echo esc_html( $s['restore_link_days'] ); ?> days. Click WhatsApp again to send a fresh link.</dd>
<dt>The customer says the coupon "only works through the personal link".</dt><dd>They typed the code in a browser that did not open the coupon link. Ask them to open the link from your message; the coupon is then applied automatically.</dd>
<dt>The customer's coupon disappeared from their cart.</dt><dd>Their cart went below the minimum cart value; they were told how much to add, and it comes back automatically when they do. Or they removed it themselves; they can type the code again in the same browser.</dd>
<dt>I cannot add a second coupon for a session.</dt><dd>Only one active coupon is allowed. Revoke the current one first.</dd>
<dt>The customer cancelled their order. Can they reuse the coupon?</dt><dd>No. A coupon stays Used once an order with it was placed. Create a new coupon for the session if you want to offer another discount.</dd>
<dt>Sessions are not becoming Abandoned, or alerts are late.</dt><dd>The background task needs site traffic; see <a href="#wct-doc-scheduled">Background tasks</a>.</dd>
<dt>Alert emails do not arrive.</dt><dd>Check the recipients and turn alerts on in <?php echo $link( '', 'General' ); ?>. If WordPress emails in general do not arrive, install an SMTP plugin.</dd>
<dt>The admin screens look unstyled or buttons do nothing.</dt><dd>A cache is serving old files: clear your cache plugin or CDN and reload with Ctrl+F5.</dd>
<dt>A red notice says "WooCommerce Checkout Tracker is not running: …".</dt><dd>The plugin turned itself off to protect your site: a file is missing or damaged, it is installed twice, or PHP or WooCommerce is too old. The notice names the cause; re-upload the complete plugin or fix the version, and the notice disappears.</dd>
<dt>Does it work with the new Checkout block, HPOS and page caching?</dt><dd>Yes. Both the Checkout block and the classic shortcode checkout are supported, HPOS order storage is supported, and restore links are never cached (WooCommerce already keeps cart and checkout pages out of page caches).</dd>
</dl>
</section>

<section id="wct-doc-privacy">
<h2>14. Privacy and good practice</h2>
<ul>
    <li>This plugin stores what customers type at checkout before they order. In many countries you must tell customers this. Add a short line near the checkout form, for example: <em>"We save your checkout details so we can help you complete your order."</em>, and mention it in your privacy policy.</li>
    <li>Only message customers who expect to hear from you. Unsolicited WhatsApp messages can get your number reported or banned, and some countries require prior consent.</li>
    <li>Keep the retention period as short as is useful for you.</li>
</ul>
</section>

<section id="wct-doc-uninstall">
<h2>15. Deactivating and uninstalling</h2>
<ul>
    <li><strong>Deactivating</strong> stops tracking and the background task, and closes every recovery coupon that is still open (without the plugin nobody could check that a coupon is used only through its link). This cannot be undone; create new coupons after reactivating. Your data stays.</li>
    <li><strong>Deleting</strong> the plugin from the Plugins screen removes all its data: sessions, captured fields, carts, links, coupon history and settings. The WooCommerce coupons themselves stay, because orders refer to them.</li>
</ul>
</section>

<section id="wct-doc-requirements">
<h2>16. Requirements and compatibility</h2>
<ul>
    <li>WordPress 6.4 or newer, WooCommerce 7.2 or newer, PHP 7.4 or newer.</li>
    <li>Classic (shortcode) checkout and the Checkout block; WooCommerce High-Performance Order Storage (HPOS).</li>
    <li>If anything required is missing, the plugin switches itself off and shows a notice instead of affecting your site.</li>
</ul>
</section>

<section id="wct-doc-developers">
<h2>17. For developers</h2>
<ul>
    <li>Before uploading changes, run <code>npm run lint</code>. It checks every PHP and JavaScript file; a single PHP syntax error can take a WordPress site offline.</li>
    <li>Release a new version with <code>npm version patch</code>, <code>minor</code> or <code>major</code>. It runs the check, updates the version in the plugin header, <code>WCT_VERSION</code> and <code>readme.txt</code>, then commits and tags. Write release notes under <code>= Unreleased =</code> in <code>readme.txt</code> first.</li>
    <li>Changing the version runs the database update automatically on the next page load.</li>
</ul>
</section>

</div>
</div>
