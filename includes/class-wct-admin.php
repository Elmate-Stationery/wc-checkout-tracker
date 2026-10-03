<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_Admin {
    public static function init() {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_wct_save_settings',array(__CLASS__,'save_settings'));
        add_action('admin_post_wct_delete_session',array(__CLASS__,'delete_session'));
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
        add_action('wp_ajax_wct_session_detail',array(__CLASS__,'ajax_detail'));
        add_filter('plugin_action_links_'.plugin_basename(WCT_FILE),array(__CLASS__,'plugin_links'));
    }
    // Plugins screen: Settings | Documentation next to Deactivate.
    public static function plugin_links($links){
        array_unshift($links,'<a href="'.esc_url(admin_url('admin.php?page=wct-settings')).'">Settings</a>','<a href="'.esc_url(admin_url('admin.php?page=wct-settings&tab=docs')).'">Documentation</a>');
        return $links;
    }
    public static function menu() {
        add_submenu_page('woocommerce','Checkout Sessions','Checkout Sessions','manage_woocommerce','wct-checkouts',array(__CLASS__,'page'));
        add_submenu_page('woocommerce','Checkout Tracker Settings','Checkout Tracker','manage_woocommerce','wct-settings',array(__CLASS__,'settings_page'));
    }
    public static function assets($hook) {
        if(strpos($hook,'wct-')===false) return;
        wp_enqueue_style('wct-admin',WCT_URL.'assets/css/admin.css',array('dashicons'),wct_asset_ver('assets/css/admin.css'));
        if(strpos($hook,'wct-settings')!==false){
            // Sample values for the live template preview on the WhatsApp tab.
            wp_enqueue_script('wct-settings',WCT_URL.'assets/js/settings.js',array(),wct_asset_ver('assets/js/settings.js'),true);
            wp_localize_script('wct-settings','WCTSettings',array('couponItems'=>"1 × Y-Plus+ Ray Triangle 12 mm Pencil - 1 Pc
1 × Kidmate 3104 Pencil Sharpener
1 × DOMS Erasener Sharpener + Eraser",'sample'=>array(
                '{first_name}'=>'Rahim','{customer_name}'=>'Rahim Uddin','{cart_items}'=>'2 × Peanut Crayon 12pc Box, 1 × ',
                '{cart_total}'=>trim(str_replace("\xC2\xA0",' ',html_entity_decode(wp_strip_all_tags(wc_price(5850)),ENT_QUOTES,'UTF-8'))),
                '{cart_restore_url}'=>WCT_Recovery::restore_url('sAmPlE-tOkEn-sAmPlE-tOkEn-sAmPlE-tOkEn-sAmP'),
                '{site_name}'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES),
                '{coupon_code}'=>'RCV-X82K9QPA','{coupon_discount}'=>'10%','{coupon_expires}'=>wp_date(get_option('date_format').' '.get_option('time_format'),time()+DAY_IN_SECONDS),
                '{coupon_restore_url}'=>WCT_Recovery::restore_url('sAmPlE-cOuPoN-sAmPlE-cOuPoN-sAmPlE-cOuPoN-s'),
            )));
            return;
        }
        if(strpos($hook,'wct-checkouts')===false) return;
        wp_enqueue_script('wct-admin',WCT_URL.'assets/js/admin.js',array(),wct_asset_ver('assets/js/admin.js'),true);
        // openId: links like admin.php?page=wct-checkouts&view=12 (e.g. from alert emails) open that session's modal.
        wp_localize_script('wct-admin','WCTAdmin',array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wct_admin'),'openId'=>isset($_GET['view'])?absint($_GET['view']):0,
            'coupon'=>WCT_Coupons::defaults()+array('currency'=>html_entity_decode(get_woocommerce_currency_symbol(),ENT_QUOTES,'UTF-8'))));
    }
    // Logged-in customer vs guest, and which details came from the customer account instead of checkout fields.
    private static function from_account($r,$field){ return !empty($r->account_fields)&&in_array($field,explode(',',$r->account_fields),true); }
    private static function account_tag($r,$field){ return self::from_account($r,$field)?' <span class="wct-src" title="From the customer'."'".'s account, not typed at checkout">account</span>':''; }
    private static function customer_type($r){ return !empty($r->is_logged_in)?'<span class="wct-ctype wct-ctype--account">Logged in</span>':'<span class="wct-ctype">Guest</span>'; }
    private static function status_label($s){ return ucwords(str_replace('_',' ',$s)); }
    // Works with both HPOS and legacy post-based order storage.
    private static function order_link($order_id){
        $order_id=absint($order_id); if(!$order_id) return '—';
        if(is_callable(array('Automattic\WooCommerce\Utilities\OrderUtil','get_order_admin_edit_url'))) $url=\Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($order_id);
        else { $order=wc_get_order($order_id); $url=$order?$order->get_edit_order_url():admin_url('post.php?post='.$order_id.'&action=edit'); }
        return '<a href="'.esc_url($url).'">#'.$order_id.'</a>';
    }
    const WA_ICON='<svg class="wct-wa-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.8 14.02c-.24.69-1.42 1.32-1.96 1.37-.5.05-1.13.07-1.83-.11-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.37-.14-.19-1.18-1.57-1.18-3 0-1.43.75-2.13 1.02-2.42.26-.29.57-.36.77-.36h.55c.18 0 .42-.07.65.5.24.57.82 1.99.89 2.13.07.14.12.31.02.5-.1.19-.14.31-.29.48-.14.17-.3.37-.43.5-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.41.29.14.46.12.63-.07.17-.19.72-.84.91-1.13.19-.29.38-.24.65-.14.26.1 1.69.8 1.98.94.29.14.48.22.55.34.07.12.07.69-.17 1.37z"/></svg>';
    // "2× by Admin · 3 hours ago"
    private static function sends_label($count,$at,$by){
        if(!(int)$count||!$at) return '';
        $user=$by?get_userdata($by):false;
        return sprintf('%d× %s· %s ago',$count,$user?'by '.$user->display_name.' ':'',human_time_diff(strtotime($at.' UTC')));
    }
    // [WhatsApp] (normal template) and, once a coupon exists, [WhatsApp + Coupon] (coupon template), each with
    // its send count and whether its link was opened. Links are created on click (WCT_Recovery::ajax_*).
    public static function whatsapp_cell($r,$country_fields=null,$coupon=false,$stats=null){
        $id=absint($r->id); $open='<div class="wct-wa-cell" data-wa-cell="'.$id.'">';
        if('converted'===$r->status) return $open.'<span class="wct-muted" title="Already ordered">—</span></div>';
        if(''===WCT_Recovery::number_for($r,$country_fields)) return $open.'<span class="wct-muted" title="No valid phone number">No phone</span></div>';
        if(false===$coupon) $coupon=WCT_Coupons::enabled()?WCT_Coupons::active_for_session($id):null;
        if(null===$stats){ $all=WCT_Recovery::link_stats(array($id)); $stats=$all[$id]??array(); }
        $html=$open.'<div class="wct-wa-buttons"><button type="button" class="button button-small wct-wa" data-id="'.$id.'" data-kind="cart" title="Send the normal WhatsApp message to '.esc_attr($r->phone).'">'.self::WA_ICON.'<span>WhatsApp</span></button>';
        if(WCT_Coupons::is_usable($coupon)) $html.='<button type="button" class="button button-small wct-wa wct-wa--coupon" data-id="'.$id.'" data-kind="coupon" title="Send coupon '.esc_attr($coupon->code).' by WhatsApp">'.self::WA_ICON.'<span>WhatsApp + Coupon</span></button>';
        $html.='</div>';
        $lines=array();
        if($l=self::sends_label($r->whatsapp_contact_count,$r->whatsapp_contacted_at,$r->whatsapp_contacted_by)) $lines[]='<strong>WhatsApp:</strong> '.esc_html($l).(isset($stats['cart'])?'<br><span class="wct-open-state'.((int)$stats['cart']->opens?' is-opened':'').'">'.esc_html(WCT_Recovery::open_label($stats['cart'])).'</span>':'');
        if($l=self::sends_label($r->whatsapp_coupon_contact_count,$r->whatsapp_coupon_contacted_at,$r->whatsapp_coupon_contacted_by)) $lines[]='<strong>WhatsApp + Coupon:</strong> '.esc_html($l).(isset($stats['coupon'])?'<br><span class="wct-open-state'.((int)$stats['coupon']->opens?' is-opened':'').'">'.esc_html(WCT_Recovery::open_label($stats['coupon'])).'</span>':'');
        if($lines) $html.='<small class="wct-wa-status">'.implode('<br>',$lines).'</small>';
        return $html.'</div>';
    }
    private static function coupon_badge($row){
        $st=WCT_Coupons::display_status($row);
        return '<span class="wct-cstatus wct-cstatus--'.esc_attr($st).'">'.esc_html(WCT_Coupons::STATUSES[$st]??ucfirst($st)).'</span>';
    }
    // Latest coupon (code, discount, expiry, status) or [Add Coupon].
    public static function coupon_cell($r,$coupon=false){
        $id=absint($r->id); $html='<div class="wct-coupon-cell" data-coupon-cell="'.$id.'">';
        if(false===$coupon){ $all=WCT_Coupons::latest_for_sessions(array($id)); $coupon=$all[$id]??null; }
        if($coupon){
            $st=WCT_Coupons::display_status($coupon);
            $html.='<code class="wct-code">'.esc_html($coupon->code).'</code> '.self::coupon_badge($coupon).'<small class="wct-coupon-meta">'.esc_html(WCT_Coupons::discount_label($coupon)).' · ';
            if('used'===$st){ $outcome=WCT_Coupons::order_outcome($coupon->order_id); $html.='used on order '.self::order_link($coupon->order_id).($outcome?' <span class="wct-order-ended">('.esc_html($outcome).')</span>':''); }
            elseif(in_array($st,array('expired','revoked'),true)) $html.=esc_html(ucfirst($st).' '.human_time_diff(strtotime(($coupon->revoked_at?:($coupon->expired_at?:$coupon->expires_at)).' UTC')).' ago');
            else $html.=esc_html('expires '.WCT_Coupons::expires_label($coupon));
            $html.='</small>';
            if(WCT_Coupons::is_usable($coupon)) $html.='<button type="button" class="button-link wct-coupon-revoke" data-id="'.$id.'">Revoke</button>';
        }
        if('converted'!==$r->status&&WCT_Coupons::enabled()&&!WCT_Coupons::is_usable($coupon)) $html.='<button type="button" class="button button-small wct-coupon-add" data-id="'.$id.'" aria-haspopup="dialog">Add Coupon</button>';
        elseif(!$coupon) $html.='<span class="wct-muted">—</span>';
        return $html.'</div>';
    }
    private static function status_cell($r,$coupon=null){
        $html='<span class="wct-status wct-'.esc_attr($r->status).'">'.esc_html(self::status_label($r->status)).'</span>';
        if('converted'===$r->status){ $outcome=WCT_Coupons::order_outcome($r->order_id); $html.='<small class="wct-conv">'.($r->converted_coupon_id&&$coupon&&(int)$coupon->id===(int)$r->converted_coupon_id?'with coupon <code>'.esc_html($coupon->code).'</code>':($r->converted_coupon_id?'with coupon':'without coupon')).($outcome?'<br><span class="wct-order-ended">order '.esc_html($outcome).'</span>':'').'</small>'; }
        return $html;
    }
    // Fresh table cells for one session, returned by the AJAX actions so the row updates in place.
    public static function cells($session_id){
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.WCT_DB::sessions_table().' WHERE id=%d',$session_id));
        if(!$r) return array();
        $latest=WCT_Coupons::latest_for_sessions(array($r->id));
        return array('waCell'=>self::whatsapp_cell($r),'couponCell'=>self::coupon_cell($r,$latest[(int)$r->id]??null),'statusCell'=>self::status_cell($r,$latest[(int)$r->id]??null));
    }
    public static function page() {
        if(!current_user_can('manage_woocommerce')) return;
        global $wpdb; $st=WCT_DB::sessions_table(); $per=25; $page=max(1,absint($_GET['paged']??1)); $offset=($page-1)*$per;
        $status=sanitize_key($_GET['status']??''); $search=sanitize_text_field($_GET['s']??''); $ctype=in_array($_GET['ctype']??'',array('account','guest'),true)?$_GET['ctype']:'';
        $where='WHERE 1=1'; $args=array(); if($status){$where.=' AND status=%s';$args[]=$status;} if($ctype){$where.='account'===$ctype?' AND is_logged_in=1':' AND is_logged_in=0';} if($search){$where.=' AND (email LIKE %s OR phone LIKE %s OR customer_name LIKE %s OR session_key LIKE %s)';$q='%'.$wpdb->esc_like($search).'%';$args=array_merge($args,array($q,$q,$q,$q));}
        $count_sql="SELECT COUNT(*) FROM $st $where"; $total=(int)$wpdb->get_var($args?$wpdb->prepare($count_sql,$args):$count_sql);
        $args2=$args; $args2[]=$offset; $args2[]=$per; $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $st $where ORDER BY last_activity_at DESC LIMIT %d,%d",$args2));
        $s=WCT_Tracker::settings(); $wa=!empty($s['whatsapp_enabled']); $cp=WCT_Coupons::enabled(); $ids=$rows?wp_list_pluck($rows,'id'):array();
        // One query each for the whole page: country fields (phone numbers), coupons, link-open stats.
        $countries=$wa&&$ids?WCT_Recovery::session_fields($ids,WCT_Recovery::COUNTRY_KEYS):array(); $coupons=WCT_Coupons::latest_for_sessions($ids); $stats=$wa?WCT_Recovery::link_stats($ids):array();
        $cols=9+($wa?1:0)+($cp?1:0);
        echo '<div class="wrap wct-wrap"><h1>WooCommerce Checkout Sessions</h1><p>Tracks checkout starts, field activity, cart snapshots and converted orders. <a href="'.esc_url(admin_url('admin.php?page=wct-settings&tab=docs')).'">Help &amp; documentation</a></p>';
        echo '<div class="wct-cards">'; foreach(array('initiated','abandoned','converted') as $x){$n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st WHERE status=%s",$x)); echo '<div class="wct-card"><strong>'.esc_html(self::status_label($x)).'</strong><span>'.esc_html($n).'</span></div>'; } echo '</div>';
        echo '<form method="get" class="wct-filters"><input type="hidden" name="page" value="wct-checkouts"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Search name, phone, email, session..."><select name="status"><option value="">All statuses</option>'; foreach(array('initiated','abandoned','converted') as $x) echo '<option value="'.esc_attr($x).'" '.selected($status,$x,false).'>'.esc_html(self::status_label($x)).'</option>'; echo '</select><select name="ctype" aria-label="Customer type"><option value="">All customers</option><option value="account" '.selected($ctype,'account',false).'>Logged-in customers</option><option value="guest" '.selected($ctype,'guest',false).'>Guests</option></select><button class="button">Filter</button></form>';
        echo '<div class="wct-table-scroll"><table class="widefat striped wct-sessions"><thead><tr><th>Session</th><th>Customer</th><th>Contact</th><th>Items</th><th>Total</th><th>Status</th><th>Last Activity</th><th>Order</th>'.($cp?'<th class="wct-coupon-col">Coupon</th>':'').($wa?'<th class="wct-wa-col">WhatsApp</th>':'').'<th class="wct-actions"><span class="screen-reader-text">Actions</span></th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="'.$cols.'">No checkout sessions found.</td></tr>';
        foreach($rows as $r){echo '<tr><td><a class="wct-open" data-id="'.absint($r->id).'" href="'.esc_url(admin_url('admin.php?page=wct-checkouts&view='.absint($r->id))).'"><strong>'.esc_html($r->session_key).'</strong></a><br><small>'.esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$r->started_at,true)).'</small></td><td>'.esc_html($r->customer_name?:'—').self::account_tag($r,'name').'<br>'.self::customer_type($r).'</td><td>'.esc_html($r->email?:'—').self::account_tag($r,'email').'<br>'.esc_html($r->phone?:'').($r->phone?self::account_tag($r,'phone'):'').'</td><td>'.esc_html($r->item_count).'</td><td>'.wp_kses_post(wc_price((float)$r->cart_total,array('currency'=>$r->currency?:get_woocommerce_currency()))).'</td><td data-status-cell="'.absint($r->id).'">'.self::status_cell($r,$coupons[(int)$r->id]??null).'</td><td>'.esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$r->last_activity_at,true)).'</td><td>'.self::order_link($r->order_id).'</td>'.($cp?'<td class="wct-coupon-col">'.self::coupon_cell($r,$coupons[(int)$r->id]??null).'</td>':'').($wa?'<td class="wct-wa-col">'.self::whatsapp_cell($r,$countries[(int)$r->id]??array(),$cp&&isset($coupons[(int)$r->id])&&WCT_Coupons::is_usable($coupons[(int)$r->id])?$coupons[(int)$r->id]:null,$stats[(int)$r->id]??array()).'</td>':'').'<td class="wct-actions"><button type="button" class="button button-small wct-open" data-id="'.absint($r->id).'" aria-haspopup="dialog">View</button></td></tr>';}
        echo '</tbody></table></div>';
        $pages=max(1,ceil($total/$per)); if($pages>1) echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$page,'total'=>$pages,'type'=>'plain'))).'</div></div>';
        echo '</div>';
        // Filled by assets/js/admin.js. Deliberately no Escape / backdrop close: only the close button.
        echo '<div id="wct-modal" class="wct-modal" hidden><div class="wct-modal__backdrop"></div><div class="wct-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wct-modal-title" tabindex="-1"><div class="wct-modal__header"><h2 id="wct-modal-title">Checkout session</h2><button type="button" class="wct-modal__close" aria-label="Close"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div><div class="wct-modal__body"></div></div></div>';
        if($cp) echo self::coupon_dialog();
    }
    // Add Coupon popup; defaults come from the Recovery Coupon settings (WCTAdmin.coupon), edits apply to this coupon only.
    private static function coupon_dialog(){
        return '<div id="wct-coupon-dialog" class="wct-modal wct-modal--small" hidden><div class="wct-modal__backdrop"></div><div class="wct-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wct-coupon-title" tabindex="-1"><div class="wct-modal__header"><h2 id="wct-coupon-title">Add coupon</h2><button type="button" class="wct-modal__close" data-coupon-close aria-label="Close"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>'
            .'<form class="wct-coupon-form" novalidate><div class="wct-modal__body wct-form"><p class="wct-form__intro">For checkout session <strong>#<span data-coupon-session></span></strong>. Values start from your Recovery Coupon settings; changes here apply to this coupon only.</p>'
            .'<fieldset class="wct-form__row"><legend>Coupon type</legend><label><input type="radio" name="type" value="percent"> Percentage</label><label><input type="radio" name="type" value="fixed"> Fixed amount</label></fieldset>'
            .'<label class="wct-form__row"><span>Discount <span class="wct-unit" data-unit></span></span><input type="number" name="amount" min="0" step="any" required></label>'
            .'<label class="wct-form__row"><span>Minimum cart value <em>optional</em></span><input type="number" name="min_cart" min="0" step="any" placeholder="No minimum"></label>'
            .'<label class="wct-form__row" data-max-row><span>Maximum discount <em>optional</em></span><input type="number" name="max_discount" min="0" step="any" placeholder="No maximum"></label>'
            .'<label class="wct-form__row"><span>Valid for (hours)</span><input type="number" name="validity_hours" min="1" max="720" step="1" required></label>'
            .'<p class="wct-form__error" role="alert" hidden></p></div><div class="wct-modal__footer"><button type="button" class="button" data-coupon-close>Cancel</button><button type="submit" class="button button-primary">Generate coupon</button></div></form></div></div>';
    }
    public static function ajax_detail(){
        if(!current_user_can('manage_woocommerce')||!check_ajax_referer('wct_admin','nonce',false)) wp_send_json_error(array('message'=>'You are not allowed to view checkout sessions, or your login has expired. Reload the page and try again.'),403);
        $id=absint($_POST['id']??0); $html=$id?self::render_detail($id):'';
        if(''===$html) wp_send_json_error(array('message'=>'This checkout session no longer exists. It may have been removed by the retention cleanup.'),404);
        wp_send_json_success(array('title'=>'Checkout session #'.$id,'html'=>$html));
    }
    // Stored times are UTC; shown in the site's timezone with the UTC value on hover.
    private static function date_html($utc){
        if(!$utc) return '<span class="wct-muted">—</span>';
        return '<time datetime="'.esc_attr(gmdate('c',strtotime($utc.' UTC'))).'" title="'.esc_attr($utc.' UTC').'">'.esc_html(get_date_from_gmt($utc,get_option('date_format').' '.get_option('time_format'))).'</time>';
    }
    private static function field_label($key){
        $label=preg_replace('/^(billing|shipping)[_-]/','',$key);
        return ucfirst(trim(preg_replace('/[_\-\[\]]+/',' ',$label)));
    }
    private static function dl($rows){
        $out='<dl class="wct-dl">';
        foreach($rows as $label=>$html) $out.='<dt>'.esc_html($label).'</dt><dd>'.(''!==(string)$html?$html:'<span class="wct-muted">—</span>').'</dd>';
        return $out.'</dl>';
    }
    const EVENT_LABELS=array(
        'coupon_generated'=>'Coupon generated','whatsapp_sent'=>'Sent by WhatsApp','link_opened'=>'Link opened by customer','link_opened_expired'=>'Link opened after it expired',
        'link_tested_by_staff'=>'Link opened by staff (not counted)','coupon_applied'=>'Applied to cart','coupon_removed_min'=>'Removed: cart below minimum',
        'coupon_reapplied'=>'Re-applied: cart meets minimum again','coupon_used'=>'Used in an order','coupon_released'=>'Released: order cancelled or failed','coupon_order_ended'=>'Order did not complete: coupon stays used',
        'coupon_expired'=>'Expired','coupon_revoked'=>'Revoked',
    );
    private static function user_name($id){ if(!$id) return ''; $u=get_userdata($id); return $u?$u->display_name:'#'.absint($id); }
    // Every restore link sent for the session: when, by whom, normal or coupon, and whether the customer opened it.
    private static function links_table($session_id){
        global $wpdb;
        $links=$wpdb->get_results($wpdb->prepare('SELECT t.*, c.code FROM '.WCT_DB::tokens_table().' t LEFT JOIN '.WCT_DB::coupons_table().' c ON c.id=t.coupon_id WHERE t.session_id=%d ORDER BY t.id DESC',$session_id));
        if(!$links) return '<p class="wct-muted">No links sent yet.</p>';
        $html='<table class="widefat striped wct-table"><thead><tr><th>Sent</th><th>Message</th><th>Opened by customer</th><th>Expires</th></tr></thead><tbody>';
        foreach($links as $l){
            $opened=(int)$l->use_count?sprintf('<strong class="wct-open-state is-opened">Opened %d×</strong><br>first %s<br>last %s',(int)$l->use_count,self::date_html($l->first_used_at),self::date_html($l->last_used_at)).($l->last_device?'<br><span class="wct-muted">'.esc_html($l->last_device).'</span>':''):'<span class="wct-open-state">Not opened yet</span>';
            if((int)$l->expired_open_count) $opened.='<br><span class="wct-muted">'.esc_html(sprintf('Tried %d× after it expired',$l->expired_open_count)).'</span>';
            $expired=strtotime($l->expires_at.' UTC')<=time();
            $html.='<tr><td data-label="Sent"><div>'.self::date_html($l->created_at).($l->created_by?'<br><span class="wct-muted">by '.esc_html(self::user_name($l->created_by)).'</span>':'').'</div></td><td data-label="Message"><div>'.($l->coupon_id?'WhatsApp + Coupon <code>'.esc_html($l->code).'</code>':'WhatsApp').'</div></td><td data-label="Opened"><div>'.$opened.'</div></td><td data-label="Expires"><div>'.self::date_html($l->expires_at).($expired?' <span class="wct-cstatus wct-cstatus--expired">Expired</span>':'').'</div></td></tr>';
        }
        return $html.'</tbody></table>';
    }
    private static function event_line($e){
        $d=$e->data?json_decode($e->data,true):array(); $bits=array();
        if(!empty($d['kind'])) $bits[]='coupon'===$d['kind']?'coupon link':'cart link';
        if(!empty($d['device'])) $bits[]=$d['device'];
        if(isset($d['restored'])) $bits[]=sprintf('%d item(s) restored',$d['restored']).(!empty($d['skipped'])?sprintf(', %d unavailable',$d['skipped']):'');
        if(!empty($d['coupon'])) $bits[]='coupon '.str_replace('_',' ',$d['coupon']);
        if(isset($d['discount'])) $bits[]='discount '.WCT_Recovery::plain_price($d['discount'],get_woocommerce_currency());
        if(isset($d['cart'])) $bits[]='cart '.WCT_Recovery::plain_price($d['cart'],get_woocommerce_currency());
        if(!empty($d['reason'])) $bits[]=$d['reason'];
        if(!empty($d['order_status'])) $bits[]='order '.$d['order_status'];
        if('coupon_generated'===$e->type&&!empty($d['code'])) $bits[]=sprintf('%s %s, valid %dh',$d['type'],$d['amount'],$d['validity_hours']);
        return '<li><span class="wct-tl__time">'.self::date_html($e->created_at).'</span> <strong>'.esc_html(self::EVENT_LABELS[$e->type]??$e->type).'</strong>'
            .($e->order_id?' '.self::order_link($e->order_id):'').($e->user_id?' <span class="wct-muted">by '.esc_html(self::user_name($e->user_id)).'</span>':'')
            .($bits?' <span class="wct-muted">· '.esc_html(implode(' · ',$bits)).'</span>':'').'</li>';
    }
    // Coupon tab: the active coupon with its actions, then every coupon ever generated for the session with its history.
    private static function coupon_panel($r,$coupons,$active){
        global $wpdb; $id=absint($r->id); $currency=get_woocommerce_currency();
        $html='<div class="wct-coupon-actions">';
        if($active) $html.='<p>Active coupon <code class="wct-code">'.esc_html($active->code).'</code> '.self::coupon_badge($active).' · '.esc_html(WCT_Coupons::discount_label($active)).' · expires '.esc_html(WCT_Coupons::expires_label($active)).'</p><button type="button" class="button-link wct-coupon-revoke" data-id="'.$id.'">Revoke coupon</button>';
        elseif('converted'!==$r->status&&WCT_Coupons::enabled()) $html.='<p class="wct-muted">No active coupon for this session.</p><button type="button" class="button button-primary wct-coupon-add" data-id="'.$id.'" aria-haspopup="dialog">Add Coupon</button>';
        $html.='</div>';
        if(!$coupons) return $html;
        $events=$wpdb->get_results('SELECT * FROM '.WCT_DB::events_table().' WHERE coupon_id IN ('.implode(',',array_map('absint',wp_list_pluck($coupons,'id'))).') ORDER BY id ASC'); // ids are absint()ed
        $by_coupon=array(); foreach($events as $e) $by_coupon[(int)$e->coupon_id][]=$e;
        foreach($coupons as $c){
            $st=WCT_Coupons::display_status($c);
            $html.='<div class="wct-box wct-coupon-card"><h3><code class="wct-code">'.esc_html($c->code).'</code> '.self::coupon_badge($c).'</h3>'.self::dl(array(
                'Discount'=>esc_html(WCT_Coupons::discount_label($c).('fixed'===$c->discount_type?' (fixed amount)':' (percentage)')),
                'Minimum cart'=>$c->min_cart?esc_html(WCT_Recovery::plain_price($c->min_cart,$currency)):'<span class="wct-muted">None</span>',
                'Maximum discount'=>$c->max_discount?esc_html(WCT_Recovery::plain_price($c->max_discount,$currency)):'<span class="wct-muted">None</span>',
                'Created'=>self::date_html($c->created_at).($c->created_by?' <span class="wct-muted">by '.esc_html(self::user_name($c->created_by)).'</span>':''),
                'Valid for'=>esc_html($c->validity_hours.' hours, until '.WCT_Coupons::expires_label($c)),
                'First sent'=>$c->sent_at?self::date_html($c->sent_at):'',
                'First applied'=>$c->applied_at?self::date_html($c->applied_at):'',
                'Used'=>'used'===$st?self::date_html($c->used_at).' · order '.self::order_link($c->order_id).(WCT_Coupons::order_outcome($c->order_id)?' <span class="wct-order-ended">('.esc_html(WCT_Coupons::order_outcome($c->order_id)).'; coupon stays used)</span>':'').' · discount '.esc_html(WCT_Recovery::plain_price($c->discount_total,$currency)):'',
                'Revoked'=>$c->revoked_at?self::date_html($c->revoked_at).($c->revoked_by?' <span class="wct-muted">by '.esc_html(self::user_name($c->revoked_by)).'</span>':'').($c->revoke_reason?' · '.esc_html($c->revoke_reason):''):'',
                'Expired'=>'expired'===$st?self::date_html($c->expired_at?:$c->expires_at):'',
            ));
            $html.='<h4>History</h4><ul class="wct-timeline">'.implode('',array_map(array(__CLASS__,'event_line'),$by_coupon[(int)$c->id]??array())).'</ul></div>';
        }
        return $html;
    }
    // All values are escaped here; captured field values go through the same redaction rules as capture and alert emails.
    private static function render_detail($id){
        global $wpdb; $st=WCT_DB::sessions_table(); $ft=WCT_DB::fields_table(); $it=WCT_DB::items_table();
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $st WHERE id=%d",$id)); if(!$r) return '';
        $fields=$wpdb->get_results($wpdb->prepare("SELECT * FROM $ft WHERE session_id=%d ORDER BY field_key",$id));
        $items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $it WHERE session_id=%d ORDER BY id",$id));
        $currency=$r->currency?:get_woocommerce_currency();
        $price=function($n) use ($currency){ return wp_kses_post(wc_price((float)$n,array('currency'=>$currency))); };
        $status='<span class="wct-status wct-'.esc_attr($r->status).'">'.esc_html(self::status_label($r->status)).'</span>';
        $name=esc_html(WCT_Tracker::redact_card_numbers('customer_name',(string)$r->customer_name));
        $email=$r->email?'<a href="mailto:'.esc_attr($r->email).'">'.esc_html($r->email).'</a>':'';
        $phone=$r->phone?'<a href="tel:'.esc_attr(preg_replace('/[^\d+]/','',$r->phone)).'">'.esc_html($r->phone).'</a>':'';

        // Checkout fields, grouped so long lists stay scannable.
        $groups=array('billing'=>array('Billing & contact',array()),'shipping'=>array('Shipping',array()),'other'=>array('Order & other',array()));
        foreach($fields as $f){
            $k=$f->field_key; $sensitive=WCT_Tracker::is_sensitive_key($k);
            $value=$sensitive?'':WCT_Tracker::redact_card_numbers($k,$f->field_value);
            $list=json_decode($value,true); if(is_array($list)) $value=implode(', ',array_map('strval',array_filter($list,'is_scalar')));
            $g=preg_match('/^(billing[_-]|(email|phone)$)/',$k)?'billing':(preg_match('/^shipping[_-](?!method)/',$k)?'shipping':'other');
            $label=self::field_label($k);
            $groups[$g][1][]='<tr class="wct-field-row" data-search="'.esc_attr(mb_strtolower($k.' '.$label.' '.$value)).'"><td data-label="Field"><div><strong>'.esc_html($label).'</strong><code>'.esc_html($k).'</code></div></td><td data-label="Value"><div>'.($sensitive?'<span class="wct-redacted">Redacted</span>':nl2br(esc_html($value))).'</div></td><td data-label="Updated"><div>'.self::date_html($f->updated_at).'</div></td></tr>';
        }
        $fields_html='';
        if($fields){
            $fields_html.='<div class="wct-field-toolbar"><label class="screen-reader-text" for="wct-field-filter">Filter fields</label><input type="search" id="wct-field-filter" class="wct-field-filter" placeholder="Filter fields by name or value…" autocomplete="off"></div>';
            foreach($groups as $g){
                if(!$g[1]) continue;
                $fields_html.='<section class="wct-field-group"><h3>'.esc_html($g[0]).' <span class="wct-count">'.count($g[1]).'</span></h3><table class="widefat striped wct-table"><thead><tr><th class="wct-col-field">Field</th><th>Value</th><th class="wct-col-updated">Updated</th></tr></thead><tbody>'.implode('',$g[1]).'</tbody></table></section>';
            }
            $fields_html.='<p class="wct-filter-empty wct-muted" hidden>No fields match this filter.</p>';
        } else $fields_html='<p class="wct-muted">No fields captured yet.</p>';

        // Cart snapshot.
        if($items){
            $cart_html='<table class="widefat striped wct-table"><thead><tr><th>Product</th><th>SKU</th><th class="wct-num">Qty</th><th class="wct-num">Unit price</th><th class="wct-num">Line total</th></tr></thead><tbody>';
            foreach($items as $i){
                $vars='';
                foreach((array)json_decode((string)$i->variation_json,true) as $attr=>$val){
                    if(!is_scalar($val)||''===(string)$val) continue;
                    $tax=preg_replace('/^attribute_/','',(string)$attr);
                    $vars.='<span class="wct-var">'.esc_html((function_exists('wc_attribute_label')?wc_attribute_label($tax):$tax).': '.$val).'</span>';
                }
                $cart_html.='<tr><td data-label="Product"><div><strong>'.esc_html($i->product_name).'</strong>'.$vars.'</div></td><td data-label="SKU">'.($i->sku?esc_html($i->sku):'<span class="wct-muted">—</span>').'</td><td data-label="Qty" class="wct-num">'.esc_html(wc_stock_amount($i->quantity)).'</td><td data-label="Unit price" class="wct-num">'.$price($i->unit_price).'</td><td data-label="Line total" class="wct-num">'.$price($i->line_total).'</td></tr>';
            }
            $cart_html.='</tbody><tfoot><tr><td colspan="4" class="wct-num">Cart total</td><td data-label="Cart total" class="wct-num">'.$price($r->cart_total).'</td></tr></tfoot></table>';
        } else $cart_html='<p class="wct-muted">No cart items.</p>';

        $user='';
        if($r->user_id){ $u=get_userdata($r->user_id); $user=$u?'<a href="'.esc_url(get_edit_user_link($u->ID)).'">'.esc_html($u->display_name).'</a> <span class="wct-muted">#'.absint($u->ID).'</span>':'#'.absint($r->user_id).' <span class="wct-muted">(deleted)</span>'; }
        $wa_html=''; $settings=WCT_Tracker::settings();
        $session_coupons=WCT_Coupons::for_session($id); $cp=WCT_Coupons::enabled();
        $active_coupon=null; foreach($session_coupons as $c) if(WCT_Coupons::is_usable($c)) $active_coupon=$c;
        if('converted'===$r->status){ $outcome=WCT_Coupons::order_outcome($r->order_id); $status.=' <small class="wct-conv">'.($r->converted_coupon_id?'with coupon':'without coupon').($outcome?' · <span class="wct-order-ended">order '.esc_html($outcome).'</span>':'').'</small>'; }
        if(!empty($settings['whatsapp_enabled'])){
            $number=WCT_Recovery::number_for($r);
            $preview=WCT_Recovery::message($r,'[secure cart link, created when you click WhatsApp]');
            $wa_html='<div class="wct-boxes"><div class="wct-box"><h3>WhatsApp</h3>'.self::dl(array(
                'Number'=>$number?esc_html('+'.$number):'<span class="wct-muted">No valid phone number</span>',
                'Cart restored'=>$r->restored_at?self::date_html($r->restored_at):'<span class="wct-muted">Not yet</span>',
            )).'<div class="wct-wa-send">'.self::whatsapp_cell($r,null,$cp?$active_coupon:null).'</div></div><div class="wct-box"><h3>Message preview</h3><div class="wct-wa-bubble">'.esc_html($preview).'</div><p class="description">Edit the templates under WooCommerce → Checkout Tracker.</p></div></div>'
                .'<h3 class="wct-section-title">Links sent</h3>'.self::links_table($id);
        }
        $coupon_html='';
        if($cp||$session_coupons) $coupon_html=self::coupon_panel($r,$session_coupons,$active_coupon);
        $alert=$r->abandoned_notified_at?self::date_html($r->abandoned_notified_at):'<span class="wct-muted">Not sent</span>';

        $tabs=array(
            'overview'=>array('Overview','<div class="wct-boxes">'
                .'<div class="wct-box"><h3>Customer</h3>'.self::dl(array('Customer type'=>self::customer_type($r).(!empty($r->is_logged_in)?' <span class="wct-muted">registered customer</span>':' <span class="wct-muted">not logged in</span>'),'Name'=>$name.($name?self::account_tag($r,'name'):''),'Email'=>$email.($email?self::account_tag($r,'email'):''),'Phone'=>$phone.($phone?self::account_tag($r,'phone'):''),'Account'=>$user?:'<span class="wct-muted">Guest</span>')).(!empty($r->account_fields)?'<p class="description wct-src-note">Details tagged <span class="wct-src">account</span> come from this logged-in customer&#8217;s account; they were not typed at checkout. Anything they type at checkout replaces them.</p>':'').'</div>'
                .'<div class="wct-box"><h3>Session</h3>'.self::dl(array('Status'=>$status,'Started'=>self::date_html($r->started_at),'Last activity'=>self::date_html($r->last_activity_at),'Abandoned at'=>$r->abandoned_at?self::date_html($r->abandoned_at):'','Converted at'=>$r->converted_at?self::date_html($r->converted_at):'','Order'=>$r->order_id?self::order_link($r->order_id):'','Alert email'=>$alert)).'</div>'
                .'<div class="wct-box"><h3>Cart</h3>'.self::dl(array('Products'=>esc_html(count($items)),'Items qty'=>esc_html(wc_stock_amount($r->item_count)),'Total'=>$price($r->cart_total),'Currency'=>esc_html($currency))).'</div>'
                .'</div>'),
            'fields'=>array('Checkout fields <span class="wct-count">'.count($fields).'</span>',$fields_html),
            'cart'=>array('Cart <span class="wct-count">'.count($items).'</span>',$cart_html),
            'whatsapp'=>array('WhatsApp',$wa_html),
            'coupon'=>array('Coupon'.($session_coupons?' <span class="wct-count">'.count($session_coupons).'</span>':''),$coupon_html),
            'technical'=>array('Technical','<div class="wct-box">'.self::dl(array('Session ID'=>'#'.absint($r->id),'Session key'=>'<code>'.esc_html($r->session_key).'</code>','IP address (hashed)'=>$r->ip_hash?'<code>'.esc_html($r->ip_hash).'</code>':'','User agent'=>esc_html((string)$r->user_agent),'Cart hash'=>$r->cart_hash?'<code>'.esc_html($r->cart_hash).'</code>':'','Created'=>self::date_html($r->created_at),'Updated'=>self::date_html($r->updated_at))).'</div>'),
        );

        $out='<div class="wct-summary">'.$status
            .'<span class="wct-summary__item"><strong>'.$price($r->cart_total).'</strong> · '.esc_html(wc_stock_amount($r->item_count)).' '.(1==$r->item_count?'item':'items').'</span>'
            .'<span class="wct-summary__item">'.($r->customer_name?$name:($r->email?esc_html($r->email):'Unknown customer')).'</span>'
            .'<span class="wct-summary__item">Last active '.esc_html(human_time_diff(strtotime($r->last_activity_at.' UTC'))).' ago</span>'
            .($r->order_id?'<span class="wct-summary__item">Order '.self::order_link($r->order_id).'</span>':'').'</div>';
        if(''===$wa_html) unset($tabs['whatsapp']);
        if(''===$coupon_html) unset($tabs['coupon']);
        $out.='<div class="wct-tabs" role="tablist" aria-label="Session details">'; $first=true;
        foreach($tabs as $key=>$t){ $out.='<button type="button" role="tab" class="wct-tab" id="wct-tab-'.$key.'" aria-controls="wct-panel-'.$key.'" aria-selected="'.($first?'true':'false').'" tabindex="'.($first?'0':'-1').'">'.$t[0].'</button>'; $first=false; }
        $out.='</div>'; $first=true;
        foreach($tabs as $key=>$t){ $out.='<div class="wct-panel" role="tabpanel" id="wct-panel-'.$key.'" aria-labelledby="wct-tab-'.$key.'" tabindex="0"'.($first?'':' hidden').'>'.$t[1].'</div>'; $first=false; }
        return $out;
    }
    public static function settings_page(){
        if(!current_user_can('manage_woocommerce')) return;
        $s=WCT_Tracker::settings(); $tab=in_array($_GET['tab']??'',array('whatsapp','coupon','docs'),true)?$_GET['tab']:'general';
        $url=function($t){ return esc_url(admin_url('admin.php?page=wct-settings'.('general'===$t?'':'&tab='.$t))); };
        echo '<div class="wrap wct-wrap"><h1>Checkout Tracker Settings</h1>';
        if(isset($_GET['wct_error'])) echo '<div class="notice notice-error"><p>Not saved: '.esc_html(wp_unslash($_GET['wct_error'])).'</p></div>';
        if(isset($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        echo '<nav class="nav-tab-wrapper wct-nav-tabs"><a href="'.$url('general').'" class="nav-tab'.('general'===$tab?' nav-tab-active':'').'">General</a><a href="'.$url('whatsapp').'" class="nav-tab'.('whatsapp'===$tab?' nav-tab-active':'').'">WhatsApp &amp; Cart Recovery</a><a href="'.$url('coupon').'" class="nav-tab'.('coupon'===$tab?' nav-tab-active':'').'">Recovery Coupon</a><a href="'.$url('docs').'" class="nav-tab'.('docs'===$tab?' nav-tab-active':'').'">Documentation</a></nav>';
        if('docs'===$tab){ include WCT_DIR.'includes/views/documentation.php'; echo '</div>'; return; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wct_save_settings"><input type="hidden" name="tab" value="'.esc_attr($tab).'">'.wp_nonce_field('wct_settings','wct_nonce',true,false).'<table class="form-table" role="presentation">';
        if('coupon'===$tab){
            $chips=''; foreach(WCT_Recovery::PLACEHOLDERS+WCT_Coupons::PLACEHOLDERS as $ph=>$help) if('{cart_restore_url}'!==$ph) $chips.='<button type="button" class="button button-small wct-chip" data-target="wct-coupon-template" data-insert="'.esc_attr($ph).'" title="'.esc_attr($help).'"><code>'.esc_html($ph).'</code></button>';
            $sym=esc_html(html_entity_decode(get_woocommerce_currency_symbol(),ENT_QUOTES,'UTF-8'));
            echo '<tr><th scope="row">Recovery coupons</th><td><label><input type="checkbox" name="coupon_enabled" value="1" '.checked(!empty($s['coupon_enabled']),true,false).'> Allow one-time recovery coupons for abandoned checkout sessions</label><p class="description">Adds a Coupon column with <em>Add Coupon</em>, and a <em>WhatsApp + Coupon</em> button once a coupon exists. The normal WhatsApp button and template are not changed.</p></td></tr>';
            echo '<tr><th scope="row">Default coupon type</th><td><fieldset><legend class="screen-reader-text">Default coupon type</legend><label><input type="radio" name="coupon_type" value="percent" '.checked('fixed'!==$s['coupon_type'],true,false).'> Percentage</label><br><label><input type="radio" name="coupon_type" value="fixed" '.checked('fixed'===$s['coupon_type'],true,false).'> Fixed amount ('.$sym.')</label></fieldset></td></tr>';
            echo '<tr><th scope="row"><label for="wct-c-amount">Default discount value</label></th><td><input type="number" id="wct-c-amount" name="coupon_amount" min="0" step="any" value="'.esc_attr($s['coupon_amount']).'" class="small-text"> <span class="description">% or '.$sym.', depending on the type</span></td></tr>';
            echo '<tr><th scope="row"><label for="wct-c-min">Minimum cart value</label></th><td><input type="number" id="wct-c-min" name="coupon_min_cart" min="0" step="any" value="'.esc_attr($s['coupon_min_cart']).'" class="small-text" placeholder="None"> '.$sym.'<p class="description">Optional. Measured on the current cart. Below it the coupon is removed with a message to the customer; once the cart qualifies again it is re-applied automatically, with a message.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wct-c-max">Maximum discount</label></th><td><input type="number" id="wct-c-max" name="coupon_max_discount" min="0" step="any" value="'.esc_attr($s['coupon_max_discount']).'" class="small-text" placeholder="None"> '.$sym.'<p class="description">Optional, percentage coupons only. Re-evaluated whenever the cart changes.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wct-c-hours">Default validity</label></th><td><input type="number" id="wct-c-hours" name="coupon_validity_hours" min="1" max="720" step="1" value="'.esc_attr($s['coupon_validity_hours']).'" class="small-text"> hours</td></tr>';
            echo '<tr><th scope="row"><label for="wct-coupon-template">Coupon WhatsApp message</label></th><td><textarea id="wct-coupon-template" class="large-text wct-template" data-preview="wct-coupon-preview" data-sample="coupon" name="coupon_template" rows="9" maxlength="2000">'.esc_textarea($s['coupon_template']).'</textarea><div class="wct-chips"><span class="description">Insert:</span> '.$chips.'</div><p class="description">Used only by <em>WhatsApp + Coupon</em>; independent from the normal WhatsApp template. <code>{coupon_restore_url}</code> restores the cart and applies the coupon.</p><div class="wct-preview"><span class="wct-preview__label">Preview with sample data</span><div class="wct-wa-bubble" id="wct-coupon-preview" aria-live="polite"></div></div></td></tr>';
            echo '<tr><th scope="row">Coupon offer popup</th><td><label><input type="checkbox" name="coupon_offer_modal" value="1" '.checked(!empty($s['coupon_offer_modal']),true,false).'> After a coupon link restores the cart, show the customer a popup with cart amount, discount and new total</label><p class="description">Always calculated from the current cart. The button continues to checkout.</p></td></tr>';
        } elseif('whatsapp'===$tab){
            $chips=''; foreach(WCT_Recovery::PLACEHOLDERS as $ph=>$help) $chips.='<button type="button" class="button button-small wct-chip" data-target="wct-template" data-insert="'.esc_attr($ph).'" title="'.esc_attr($help).'"><code>'.esc_html($ph).'</code></button>';
            $dest='cart'===$s['restore_destination']?'cart':'checkout';
            echo '<tr><th scope="row">WhatsApp messages</th><td><label><input type="checkbox" name="whatsapp_enabled" value="1" '.checked(!empty($s['whatsapp_enabled']),true,false).'> Show a WhatsApp button for checkout sessions</label><p class="description">Clicking it opens WhatsApp (Web, Desktop or phone) with the customer&#8217;s number and your message filled in; you press Send. Converted sessions and sessions without a valid phone have no button.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wct-cc">Default country code</label></th><td><span class="wct-cc-prefix">+</span><input type="text" id="wct-cc" name="whatsapp_country_code" value="'.esc_attr($s['whatsapp_country_code']).'" class="small-text" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4"><p class="description">Used for phone numbers entered without a country code (e.g. <code>01670019801</code> becomes <code>+8801670019801</code>), unless the customer picked a billing/shipping country at checkout.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wct-template">Message template</label></th><td><textarea id="wct-template" class="large-text wct-template" data-preview="wct-preview" name="whatsapp_template" rows="8" maxlength="2000">'.esc_textarea($s['whatsapp_template']).'</textarea><div class="wct-chips"><span class="description">Insert:</span> '.$chips.'</div><p class="description">Placeholders are filled per customer when you click WhatsApp. <code>{cart_restore_url}</code> is a secure link that rebuilds that customer&#8217;s cart on any device.</p><div class="wct-preview"><span class="wct-preview__label">Preview with sample data</span><div class="wct-wa-bubble" id="wct-preview" aria-live="polite"></div></div></td></tr>';
            echo '<tr><th scope="row">Cart restore destination</th><td><fieldset><legend class="screen-reader-text">Cart restore destination</legend><label><input type="radio" name="restore_destination" value="checkout" '.checked($dest,'checkout',false).'> Checkout</label><br><label><input type="radio" name="restore_destination" value="cart" '.checked($dest,'cart',false).'> Cart</label><p class="description">Where customers land after opening a cart restore link. Applies immediately, including to links already sent.</p></fieldset></td></tr>';
            echo '<tr><th scope="row"><label for="wct-days">Restore link valid for</label></th><td><input type="number" id="wct-days" name="restore_link_days" min="1" max="30" value="'.esc_attr($s['restore_link_days']).'" class="small-text"> days<p class="description">Applies to links created from now on. Each WhatsApp click creates a new link; earlier links keep working until they expire.</p></td></tr>';
        } else {
            echo '<tr><th>Capture checkout sessions</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($s['enabled']),true,false).'> Enable tracking</label><p class="description">Tracks checkout page activity for users who interact with the checkout.</p></td></tr><tr><th>Abandoned checkout timeout</th><td><input type="number" min="5" max="10080" name="abandon_timeout_minutes" value="'.esc_attr($s['abandon_timeout_minutes']).'"> minutes <p class="description">An Initiated session is marked Abandoned when there has been no checkout activity for this period. The scheduled task runs every 15 minutes.</p></td></tr><tr><th>Abandoned email alerts</th><td><label><input type="checkbox" name="email_alerts_enabled" value="1" '.checked(!empty($s['email_alerts_enabled']),true,false).'> Send an email when a session becomes Abandoned (once per session, only when it has an email or phone and cart items, and not if that email has placed an order since)</label><p><input type="text" class="regular-text" name="email_alert_recipients" value="'.esc_attr($s['email_alert_recipients']).'" placeholder="admin@example.com, sales@example.com"></p><p class="description">Enter one or more recipient email addresses separated by commas, semicolons, or spaces. Alerts include customer details, captured checkout fields, cart items, total, and a WP-Admin session link. Emails are sent by WordPress wp_mail().</p></td></tr><tr><th>Retention (days)</th><td><input type="number" min="1" max="3650" name="retention_days" value="'.esc_attr($s['retention_days']).'"> <p class="description">Old session records are removed by the scheduled maintenance task. IP is stored only as a one-way hash.</p></td></tr>';
        }
        echo '</table><p><button class="button button-primary">Save Settings</button></p></form>';
        if('general'===$tab) echo '<hr><h2>Data captured</h2><p>Checkout form values except payment/security-sensitive fields, cart products, quantities, prices, variations, session timestamps, and conversion order ID.</p><p><strong>Privacy:</strong> Add appropriate disclosure/consent to your site according to your local privacy requirements. Do not use this tracker to collect card numbers, CVV/CVC, passwords, or payment tokens.</p>';
        elseif('coupon'===$tab) echo '<hr><p><strong>How recovery coupons work:</strong> each is a one-time WooCommerce coupon that only works in the browser that opened its personal link. It is marked Used when an order with it is placed and stays Used even if that order is later cancelled or fails, so it can never be used twice. Revoke or let it expire to end it early. History stays available after the checkout session itself is cleaned up.</p>';
        else echo '<hr><p><strong>Consent:</strong> only message customers who expect to hear from you. Unsolicited WhatsApp messages can get your number reported or banned, and some countries require prior consent.</p>';
        echo '</div>';
    }
    // Each tab saves only its own fields and keeps the rest of the stored settings.
    public static function save_settings(){
        if(!current_user_can('manage_woocommerce')||!check_admin_referer('wct_settings','wct_nonce')) wp_die('Unauthorized.');
        $s=WCT_Tracker::settings(); $tab=in_array($_POST['tab']??'',array('whatsapp','coupon'),true)?$_POST['tab']:'general';
        if('coupon'===$tab){
            $config=WCT_Coupons::clean_config(array('type'=>$_POST['coupon_type']??'percent','amount'=>$_POST['coupon_amount']??'','min_cart'=>$_POST['coupon_min_cart']??'','max_discount'=>$_POST['coupon_max_discount']??'','validity_hours'=>$_POST['coupon_validity_hours']??''));
            if(is_wp_error($config)){ wp_safe_redirect(add_query_arg(array('page'=>'wct-settings','tab'=>'coupon','wct_error'=>rawurlencode($config->get_error_message())),admin_url('admin.php'))); exit; }
            $template=isset($_POST['coupon_template'])?mb_substr(sanitize_textarea_field(wp_unslash($_POST['coupon_template'])),0,2000):'';
            $s['coupon_enabled']=empty($_POST['coupon_enabled'])?0:1;
            $s['coupon_type']=$config['type']; $s['coupon_amount']=$config['amount'];
            $s['coupon_min_cart']=null===$config['min_cart']?'':$config['min_cart']; $s['coupon_max_discount']=null===$config['max_discount']?'':$config['max_discount'];
            $s['coupon_validity_hours']=$config['validity_hours'];
            $s['coupon_template']=''!==trim($template)?$template:WCT_Coupons::DEFAULT_TEMPLATE;
            $s['coupon_offer_modal']=empty($_POST['coupon_offer_modal'])?0:1;
        } elseif('whatsapp'===$tab){
            $template=isset($_POST['whatsapp_template'])?mb_substr(sanitize_textarea_field(wp_unslash($_POST['whatsapp_template'])),0,2000):'';
            $cc=substr(preg_replace('/\D/','',(string)wp_unslash($_POST['whatsapp_country_code']??'')),0,4);
            $s['whatsapp_enabled']=empty($_POST['whatsapp_enabled'])?0:1;
            $s['whatsapp_template']=''!==trim($template)?$template:WCT_Recovery::DEFAULT_TEMPLATE;
            $s['whatsapp_country_code']=''!==$cc?$cc:'880';
            $s['restore_destination']=(isset($_POST['restore_destination'])&&'cart'===$_POST['restore_destination'])?'cart':'checkout';
            $s['restore_link_days']=min(30,max(1,absint($_POST['restore_link_days']??7)));
        } else {
            $s['enabled']=empty($_POST['enabled'])?0:1;
            $s['abandon_timeout_minutes']=min(10080,max(5,absint($_POST['abandon_timeout_minutes']??60)));
            $s['retention_days']=min(3650,max(1,absint($_POST['retention_days']??90)));
            $s['email_alerts_enabled']=empty($_POST['email_alerts_enabled'])?0:1;
            $s['email_alert_recipients']=isset($_POST['email_alert_recipients'])?sanitize_text_field(wp_unslash($_POST['email_alert_recipients'])):get_option('admin_email');
        }
        update_option('wct_settings',$s);
        WCT_Tracker::reschedule();
        wp_safe_redirect(admin_url('admin.php?page=wct-settings'.('general'===$tab?'':'&tab='.$tab).'&updated=1')); exit;
    }
    public static function delete_session(){if(!current_user_can('manage_woocommerce')||!check_admin_referer('wct_delete','wct_nonce'))wp_die('Unauthorized.');$id=absint($_GET['id']??0);global $wpdb;$wpdb->delete(WCT_DB::fields_table(),array('session_id'=>$id));$wpdb->delete(WCT_DB::items_table(),array('session_id'=>$id));$wpdb->delete(WCT_DB::tokens_table(),array('session_id'=>$id));$wpdb->delete(WCT_DB::sessions_table(),array('id'=>$id));wp_safe_redirect(admin_url('admin.php?page=wct-checkouts'));exit;}
}
