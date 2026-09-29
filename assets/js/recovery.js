(function(){
  'use strict';
  // Storefront (cart / checkout) after a recovery coupon link: the offer popup, and coupon messages for the
  // Cart/Checkout blocks (their Store API responses carry them in extensions.wct.notices).
  var cfg=window.WCTRecovery;
  if(!cfg) return;

  function node(tag,cls,text){ var n=document.createElement(tag); if(cls) n.className=cls; if(text!=null) n.textContent=text; return n; }

  function showOffer(o){
    var last=document.activeElement;
    var overlay=node('div','wct-offer');
    var box=node('div','wct-offer__box'); box.setAttribute('role','dialog'); box.setAttribute('aria-modal','true'); box.setAttribute('aria-labelledby','wct-offer-title'); box.tabIndex=-1;
    box.appendChild(node('h2','wct-offer__title',o.locked ? 'Your cart is waiting' : 'Your special offer'));
    box.lastChild.id='wct-offer-title';
    var rows=node('dl','wct-offer__rows');
    function row(label,value,cls){ var r=node('div','wct-offer__row'+(cls?' '+cls:'')); r.appendChild(node('dt',null,label)); r.appendChild(node('dd',null,value)); rows.appendChild(r); }
    row('Cart',o.cart);
    if(o.locked){
      box.appendChild(rows);
      box.appendChild(node('p','wct-offer__note',o.needMore ? 'Add '+o.needMore+' more to get '+o.discountLabel+' off with coupon '+o.code+'. It will be applied automatically.' : 'Coupon '+o.code+' will be applied when your cart qualifies.'));
    } else {
      row('Discount',o.discountPct ? o.discountPct+' / '+o.discount : o.discount,'is-discount');
      row('New total',o.total,'is-total');
      box.appendChild(rows);
      box.appendChild(node('p','wct-offer__note','Coupon '+o.code+' is applied · valid until '+o.expires+'. Shipping, if any, is added at checkout.'));
    }
    // A single OK button that only closes the popup: no navigation, and the restored cart and coupon stay as they are.
    var actions=node('div','wct-offer__actions');
    var ok=node('button','wct-offer__cta','OK'); ok.type='button';
    actions.appendChild(ok); box.appendChild(actions);
    overlay.appendChild(box); document.body.appendChild(overlay);
    document.documentElement.classList.add('wct-offer-open');
    function close(){
      overlay.remove(); document.documentElement.classList.remove('wct-offer-open');
      document.removeEventListener('keydown',keys,true);
      if(last && last.focus) last.focus();
    }
    function keys(e){
      if(e.key==='Escape'){ e.preventDefault(); close(); return; } // same as OK, for keyboard users
      if(e.key==='Tab'){ e.preventDefault(); ok.focus(); }        // OK is the only control
    }
    ok.addEventListener('click',close);
    document.addEventListener('keydown',keys,true);
    ok.focus();
  }

  var toastWrap=null;
  function toast(message){
    if(!toastWrap){ toastWrap=node('div','wct-toasts'); toastWrap.setAttribute('role','status'); toastWrap.setAttribute('aria-live','polite'); document.body.appendChild(toastWrap); }
    var t=node('div','wct-toast'); t.appendChild(node('span',null,message));
    var b=node('button','wct-toast__close'); b.type='button'; b.setAttribute('aria-label','Dismiss'); b.innerHTML='&times;';
    b.addEventListener('click',function(){ t.remove(); });
    t.appendChild(b); toastWrap.appendChild(t);
    setTimeout(function(){ t.remove(); },12000);
  }
  function watchBlockNotices(){
    var data=window.wp && window.wp.data;
    if(!data || typeof data.subscribe!=='function') return;
    var lastCart=null;
    data.subscribe(function(){
      var cart;
      try { cart=data.select('wc/store/cart').getCartData(); } catch(e){ return; }
      if(!cart || cart===lastCart) return;
      lastCart=cart;
      var list=cart.extensions && cart.extensions.wct && cart.extensions.wct.notices;
      if(list && list.length) list.forEach(toast);
    });
  }

  function start(){ if(cfg.offer) showOffer(cfg.offer); watchBlockNotices(); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start); else start();
})();
