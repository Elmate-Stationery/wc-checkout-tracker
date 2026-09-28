(function($){
  'use strict';
  if(!window.WCT) return;
  var timer=null, busy=false;
  function collect(){
    var out={};
    $('.woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea').each(function(){
      var el=this, name=el.name || el.id;
      if(!name || el.disabled) return;
      var type=(el.type||'').toLowerCase();
      if(['password','hidden','submit','button','file'].indexOf(type)!==-1) return;
      if((type==='checkbox'||type==='radio') && !el.checked) return;
      var val=$(el).val();
      if(val!==undefined && val!==null && String(val).length) out[name]=val;
    });
    return out;
  }
  function send(fields){
    if(busy) return; busy=true;
    $.post(WCT.ajaxUrl,{action:'wct_capture',nonce:WCT.nonce,fields:fields}).always(function(){busy=false;});
  }
  function start(){ $.post(WCT.ajaxUrl,{action:'wct_start',nonce:WCT.nonce}); }
  function capturePrefilled(){
    var fields=collect();
    if(Object.keys(fields).length) send(fields);
  }
  $(function(){
    start();
    // Capture values already present in visible checkout fields, including browser autofill,
    // without requiring the visitor to log in or type anything. Browsers may populate
    // autofill fields just after DOM ready, so scan more than once after page load.
    setTimeout(capturePrefilled, 150);
    setTimeout(capturePrefilled, 700);
    setTimeout(capturePrefilled, 1500);
    $(document.body).on('change blur input','form.checkout input, form.checkout select, form.checkout textarea',function(){
      clearTimeout(timer); timer=setTimeout(function(){send(collect());},500);
    });
    $(document.body).on('updated_checkout updated_wc_div added_to_cart removed_from_cart wc_fragments_refreshed',function(){
      clearTimeout(timer); timer=setTimeout(function(){send(collect());},300);
    });
    setInterval(function(){ if(!document.hidden) send(collect()); }, Math.max(3000, parseInt(WCT.interval||3000,10)));
  });
})(jQuery);
