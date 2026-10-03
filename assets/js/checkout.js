(function($){
  'use strict';
  if(!window.WCT) return;
  var S=WCT.sensitive||{};
  var SKIP_TYPES=['password','hidden','submit','button','file','image','reset'];
  var SECRET_AUTOCOMPLETE=['current-password','new-password','one-time-code'];
  // Gateway card/bank inputs live in these areas; only plain choices (radios/checkboxes) are read from them.
  var PAYMENT_AREAS='.payment_box, .wc-block-checkout__payment-method, .wc-block-components-radio-control-accordion-content';
  var PHONE_KEY=/phone|mobile|(?:^|[^a-z])tel(?:[^a-z]|$)/;
  var HEARTBEAT=Math.max(15000, parseInt(WCT.heartbeat,10)||60000);
  var ready=false, inFlight=false, again=false, againForce=false, timer=null, timerForce=false;
  var lastSent={}, lastContact=0, lastInteraction=0;

  function list(name){ return S[name]||[]; }
  // Mirrors WCT_Tracker::is_sensitive_key() so credentials never leave the browser; the server checks again.
  function isSensitiveName(name){
    var key=String(name).toLowerCase(), i;
    if(list('safe').indexOf(key)!==-1) return false;
    if(list('exact').indexOf(key)!==-1) return true;
    var compact=key.replace(/[^a-z0-9]/g,''), parts=key.split(/[^a-z0-9]+/), subs=list('substrings'), toks=list('tokens');
    for(i=0;i<subs.length;i++) if(compact.indexOf(subs[i])!==-1) return true;
    for(i=0;i<toks.length;i++){
      // "pin_code" is a postal code in India, not a PIN.
      if(toks[i]==='pin' && compact.indexOf('pincode')!==-1) continue;
      if(parts.indexOf(toks[i])!==-1) return true;
    }
    return false;
  }
  function isSensitive(el, name, type){
    var ac=String(el.getAttribute('autocomplete')||'').toLowerCase().split(/\s+/), i;
    for(i=0;i<ac.length;i++) if(ac[i].indexOf('cc-')===0 || SECRET_AUTOCOMPLETE.indexOf(ac[i])!==-1) return true;
    if(type!=='checkbox' && type!=='radio' && $(el).closest(PAYMENT_AREAS).length) return true;
    return isSensitiveName(name);
  }
  function luhn(d){
    var sum=0, dbl=false;
    for(var i=d.length-1;i>=0;i--){ var n=+d.charAt(i); if(dbl && (n*=2)>9) n-=9; sum+=n; dbl=!dbl; }
    return sum%10===0;
  }
  // Mirrors WCT_Tracker::redact_card_numbers(): card-like numbers are replaced wherever they are typed.
  function redact(name, text){
    text=String(text);
    if(PHONE_KEY.test(String(name).toLowerCase()) || (text.match(/\d/g)||[]).length<13) return text;
    return text.replace(/\d+(?:[ -]\d+)*/g, function(run){
      var groups=run.split(/[ -]/);
      for(var i=0;i<groups.length;i++){
        var digits='';
        for(var j=i;j<groups.length;j++){
          digits+=groups[j];
          if(digits.length>19) break;
          if(digits.length>=13 && luhn(digits)) return '[redacted]';
        }
      }
      return run;
    });
  }
  function collect(){
    var out={};
    // Every named field on the checkout page, not only the checkout form.
    $('input, select, textarea').each(function(){
      var el=this, name=el.name || el.id;
      if(!name || el.disabled) return;
      var type=(el.type||'').toLowerCase();
      if(SKIP_TYPES.indexOf(type)!==-1) return;
      if((type==='checkbox'||type==='radio') && !el.checked) return;
      if(isSensitive(el, name, type)) return;
      var val=$(el).val();
      if(val===undefined || val===null || !String(val).length) return;
      out[name]=Array.isArray(val) ? val.map(function(v){ return redact(name, v); }) : redact(name, val);
    });
    return out;
  }
  // Only fields whose value changed since the last successful send.
  function changes(current){
    var out=null;
    Object.keys(current).forEach(function(k){ if(lastSent[k]!==JSON.stringify(current[k])) (out=out||{})[k]=current[k]; });
    return out;
  }
  function remember(fields){ if(fields) Object.keys(fields).forEach(function(k){ lastSent[k]=JSON.stringify(fields[k]); }); }
  // One request at a time, so each request carries the cookie from the one before it. Anything requested
  // meanwhile is sent right after, never dropped. force=true also sends with no field changes (cart / activity).
  function flush(force){
    if(!ready || inFlight){ again=true; againForce=againForce || !!force; return; }
    force=!!force || againForce; again=againForce=false;
    var fields=changes(collect());
    if(!fields && !force) return;
    inFlight=true;
    $.post(WCT.ajaxUrl,{action:'wct_capture',nonce:WCT.nonce,fields:fields||{}}).done(function(res){
      if(res && res.success){ remember(fields); lastContact=Date.now(); }
    }).always(function(){ inFlight=false; if(again) flush(); });
  }
  function schedule(delay, force){
    timerForce=timerForce || !!force;
    clearTimeout(timer);
    timer=setTimeout(function(){ var f=timerForce; timerForce=false; flush(f); }, delay);
  }
  // Last-moment save when the tab is hidden or closed, so values typed just before leaving are kept.
  function beacon(){
    if(!ready || !navigator.sendBeacon || typeof URLSearchParams!=='function') return;
    var fields=changes(collect());
    if(!fields) return;
    var body=new URLSearchParams();
    body.append('action','wct_capture'); body.append('nonce',WCT.nonce);
    Object.keys(fields).forEach(function(k){
      var v=fields[k];
      if(Array.isArray(v)) v.forEach(function(x){ body.append('fields['+k+'][]', x); }); else body.append('fields['+k+']', v);
    });
    if(navigator.sendBeacon(WCT.ajaxUrl, body)) remember(fields);
  }
  // The Checkout block changes the cart through the Store API without jQuery events; watch its data store.
  function watchBlockCart(){
    var data=window.wp && window.wp.data;
    if(!data || typeof data.subscribe!=='function' || typeof data.select!=='function') return;
    var lastCart=null, lastSig=null;
    data.subscribe(function(){
      var cart;
      try { cart=data.select('wc/store/cart').getCartData(); } catch(e){ return; }
      if(!cart || !cart.items || cart===lastCart) return;
      lastCart=cart;
      var sig=JSON.stringify([cart.items.map(function(i){ return [i.key,i.quantity]; }), cart.totals && cart.totals.total_price]);
      if(lastSig!==null && sig!==lastSig) schedule(300,true);
      lastSig=sig;
    });
  }
  function touch(){ lastInteraction=Date.now(); }
  $(function(){
    // Nothing is sent until wct_start has answered, so the session cookie is settled first.
    $.post(WCT.ajaxUrl,{action:'wct_start',nonce:WCT.nonce}).always(function(){ ready=true; lastContact=Date.now(); flush(); });
    // Capture values already present in visible checkout fields, including browser autofill,
    // without requiring the visitor to log in or type anything. Browsers may populate
    // autofill fields just after DOM ready, so scan more than once after page load.
    setTimeout(function(){ flush(); }, 150);
    setTimeout(function(){ flush(); }, 700);
    setTimeout(function(){ flush(); }, 1500);
    $(document.body).on('change blur input','input, select, textarea',function(){ touch(); schedule(500); });
    $(document.body).on('updated_checkout updated_wc_div added_to_cart removed_from_cart wc_fragments_refreshed',function(){ schedule(300,true); });
    watchBlockCart();
    ['pointerdown','keydown','touchstart','mousemove','wheel','scroll'].forEach(function(ev){ window.addEventListener(ev, touch, {passive:true}); });
    // Keeps a page the visitor is actively using from being marked abandoned; an idle or hidden tab sends nothing.
    setInterval(function(){ if(!document.hidden && lastInteraction>lastContact) flush(true); }, HEARTBEAT);
    document.addEventListener('visibilitychange', function(){ if(document.hidden) beacon(); });
    window.addEventListener('pagehide', beacon);
  });
})(jQuery);
