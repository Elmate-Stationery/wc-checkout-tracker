(function(){
  'use strict';
  // Template fields (normal WhatsApp and coupon WhatsApp): placeholder buttons insert at the cursor of their own
  // textarea, and each has a live preview with sample data.
  if(!window.WCTSettings) return;
  var sample=WCTSettings.sample||{};
  var areas=[].slice.call(document.querySelectorAll('textarea.wct-template'));
  if(!areas.length) return;
  function render(area){
    var preview=document.getElementById(area.getAttribute('data-preview'));
    if(!preview) return;
    var text=area.value, set=sample;
    // The coupon message lists one product per line, so its preview does too.
    if(area.getAttribute('data-sample')==='coupon' && WCTSettings.couponItems){ set=Object.assign({},sample,{'{cart_items}':WCTSettings.couponItems}); }
    Object.keys(set).forEach(function(ph){ text=text.split(ph).join(set[ph]); });
    preview.textContent=text; // textContent + CSS pre-wrap: no HTML is ever interpreted
  }
  document.addEventListener('click',function(e){
    var chip=e.target.closest ? e.target.closest('.wct-chip') : null;
    if(!chip) return;
    e.preventDefault();
    var area=document.getElementById(chip.getAttribute('data-target')) || areas[0];
    var ph=chip.getAttribute('data-insert'), start=area.selectionStart, end=area.selectionEnd;
    area.focus();
    if(typeof area.setRangeText==='function'){ area.setRangeText(ph,start,end,'end'); }
    else { area.value=area.value.slice(0,start)+ph+area.value.slice(end); }
    render(area);
  });
  areas.forEach(function(area){ area.addEventListener('input',function(){ render(area); }); render(area); });
})();
