<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_Admin {
    public static function init() {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_wct_save_settings',array(__CLASS__,'save_settings'));
        add_action('admin_post_wct_delete_session',array(__CLASS__,'delete_session'));
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
        add_action('wp_ajax_wct_session_detail',array(__CLASS__,'ajax_detail'));
    }
    public static function menu() {
        add_submenu_page('woocommerce','Checkout Sessions','Checkout Sessions','manage_woocommerce','wct-checkouts',array(__CLASS__,'page'));
        add_submenu_page('woocommerce','Checkout Tracker Settings','Checkout Tracker','manage_woocommerce','wct-settings',array(__CLASS__,'settings_page'));
    }
    public static function assets($hook) {
        if(strpos($hook,'wct-')===false) return;
        wp_enqueue_style('wct-admin',WCT_URL.'assets/css/admin.css',array('dashicons'),wct_asset_ver('assets/css/admin.css'));
        if(strpos($hook,'wct-checkouts')===false) return;
        wp_enqueue_script('wct-admin',WCT_URL.'assets/js/admin.js',array(),wct_asset_ver('assets/js/admin.js'),true);
        // openId: links like admin.php?page=wct-checkouts&view=12 (e.g. from alert emails) open that session's modal.
        wp_localize_script('wct-admin','WCTAdmin',array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wct_admin'),'openId'=>isset($_GET['view'])?absint($_GET['view']):0));
    }
    private static function status_label($s){ return ucwords(str_replace('_',' ',$s)); }
    // Works with both HPOS and legacy post-based order storage.
    private static function order_link($order_id){
        $order_id=absint($order_id); if(!$order_id) return '—';
        if(is_callable(array('Automattic\WooCommerce\Utilities\OrderUtil','get_order_admin_edit_url'))) $url=\Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($order_id);
        else { $order=wc_get_order($order_id); $url=$order?$order->get_edit_order_url():admin_url('post.php?post='.$order_id.'&action=edit'); }
        return '<a href="'.esc_url($url).'">#'.$order_id.'</a>';
    }
    public static function page() {
        if(!current_user_can('manage_woocommerce')) return;
        global $wpdb; $st=WCT_DB::sessions_table(); $per=25; $page=max(1,absint($_GET['paged']??1)); $offset=($page-1)*$per;
        $status=sanitize_key($_GET['status']??''); $search=sanitize_text_field($_GET['s']??'');
        $where='WHERE 1=1'; $args=array(); if($status){$where.=' AND status=%s';$args[]=$status;} if($search){$where.=' AND (email LIKE %s OR phone LIKE %s OR customer_name LIKE %s OR session_key LIKE %s)';$q='%'.$wpdb->esc_like($search).'%';$args=array_merge($args,array($q,$q,$q,$q));}
        $count_sql="SELECT COUNT(*) FROM $st $where"; $total=(int)$wpdb->get_var($args?$wpdb->prepare($count_sql,$args):$count_sql);
        $args2=$args; $args2[]=$offset; $args2[]=$per; $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $st $where ORDER BY last_activity_at DESC LIMIT %d,%d",$args2));
        echo '<div class="wrap wct-wrap"><h1>WooCommerce Checkout Sessions</h1><p>Tracks checkout starts, field activity, cart snapshots and converted orders.</p>';
        echo '<div class="wct-cards">'; foreach(array('initiated','abandoned','converted') as $x){$n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st WHERE status=%s",$x)); echo '<div class="wct-card"><strong>'.esc_html(self::status_label($x)).'</strong><span>'.esc_html($n).'</span></div>'; } echo '</div>';
        echo '<form method="get" class="wct-filters"><input type="hidden" name="page" value="wct-checkouts"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Search name, phone, email, session..."><select name="status"><option value="">All statuses</option>'; foreach(array('initiated','abandoned','converted') as $x) echo '<option value="'.esc_attr($x).'" '.selected($status,$x,false).'>'.esc_html(self::status_label($x)).'</option>'; echo '</select><button class="button">Filter</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Session</th><th>Customer</th><th>Contact</th><th>Items</th><th>Total</th><th>Status</th><th>Last Activity</th><th>Order</th><th class="wct-actions"><span class="screen-reader-text">Actions</span></th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="9">No checkout sessions found.</td></tr>';
        foreach($rows as $r){echo '<tr><td><a class="wct-open" data-id="'.absint($r->id).'" href="'.esc_url(admin_url('admin.php?page=wct-checkouts&view='.absint($r->id))).'"><strong>'.esc_html($r->session_key).'</strong></a><br><small>'.esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$r->started_at,true)).'</small></td><td>'.esc_html($r->customer_name?:'—').'</td><td>'.esc_html($r->email?:'—').'<br>'.esc_html($r->phone?:'').'</td><td>'.esc_html($r->item_count).'</td><td>'.wp_kses_post(wc_price((float)$r->cart_total,array('currency'=>$r->currency?:get_woocommerce_currency()))).'</td><td><span class="wct-status wct-'.esc_attr($r->status).'">'.esc_html(self::status_label($r->status)).'</span></td><td>'.esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$r->last_activity_at,true)).'</td><td>'.self::order_link($r->order_id).'</td><td class="wct-actions"><button type="button" class="button button-small wct-open" data-id="'.absint($r->id).'" aria-haspopup="dialog">View</button></td></tr>';}
        echo '</tbody></table>';
        $pages=max(1,ceil($total/$per)); if($pages>1) echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$page,'total'=>$pages,'type'=>'plain'))).'</div></div>';
        echo '</div>';
        // Filled by assets/js/admin.js. Deliberately no Escape / backdrop close: only the close button.
        echo '<div id="wct-modal" class="wct-modal" hidden><div class="wct-modal__backdrop"></div><div class="wct-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wct-modal-title" tabindex="-1"><div class="wct-modal__header"><h2 id="wct-modal-title">Checkout session</h2><button type="button" class="wct-modal__close" aria-label="Close"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div><div class="wct-modal__body"></div></div></div>';
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
        $alert=$r->abandoned_notified_at?self::date_html($r->abandoned_notified_at):'<span class="wct-muted">Not sent</span>';

        $tabs=array(
            'overview'=>array('Overview','<div class="wct-boxes">'
                .'<div class="wct-box"><h3>Customer</h3>'.self::dl(array('Name'=>$name,'Email'=>$email,'Phone'=>$phone,'Account'=>$user?:'<span class="wct-muted">Guest</span>')).'</div>'
                .'<div class="wct-box"><h3>Session</h3>'.self::dl(array('Status'=>$status,'Started'=>self::date_html($r->started_at),'Last activity'=>self::date_html($r->last_activity_at),'Abandoned at'=>$r->abandoned_at?self::date_html($r->abandoned_at):'','Converted at'=>$r->converted_at?self::date_html($r->converted_at):'','Order'=>$r->order_id?self::order_link($r->order_id):'','Alert email'=>$alert)).'</div>'
                .'<div class="wct-box"><h3>Cart</h3>'.self::dl(array('Products'=>esc_html(count($items)),'Items qty'=>esc_html(wc_stock_amount($r->item_count)),'Total'=>$price($r->cart_total),'Currency'=>esc_html($currency))).'</div>'
                .'</div>'),
            'fields'=>array('Checkout fields <span class="wct-count">'.count($fields).'</span>',$fields_html),
            'cart'=>array('Cart <span class="wct-count">'.count($items).'</span>',$cart_html),
            'technical'=>array('Technical','<div class="wct-box">'.self::dl(array('Session ID'=>'#'.absint($r->id),'Session key'=>'<code>'.esc_html($r->session_key).'</code>','IP address (hashed)'=>$r->ip_hash?'<code>'.esc_html($r->ip_hash).'</code>':'','User agent'=>esc_html((string)$r->user_agent),'Cart hash'=>$r->cart_hash?'<code>'.esc_html($r->cart_hash).'</code>':'','Created'=>self::date_html($r->created_at),'Updated'=>self::date_html($r->updated_at))).'</div>'),
        );

        $out='<div class="wct-summary">'.$status
            .'<span class="wct-summary__item"><strong>'.$price($r->cart_total).'</strong> · '.esc_html(wc_stock_amount($r->item_count)).' '.(1==$r->item_count?'item':'items').'</span>'
            .'<span class="wct-summary__item">'.($r->customer_name?$name:($r->email?esc_html($r->email):'Unknown customer')).'</span>'
            .'<span class="wct-summary__item">Last active '.esc_html(human_time_diff(strtotime($r->last_activity_at.' UTC'))).' ago</span>'
            .($r->order_id?'<span class="wct-summary__item">Order '.self::order_link($r->order_id).'</span>':'').'</div>';
        $out.='<div class="wct-tabs" role="tablist" aria-label="Session details">'; $first=true;
        foreach($tabs as $key=>$t){ $out.='<button type="button" role="tab" class="wct-tab" id="wct-tab-'.$key.'" aria-controls="wct-panel-'.$key.'" aria-selected="'.($first?'true':'false').'" tabindex="'.($first?'0':'-1').'">'.$t[0].'</button>'; $first=false; }
        $out.='</div>'; $first=true;
        foreach($tabs as $key=>$t){ $out.='<div class="wct-panel" role="tabpanel" id="wct-panel-'.$key.'" aria-labelledby="wct-tab-'.$key.'" tabindex="0"'.($first?'':' hidden').'>'.$t[1].'</div>'; $first=false; }
        return $out;
    }
    public static function settings_page(){ if(!current_user_can('manage_woocommerce'))return; $s=wp_parse_args(get_option('wct_settings',array()),array('enabled'=>1,'retention_days'=>90,'abandon_timeout_minutes'=>60,'email_alerts_enabled'=>0,'email_alert_recipients'=>get_option('admin_email'))); echo '<div class="wrap wct-wrap"><h1>Checkout Tracker Settings</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wct_save_settings">'.wp_nonce_field('wct_settings','wct_nonce',true,false).'<table class="form-table"><tr><th>Capture checkout sessions</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($s['enabled']),true,false).'> Enable tracking</label><p class="description">Tracks checkout page activity for users who interact with the checkout.</p></td></tr><tr><th>Abandoned checkout timeout</th><td><input type="number" min="5" max="10080" name="abandon_timeout_minutes" value="'.esc_attr($s['abandon_timeout_minutes']).'"> minutes <p class="description">An Initiated session is marked Abandoned when there has been no checkout activity for this period. The scheduled task runs every 15 minutes.</p></td></tr><tr><th>Abandoned email alerts</th><td><label><input type="checkbox" name="email_alerts_enabled" value="1" '.checked(!empty($s['email_alerts_enabled']),true,false).'> Send an email when a session becomes Abandoned (once per session, only when it has an email or phone and cart items, and not if that email has placed an order since)</label><p><input type="text" class="regular-text" name="email_alert_recipients" value="'.esc_attr($s['email_alert_recipients']).'" placeholder="admin@example.com, sales@example.com"></p><p class="description">Enter one or more recipient email addresses separated by commas, semicolons, or spaces. Alerts include customer details, captured checkout fields, cart items, total, and a WP-Admin session link. Emails are sent by WordPress wp_mail().</p></td></tr><tr><th>Retention (days)</th><td><input type="number" min="1" max="3650" name="retention_days" value="'.esc_attr($s['retention_days']).'"> <p class="description">Old session records are removed by the scheduled maintenance task. IP is stored only as a one-way hash.</p></td></tr></table><p><button class="button button-primary">Save Settings</button></p></form><hr><h2>Data captured</h2><p>Checkout form values except payment/security-sensitive fields, cart products, quantities, prices, variations, session timestamps, and conversion order ID.</p><p><strong>Privacy:</strong> Add appropriate disclosure/consent to your site according to your local privacy requirements. Do not use this tracker to collect card numbers, CVV/CVC, passwords, or payment tokens.</p></div>'; }
    public static function save_settings(){if(!current_user_can('manage_woocommerce')||!check_admin_referer('wct_settings','wct_nonce'))wp_die('Unauthorized.');$recipients = isset($_POST['email_alert_recipients']) ? sanitize_text_field(wp_unslash($_POST['email_alert_recipients'])) : get_option('admin_email'); update_option('wct_settings',array('enabled'=>empty($_POST['enabled'])?0:1,'abandon_timeout_minutes'=>min(10080,max(5,absint($_POST['abandon_timeout_minutes']??60))),'retention_days'=>min(3650,max(1,absint($_POST['retention_days']??90))),'email_alerts_enabled'=>empty($_POST['email_alerts_enabled'])?0:1,'email_alert_recipients'=>$recipients));WCT_Tracker::reschedule();wp_safe_redirect(admin_url('admin.php?page=wct-settings&updated=1'));exit;}
    public static function delete_session(){if(!current_user_can('manage_woocommerce')||!check_admin_referer('wct_delete','wct_nonce'))wp_die('Unauthorized.');$id=absint($_GET['id']??0);global $wpdb;$wpdb->delete(WCT_DB::fields_table(),array('session_id'=>$id));$wpdb->delete(WCT_DB::items_table(),array('session_id'=>$id));$wpdb->delete(WCT_DB::sessions_table(),array('id'=>$id));wp_safe_redirect(admin_url('admin.php?page=wct-checkouts'));exit;}
}
