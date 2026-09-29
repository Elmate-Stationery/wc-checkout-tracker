(function(){
  'use strict';
  var cfg=window.WCTAdmin, modal=document.getElementById('wct-modal');
  if(!cfg || !modal) return;
  var dialog=modal.querySelector('.wct-modal__dialog'), body=modal.querySelector('.wct-modal__body');
  var title=modal.querySelector('#wct-modal-title'), closeBtn=modal.querySelector('.wct-modal__close');
  var cdlg=document.getElementById('wct-coupon-dialog'); // Add Coupon popup (only when recovery coupons are enabled)
  var FOCUSABLE='a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var lastFocus=null, request=0, controller=null, lockY=0, locks=0, currentId=0;

  // WP admin sets html/body to height:100%, so overflow:hidden would collapse the page and jump to the top.
  // Pin the page at its current offset instead, and restore that exact position when the last popup closes.
  function lockPage(){
    if(locks++) return;
    var s=document.body.style, bar=window.innerWidth-document.documentElement.clientWidth;
    lockY=window.pageYOffset;
    s.position='fixed'; s.top=(-lockY)+'px'; s.left='0'; s.right='0';
    if(bar>0) s.paddingRight=bar+'px'; // keep the list from shifting when the scrollbar disappears
    document.documentElement.classList.add('wct-modal-open');
  }
  function unlockPage(){
    if(--locks>0) return;
    locks=0;
    var s=document.body.style;
    s.position=s.top=s.left=s.right=s.paddingRight='';
    document.documentElement.classList.remove('wct-modal-open');
    window.scrollTo(0,lockY);
  }
  function post(action,fields){
    var data=new FormData();
    data.append('action',action); data.append('nonce',cfg.nonce);
    Object.keys(fields||{}).forEach(function(k){ data.append(k,fields[k]); });
    return fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return null; }); });
  }
  function errorOf(res,fallback){ return (res && res.data && res.data.message) || fallback; }

  // Keep ?view=ID in the address bar while a session is open, so reload / copy link reopens it.
  function setUrl(id){
    if(!window.history || !history.replaceState || typeof URL!=='function') return;
    var u=new URL(window.location.href);
    if(id) u.searchParams.set('view',id); else u.searchParams.delete('view');
    history.replaceState(history.state,'',u.toString());
  }
  function state(html){ body.innerHTML='<div class="wct-modal__state">'+html+'</div>'; }
  function showError(message,id){
    state('<p class="wct-modal__error"></p><button type="button" class="button wct-retry">Try again</button>');
    body.querySelector('.wct-modal__error').textContent=message;
    body.querySelector('.wct-retry').addEventListener('click',function(){ load(id); });
  }
  // keepTab: reopen on the same tab (after an action inside the popup changed the data).
  function load(id,keepTab){
    var mine=++request;
    if(controller) controller.abort();
    controller=typeof AbortController==='function' ? new AbortController() : null;
    var scroll=keepTab ? body.scrollTop : 0;
    if(!keepTab){ title.textContent='Checkout session #'+id; state('<span class="spinner is-active"></span><span>Loading session…</span>'); }
    var data=new FormData();
    data.append('action','wct_session_detail'); data.append('nonce',cfg.nonce); data.append('id',id);
    fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin',signal:controller ? controller.signal : undefined})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(res){
        if(mine!==request) return; // a newer request (or close) superseded this one
        if(!res || !res.success){ showError(errorOf(res,'Could not load this session. Reload the page and try again.'), id); return; }
        title.textContent=res.data.title;
        body.innerHTML=res.data.html;
        initTabs(keepTab); initFilter();
        body.scrollTop=scroll;
      })
      .catch(function(e){
        if(mine!==request || (e && e.name==='AbortError')) return;
        showError('Could not load this session. Check your connection and try again.', id);
      });
  }
  function open(id){
    id=parseInt(id,10); if(!id) return;
    if(modal.hidden){
      lastFocus=document.activeElement;
      lockPage();
      modal.hidden=false;
    }
    currentId=id;
    setUrl(id); load(id);
    closeBtn.focus();
  }
  function close(){
    request++; if(controller) controller.abort();
    modal.hidden=true; body.innerHTML=''; currentId=0;
    unlockPage();
    setUrl(0);
    if(lastFocus && document.body.contains(lastFocus)) lastFocus.focus({preventScroll:true});
  }
  function refreshOpenSession(id){
    if(!modal.hidden && currentId===parseInt(id,10)){
      var tab=body.querySelector('[role="tab"][aria-selected="true"]');
      load(currentId,tab ? tab.id : null);
    }
  }
  // Server-rendered cells for one session (WhatsApp, Coupon, Status), replaced everywhere they appear.
  function applyCells(id,data){
    if(!data) return;
    [['waCell','[data-wa-cell="'+id+'"]',true],['couponCell','[data-coupon-cell="'+id+'"]',true],['statusCell','[data-status-cell="'+id+'"]',false]].forEach(function(c){
      if(!data[c[0]]) return;
      [].forEach.call(document.querySelectorAll(c[1]),function(el){
        if(modal.contains(el)) return; // the popup is reloaded as a whole instead
        if(c[2]) el.outerHTML=data[c[0]]; else el.innerHTML=data[c[0]];
      });
    });
    refreshOpenSession(id);
  }

  function initTabs(keepTab){
    var tabs=[].slice.call(body.querySelectorAll('[role="tab"]')), bar=body.querySelector('[role="tablist"]');
    function select(tab,focus){
      tabs.forEach(function(t){
        var on=t===tab;
        t.setAttribute('aria-selected',on ? 'true' : 'false'); t.tabIndex=on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden=!on;
      });
      // If the sticky tab bar is scrolled into view, start the new panel right under it.
      if(bar && body.scrollTop>bar.offsetTop) body.scrollTop=bar.offsetTop;
      if(focus) tab.focus();
      // On narrow screens the tab bar scrolls sideways; keep the chosen tab fully visible.
      if(bar) bar.scrollLeft=Math.max(0,Math.min(bar.scrollLeft,tab.offsetLeft-16),tab.offsetLeft+tab.offsetWidth+16-bar.clientWidth);
    }
    tabs.forEach(function(t,i){
      t.addEventListener('click',function(){ select(t,false); });
      t.addEventListener('keydown',function(e){
        var to={ArrowRight:i+1,ArrowLeft:i-1,Home:0,End:tabs.length-1}[e.key];
        if(to===undefined) return;
        e.preventDefault(); select(tabs[(to+tabs.length)%tabs.length],true);
      });
    });
    var keep=keepTab && document.getElementById(keepTab);
    if(keep) select(keep,false);
  }
  function initFilter(){
    var input=body.querySelector('.wct-field-filter'), empty=body.querySelector('.wct-filter-empty');
    if(!input) return;
    input.addEventListener('input',function(){
      var q=input.value.trim().toLowerCase(), any=false;
      [].forEach.call(body.querySelectorAll('.wct-field-group'),function(g){
        var shown=0;
        [].forEach.call(g.querySelectorAll('.wct-field-row'),function(r){
          var match=!q || (r.getAttribute('data-search')||'').indexOf(q)!==-1;
          r.hidden=!match; if(match) shown++;
        });
        g.hidden=!shown; if(shown) any=true;
        var count=g.querySelector('h3 .wct-count');
        if(count){ if(!count.hasAttribute('data-total')) count.setAttribute('data-total',count.textContent); count.textContent=q ? shown+' / '+count.getAttribute('data-total') : count.getAttribute('data-total'); }
      });
      if(empty) empty.hidden=any;
    });
  }

  document.addEventListener('click',function(e){
    var t=e.target.closest ? e.target.closest('.wct-open') : null;
    if(!t) return;
    // Modified clicks on the session link still open the full page in a new tab.
    if(e.button!==0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault(); open(t.getAttribute('data-id'));
  });
  closeBtn.addEventListener('click',close);

  // WhatsApp / WhatsApp + Coupon: open the new tab synchronously (inside the click, so popup blockers allow it),
  // then ask the server for the wa.me link. The server creates the restore link and records the send.
  function sendWhatsApp(btn){
    if(btn.disabled) return;
    var id=btn.getAttribute('data-id'), coupon=btn.getAttribute('data-kind')==='coupon', win=window.open('','_blank');
    if(win){ try{ win.document.title='Opening WhatsApp…'; win.document.body.innerHTML='<p style="font:16px/1.5 -apple-system,Segoe UI,sans-serif;padding:24px;color:#50575e">Opening WhatsApp…</p>'; }catch(e){} }
    btn.disabled=true;
    post(coupon ? 'wct_whatsapp_coupon_link' : 'wct_whatsapp_link',{id:id})
      .then(function(res){
        if(!res || !res.success){
          if(win) win.close();
          window.alert(errorOf(res,'Could not create the WhatsApp message. Reload the page and try again.'));
          return;
        }
        if(win){ win.opener=null; win.location.replace(res.data.url); } else { window.location.href=res.data.url; }
        applyCells(id,res.data);
      })
      .catch(function(){ if(win) win.close(); window.alert('Could not reach the server. Check your connection and try again.'); })
      .then(function(){ btn.disabled=false; });
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest ? e.target.closest('.wct-wa') : null;
    if(!b) return;
    e.preventDefault(); sendWhatsApp(b);
  });

  // Revoke the session's active coupon.
  document.addEventListener('click',function(e){
    var b=e.target.closest ? e.target.closest('.wct-coupon-revoke') : null;
    if(!b) return;
    e.preventDefault();
    if(!window.confirm('Revoke this coupon? The customer will no longer be able to use it. You can create a new one afterwards.')) return;
    b.disabled=true;
    post('wct_coupon_revoke',{id:b.getAttribute('data-id')}).then(function(res){
      if(!res || !res.success){ window.alert(errorOf(res,'Could not revoke the coupon.')); b.disabled=false; return; }
      applyCells(b.getAttribute('data-id'),res.data);
    }).catch(function(){ window.alert('Could not reach the server. Check your connection and try again.'); b.disabled=false; });
  });

  // Add Coupon popup: defaults from the Recovery Coupon settings; the values entered apply to this coupon only.
  var couponFor=0, couponFocus=null;
  function couponForm(){ return cdlg.querySelector('form'); }
  function syncCouponType(){
    var f=couponForm(), fixed=f.querySelector('input[name="type"]:checked').value==='fixed';
    cdlg.querySelector('[data-unit]').textContent=fixed ? '('+cfg.coupon.currency+')' : '(%)';
    f.amount.max=fixed ? '' : '100';
    cdlg.querySelector('[data-max-row]').hidden=fixed;
  }
  function openCoupon(id){
    if(!cdlg) return;
    var f=couponForm(), d=cfg.coupon||{};
    couponFor=parseInt(id,10); couponFocus=document.activeElement;
    f.reset();
    (f.querySelector('input[name="type"][value="'+(d.type==='fixed'?'fixed':'percent')+'"]')).checked=true;
    f.amount.value=d.amount||''; f.min_cart.value=d.min_cart||''; f.max_discount.value=d.max_discount||''; f.validity_hours.value=d.validity_hours||24;
    cdlg.querySelector('[data-coupon-session]').textContent=couponFor;
    cdlg.querySelector('.wct-form__error').hidden=true;
    syncCouponType();
    lockPage(); cdlg.hidden=false;
    f.amount.focus(); f.amount.select();
  }
  function closeCoupon(){
    if(!cdlg || cdlg.hidden) return;
    cdlg.hidden=true; unlockPage();
    if(couponFocus && document.body.contains(couponFocus)) couponFocus.focus({preventScroll:true});
  }
  if(cdlg){
    cdlg.addEventListener('change',function(e){ if(e.target.name==='type') syncCouponType(); });
    [].forEach.call(cdlg.querySelectorAll('[data-coupon-close]'),function(b){ b.addEventListener('click',closeCoupon); });
    couponForm().addEventListener('submit',function(e){
      e.preventDefault();
      var f=couponForm(), err=cdlg.querySelector('.wct-form__error'), submit=f.querySelector('[type="submit"]');
      submit.disabled=true; err.hidden=true;
      post('wct_coupon_create',{id:couponFor,type:f.querySelector('input[name="type"]:checked').value,amount:f.amount.value,min_cart:f.min_cart.value,max_discount:f.max_discount.value,validity_hours:f.validity_hours.value})
        .then(function(res){
          if(!res || !res.success){ err.textContent=errorOf(res,'Could not create the coupon.'); err.hidden=false; return; }
          var id=couponFor; closeCoupon(); applyCells(id,res.data);
        })
        .catch(function(){ err.textContent='Could not reach the server. Check your connection and try again.'; err.hidden=false; })
        .then(function(){ submit.disabled=false; });
    });
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest ? e.target.closest('.wct-coupon-add') : null;
    if(!b) return;
    e.preventDefault(); openCoupon(b.getAttribute('data-id'));
  });

  // Escape and clicks on the backdrop intentionally do nothing; only the close buttons close the popups.
  // Keep keyboard focus inside the top-most open popup.
  function topDialog(){ return cdlg && !cdlg.hidden ? cdlg.querySelector('.wct-modal__dialog') : (!modal.hidden ? dialog : null); }
  document.addEventListener('keydown',function(e){
    var d=topDialog();
    if(e.key!=='Tab' || !d) return;
    var items=[].filter.call(d.querySelectorAll(FOCUSABLE),function(el){ return el.offsetParent!==null || el===document.activeElement; });
    if(!items.length){ e.preventDefault(); d.focus(); return; }
    var first=items[0], last=items[items.length-1], active=document.activeElement;
    if(e.shiftKey && (active===first || active===d || !d.contains(active))){ e.preventDefault(); last.focus(); }
    else if(!e.shiftKey && (active===last || !d.contains(active))){ e.preventDefault(); first.focus(); }
  });
  document.addEventListener('focusin',function(e){
    var d=topDialog();
    if(d && !d.contains(e.target)){ var f=d.querySelector(FOCUSABLE); if(f) f.focus(); }
  });

  if(parseInt(cfg.openId,10)) open(cfg.openId);
})();
