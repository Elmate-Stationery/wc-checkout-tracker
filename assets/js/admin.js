(function(){
  'use strict';
  var cfg=window.WCTAdmin, modal=document.getElementById('wct-modal');
  if(!cfg || !modal) return;
  var dialog=modal.querySelector('.wct-modal__dialog'), body=modal.querySelector('.wct-modal__body');
  var title=modal.querySelector('#wct-modal-title'), closeBtn=modal.querySelector('.wct-modal__close');
  var FOCUSABLE='a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var lastFocus=null, request=0, controller=null, lockY=0;

  // WP admin sets html/body to height:100%, so overflow:hidden would collapse the page and jump to the top.
  // Pin the page at its current offset instead, and restore that exact position on close.
  function lockPage(){
    var s=document.body.style, bar=window.innerWidth-document.documentElement.clientWidth;
    lockY=window.pageYOffset;
    s.position='fixed'; s.top=(-lockY)+'px'; s.left='0'; s.right='0';
    if(bar>0) s.paddingRight=bar+'px'; // keep the list from shifting when the scrollbar disappears
    document.documentElement.classList.add('wct-modal-open');
  }
  function unlockPage(){
    var s=document.body.style;
    s.position=s.top=s.left=s.right=s.paddingRight='';
    document.documentElement.classList.remove('wct-modal-open');
    window.scrollTo(0,lockY);
  }

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
  function load(id){
    var mine=++request;
    if(controller) controller.abort();
    controller=typeof AbortController==='function' ? new AbortController() : null;
    title.textContent='Checkout session #'+id;
    state('<span class="spinner is-active"></span><span>Loading session…</span>');
    var data=new FormData();
    data.append('action','wct_session_detail'); data.append('nonce',cfg.nonce); data.append('id',id);
    fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin',signal:controller ? controller.signal : undefined})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(res){
        if(mine!==request) return; // a newer request (or close) superseded this one
        if(!res || !res.success){ showError((res && res.data && res.data.message) || 'Could not load this session. Reload the page and try again.', id); return; }
        title.textContent=res.data.title;
        body.innerHTML=res.data.html;
        body.scrollTop=0;
        initTabs(); initFilter();
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
    setUrl(id); load(id);
    closeBtn.focus();
  }
  function close(){
    request++; if(controller) controller.abort();
    modal.hidden=true; body.innerHTML='';
    unlockPage();
    setUrl(0);
    if(lastFocus && document.body.contains(lastFocus)) lastFocus.focus({preventScroll:true});
  }

  function initTabs(){
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
  // Escape and clicks on the backdrop intentionally do nothing; only the close button closes the modal.
  // Keep keyboard focus inside the dialog while it is open.
  modal.addEventListener('keydown',function(e){
    if(e.key!=='Tab') return;
    var items=[].filter.call(dialog.querySelectorAll(FOCUSABLE),function(el){ return el.offsetParent!==null || el===document.activeElement; });
    if(!items.length){ e.preventDefault(); dialog.focus(); return; }
    var first=items[0], last=items[items.length-1], active=document.activeElement;
    if(e.shiftKey && (active===first || active===dialog)){ e.preventDefault(); last.focus(); }
    else if(!e.shiftKey && active===last){ e.preventDefault(); first.focus(); }
  });
  document.addEventListener('focusin',function(e){
    if(!modal.hidden && !dialog.contains(e.target)) closeBtn.focus();
  });

  if(parseInt(cfg.openId,10)) open(cfg.openId);
})();
