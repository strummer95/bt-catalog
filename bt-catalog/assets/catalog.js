/* BT Catalog storefront — data-driven from the REST API.
   Builds the whole UI inside #btcat-root; styled by catalog.css (the approved mock styles). */
(function () {
  var CFG = window.btcatCfg || { rest: '/wp-json/boomerts/v1/' };
  var REST = CFG.rest.replace(/\/?$/, '/');
  var SIZES_ORDER = ['XS','S','M','L','XL','2XL','3XL','4XL','5XL'];
  var FAMILIES = [['Black','#1d1d1d'],['White','#ffffff'],['Grey','#9aa0a8'],['Blue','#2049b8'],
    ['Red','#b3132a'],['Green','#1f7a44'],['Yellow','#e8a417'],['Orange','#e8601c'],
    ['Pink','#e535ab'],['Purple','#5b2a86'],['Neutral','#d8c6a0']];

  var F = { s:'', brand:'', category:'', color:'', page:1 };
  var current = null, currentColor = null;
  var quote = [], dStep = 1, method = 'print', locs = 1, sent = false;
  var contact = { name:'', email:'', phone:'', notes:'' };

  var root = document.getElementById('btcat-root');
  if (!root) return;

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function money(n){ return '$' + (Math.round(n*100)/100).toFixed(2); }
  function api(path){ return fetch(REST + path, { credentials:'same-origin' }).then(function(r){ return r.json(); }); }

  /* ---------- shell ---------- */
  root.innerHTML =
    '<section class="hero"><div class="wrap">' +
      '<span class="eyebrow">Boomer T\'s · Blank Apparel</span>' +
      '<h1>Pick your blank. Get a <span>quote.</span></h1>' +
      '<p>Browse every garment we print &amp; stitch, build your sizes, and send it to our quote desk.</p>' +
    '</div></section>' +
    '<div class="wrap"><div class="catnav">' +
      '<nav class="cmenus">' +
        '<div class="cm"><span class="cmlabel">Brands <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mBrands"></div></div>' +
        '<div class="cm"><span class="cmlabel">Categories <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mCats"></div></div>' +
        '<div class="cm"><span class="cmlabel">Colors <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mColors"></div></div>' +
      '</nav>' +
      '<div class="csearch">\uD83D\uDD0D<input id="btSearch" placeholder="Search style # or product\u2026"></div>' +
    '</div></div>' +
    '<div class="wrap shell"><main style="grid-column:1/-1">' +
      '<div class="toolbar"><div class="count"><b id="btCount">0</b> styles</div><div id="btActive"></div></div>' +
      '<div class="grid" id="btGrid"></div>' +
      '<div class="pager" id="btPager"></div>' +
    '</main></div>' +
    '<div id="btPdp" class="pdp" style="display:none"></div>' +
    '<div id="btDrawer" class="drawer" style="display:none"></div>' +
    '<div id="btScrim" class="scrim" style="display:none;position:fixed;inset:0;z-index:75;background:rgba(0,0,0,.45)"></div>' +
    '<button id="btFab" class="btcat-fab">My Quote \u00b7 <span id="btBadge">0</span></button>';

  // FAB styling (in case the page CSS doesn't carry it)
  var fab = document.getElementById('btFab');
  fab.style.cssText = 'position:fixed;bottom:22px;right:22px;z-index:70;background:#e535ab;color:#fff;border:0;' +
    'font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:.5px;font-size:15px;padding:13px 20px;' +
    'border-radius:30px;box-shadow:0 6px 18px rgba(0,0,0,.28);cursor:pointer';
  fab.addEventListener('click', openDrawer);

  /* ---------- menus ---------- */
  function bindMenus(){
    var labels = root.querySelectorAll('.cmlabel');
    labels.forEach(function(l){ l.addEventListener('click', function(e){ e.stopPropagation(); toggleMenu(l.parentNode); }); });
    document.addEventListener('click', function(e){ if(!e.target.closest('.cm')) closeMenus(); });
  }
  function toggleMenu(cm){ var o = cm.classList.contains('open'); closeMenus(); if(!o) cm.classList.add('open'); }
  function closeMenus(){ root.querySelectorAll('.cm.open').forEach(function(e){ e.classList.remove('open'); }); }

  function loadFacets(){
    api('catalog/facets').then(function(f){
      var b = (f && f.brands) || [], c = (f && f.categories) || [];
      document.getElementById('mBrands').innerHTML =
        '<div class="mega"><div class="megacol megabrands">' +
        b.map(function(x){ return '<div class="megai" data-brand="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') +
        '</div></div>';
      document.getElementById('mCats').innerHTML =
        '<div class="mega"><div class="megacol">' +
        c.map(function(x){ return '<div class="megai" data-cat="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') +
        '</div></div>';
      document.getElementById('mColors').innerHTML =
        '<div class="mega"><div class="megacol">' +
        FAMILIES.map(function(fm){ return '<div class="megai colori" data-color="'+esc(fm[0])+'"><span class="cdot" style="background:'+fm[1]+'"></span>'+esc(fm[0])+'</div>'; }).join('') +
        '</div></div>';

      root.querySelectorAll('[data-brand]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('brand', el.getAttribute('data-brand')); }); });
      root.querySelectorAll('[data-cat]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('category', el.getAttribute('data-cat')); }); });
      root.querySelectorAll('[data-color]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('color', el.getAttribute('data-color')); }); });
    });
  }

  function setFilter(key, val){
    F[key] = (F[key] === val) ? '' : val;  // toggle
    F.page = 1; closeMenus(); renderActive(); loadGrid();
  }
  function renderActive(){
    var bits = [];
    ['brand','category','color'].forEach(function(k){
      if (F[k]) bits.push('<span class="chip" data-clear="'+k+'" style="display:inline-block;background:#f1f1fb;color:#27267e;border-radius:20px;padding:4px 12px;font-size:13px;margin-left:8px;cursor:pointer">'+esc(F[k])+' \u00d7</span>');
    });
    var el = document.getElementById('btActive');
    el.innerHTML = bits.join('');
    el.querySelectorAll('[data-clear]').forEach(function(c){ c.addEventListener('click', function(){ F[c.getAttribute('data-clear')]=''; F.page=1; renderActive(); loadGrid(); }); });
  }

  /* ---------- grid ---------- */
  function loadGrid(){
    var q = 'catalog?page=' + F.page + '&per=24';
    if (F.s) q += '&s=' + encodeURIComponent(F.s);
    if (F.brand) q += '&brand=' + encodeURIComponent(F.brand);
    if (F.category) q += '&category=' + encodeURIComponent(F.category);
    if (F.color) q += '&color=' + encodeURIComponent(F.color);
    var grid = document.getElementById('btGrid');
    grid.innerHTML = '<div style="grid-column:1/-1;padding:40px;text-align:center;color:#8a8aa0">Loading\u2026</div>';
    api(q).then(function(d){
      document.getElementById('btCount').textContent = d.total || 0;
      if (!d.items || !d.items.length){ grid.innerHTML = '<div class="noresults" style="grid-column:1/-1;text-align:center;padding:50px;color:#8a8aa0">No styles match.</div>'; document.getElementById('btPager').innerHTML=''; return; }
      grid.innerHTML = d.items.map(function(p){
        var img = p.thumb ? '<img src="'+esc(p.thumb)+'" loading="lazy" onerror="this.style.display=\'none\'">'
                          : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc;font-size:13px">No image</div>';
        return '<div class="pcard" data-id="'+p.id+'">' +
          '<div class="pimg">'+img+'</div>' +
          '<div class="pbody"><div class="pbrand">'+esc(p.brand)+'</div><div class="pname">'+esc(p.name||p.style)+'</div>' +
          '<div class="pstyle">Style '+esc(p.style)+'</div>' +
          '<div class="row"><div class="price">'+money(p.price)+' <small>/ea</small></div>' +
          '<div class="colorcount">'+p.colors+' colors available</div></div></div></div>';
      }).join('');
      grid.querySelectorAll('.pcard').forEach(function(c){ c.addEventListener('click', function(){ openPDP(c.getAttribute('data-id')); }); });
      renderPager(d.page, d.pages);
    });
  }
  function renderPager(page, pages){
    var el = document.getElementById('btPager');
    if (pages <= 1){ el.innerHTML=''; return; }
    el.innerHTML = '<button class="pgbtn" '+(page<=1?'disabled':'')+' data-go="'+(page-1)+'">\u2039 Prev</button>' +
      '<span class="pginfo">Page '+page+' of '+pages+'</span>' +
      '<button class="pgbtn" '+(page>=pages?'disabled':'')+' data-go="'+(page+1)+'">Next \u203a</button>';
    el.querySelectorAll('[data-go]').forEach(function(b){ b.addEventListener('click', function(){ if(b.disabled) return; F.page=parseInt(b.getAttribute('data-go'),10); loadGrid(); window.scrollTo({top:root.offsetTop,behavior:'smooth'}); }); });
  }

  /* ---------- product detail ---------- */
  function openPDP(id){
    api('catalog/item?id=' + id).then(function(p){
      if (!p || p.error) return;
      current = p; currentColor = (p.colors[0] && p.colors[0].name) || null;
      var pdp = document.getElementById('btPdp');
      pdp.innerHTML =
        '<div class="wrap"><span class="back">\u2190 Back to catalog</span>' +
        '<div class="pdp-grid"><div class="pdp-img" id="btPdpImg"></div><div>' +
          '<div class="pbrand">'+esc(p.brand)+'</div><h1>'+esc(p.name||p.style)+'</h1>' +
          '<div class="pstyle">Style '+esc(p.style)+'</div>' +
          '<div class="price">'+money(p.price)+' <small style="font-size:13px;color:#8a8aa0">/ea retail</small></div>' +
          '<div class="priceNote">Per-piece retail before decoration. Final price comes back on your quote.</div>' +
          '<p class="desc">'+esc(p.desc)+'</p>' +
          '<ul class="specs">'+ p.specs.map(function(s){ return '<li><span>'+esc(s[0])+'</span><span>'+esc(s[1])+'</span></li>'; }).join('') +'</ul>' +
          '<div class="lab">Color <span id="btColorName" style="color:#8a8aa0;text-transform:none;letter-spacing:0"></span></div>' +
          '<div class="colorgrid" id="btColors2"></div>' +
          '<div class="lab">Sizes &amp; quantity</div><div class="sizegrid" id="btSizes"></div>' +
          '<button class="addbtn" id="btAdd">Add to Quote</button>' +
        '</div></div></div>';
      pdp.classList.add('open');
      pdp.style.cssText = 'position:fixed;inset:0;z-index:80;background:#fff;overflow:auto;-webkit-overflow-scrolling:touch';
      document.documentElement.style.overflow = 'hidden';
      pdp.querySelector('.back').addEventListener('click', closePDP);

      renderColors(p);
      renderSizes(p);
      document.getElementById('btAdd').addEventListener('click', addToQuote);
    });
  }
  function closePDP(){ var p=document.getElementById('btPdp'); p.classList.remove('open'); p.style.cssText='display:none'; document.documentElement.style.overflow=''; }

  function renderColors(p){
    var box = document.getElementById('btColors2');
    box.innerHTML = p.colors.map(function(c){
      var sel = c.name === currentColor ? ' sel' : '';
      var sw = c.hex ? 'background:#'+c.hex.replace('#','') : 'background:#ddd';
      return '<div class="swatch'+sel+'" data-c="'+esc(c.name)+'"><span class="sw" style="'+sw+'"></span><span class="swn">'+esc(c.name)+'</span></div>';
    }).join('');
    setColorName();
    box.querySelectorAll('.swatch').forEach(function(s){ s.addEventListener('click', function(){
      currentColor = s.getAttribute('data-c');
      box.querySelectorAll('.swatch').forEach(function(x){ x.classList.remove('sel'); });
      s.classList.add('sel'); setColorName(); swapImage();
    }); });
    swapImage();
  }
  function setColorName(){ var el=document.getElementById('btColorName'); if(el) el.textContent = currentColor ? ('\u2014 '+currentColor) : ''; }
  function swapImage(){
    var c = current.colors.filter(function(x){ return x.name===currentColor; })[0] || current.colors[0];
    var img = document.getElementById('btPdpImg');
    if (!img) return;
    img.innerHTML = (c && c.img) ? '<img src="'+esc(c.img)+'" onerror="this.parentNode.innerHTML=\'<div style=&quot;color:#ccc&quot;>No image</div>\'">'
                                 : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc">No image</div>';
  }
  function renderSizes(p){
    var sizes = p.sizes && p.sizes.length ? p.sizes : ['S','M','L','XL','2XL'];
    document.getElementById('btSizes').innerHTML = sizes.map(function(z){
      return '<div class="sizecell"><span class="szl">'+esc(z)+'</span><input type="number" min="0" value="0" data-sz="'+esc(z)+'"></div>';
    }).join('');
  }

  function addToQuote(){
    var sizes = {};
    document.querySelectorAll('#btSizes input').forEach(function(i){ var v=parseInt(i.value||0,10); if(v>0) sizes[i.getAttribute('data-sz')]=v; });
    var qty = Object.keys(sizes).reduce(function(a,k){ return a+sizes[k]; }, 0);
    if (qty === 0){ alert('Add at least one size quantity.'); return; }
    var c = current.colors.filter(function(x){ return x.name===currentColor; })[0] || {};
    quote.push({ id:current.id, brand:current.brand, name:current.name||current.style, style:current.style,
      color:currentColor||'', img:c.img||'', price:current.price, sizes:sizes, qty:qty });
    updateBadge(); closePDP(); dStep=1; openDrawer();
  }

  /* ---------- quote drawer ---------- */
  function updateBadge(){ var n=quote.reduce(function(a,l){return a+l.qty;},0); document.getElementById('btBadge').textContent=n; }
  function openDrawer(){
    var d = document.getElementById('btDrawer');
    document.getElementById('btScrim').style.display='block';
    d.style.cssText = 'display:flex;flex-direction:column;position:fixed;top:0;right:0;bottom:0;z-index:80;'+
      'width:420px;max-width:100%;background:#fff;box-shadow:-8px 0 30px rgba(0,0,0,.18)';
    d.classList.add('open');
    document.documentElement.style.overflow='hidden';
    renderDrawer();
  }
  function closeDrawer(){
    document.getElementById('btScrim').style.display='none';
    var d=document.getElementById('btDrawer'); d.classList.remove('open'); d.style.display='none';
    document.documentElement.style.overflow='';
  }
  document.getElementById('btScrim').addEventListener('click', closeDrawer);

  function pricePer(){ // simple estimate: base + decoration per location
    var base = quote.length ? quote[0].price : 0;
    var deco = method==='print' ? (3 + (locs-1)*2.5) : (5 + (locs-1)*3);
    return base + deco;
  }
  function totalQty(){ return quote.reduce(function(a,l){return a+l.qty;},0); }

  function renderDrawer(){
    var d = document.getElementById('btDrawer');
    var head = '<div class="dhead"><div class="dtitle">Your Quote</div><button class="dx" id="btDx">\u2715</button></div>' +
      '<div class="steps">' +
      ['Garment','Decoration','Send'].map(function(lab,i){ var n=i+1; return '<div class="st'+(dStep===n?' on':'')+'" data-step="'+n+'"><b>'+n+'</b>'+lab+'</div>'; }).join('') +
      '</div>';
    var body = '<div class="dbody" id="btDbody" style="flex:1;overflow-y:auto"></div>';
    var foot = '<div class="dfoot" id="btDfoot"></div>';
    d.innerHTML = head + body + foot;
    d.querySelector('#btDx').addEventListener('click', closeDrawer);
    d.querySelectorAll('.steps .st').forEach(function(s){ s.addEventListener('click', function(){ goStep(parseInt(s.getAttribute('data-step'),10)); }); });
    if (dStep===1) stepGarment(); else if (dStep===2) stepDeco(); else stepSend();
  }
  function goStep(n){ if(n>1 && quote.length===0) return; dStep=n; renderDrawer(); }

  function stepGarment(){
    var b = document.getElementById('btDbody');
    if (!quote.length){ b.innerHTML = '<p style="padding:24px;text-align:center;color:#8a8aa0">Your quote is empty. Add a garment from the catalog.</p>'; document.getElementById('btDfoot').innerHTML=''; return; }
    b.innerHTML = quote.map(function(l,idx){
      var inputs = SIZES_ORDER.filter(function(z){return true;}).map(function(z){
        return '<label class="qsz"><span>'+z+'</span><input type="number" min="0" value="'+(l.sizes[z]||'')+'" placeholder="0" data-i="'+idx+'" data-z="'+z+'"></label>';
      }).join('');
      var th = l.img ? '<img src="'+esc(l.img)+'" onerror="this.style.display=\'none\'">' : '';
      return '<div class="qline"><div class="th">'+th+'</div><div class="meta">' +
        '<div class="n">'+esc(l.brand)+' '+esc(l.name)+'</div><div class="s">Style '+esc(l.style)+' \u00b7 '+esc(l.color)+'</div>' +
        '<div class="qsizes">'+inputs+'</div><div class="qsum" id="qsum'+idx+'">'+l.qty+' pcs \u00b7 '+money(l.price)+'/ea</div>' +
        '</div><button class="qrm" data-rm="'+idx+'">\u2715</button></div>';
    }).join('');
    b.querySelectorAll('.qsizes input').forEach(function(i){ i.addEventListener('input', function(){ updateLineSize(+i.getAttribute('data-i'), i.getAttribute('data-z'), i.value); }); });
    b.querySelectorAll('[data-rm]').forEach(function(x){ x.addEventListener('click', function(){ quote.splice(+x.getAttribute('data-rm'),1); updateBadge(); if(!quote.length) dStep=1; renderDrawer(); }); });
    document.getElementById('btDfoot').innerHTML = '<button class="primary" id="btNext">Next: Decoration</button>';
    document.getElementById('btNext').addEventListener('click', function(){ goStep(2); });
  }
  function updateLineSize(idx, z, val){
    val = parseInt(val||0,10);
    if (val>0) quote[idx].sizes[z]=val; else delete quote[idx].sizes[z];
    quote[idx].qty = Object.keys(quote[idx].sizes).reduce(function(a,k){return a+quote[idx].sizes[k];},0);
    updateBadge();
    var s = document.getElementById('qsum'+idx); if(s) s.textContent = quote[idx].qty+' pcs \u00b7 '+money(quote[idx].price)+'/ea';
  }

  function stepDeco(){
    var b = document.getElementById('btDbody');
    b.innerHTML =
      '<div class="secLab">How are we decorating it?</div>' +
      '<div class="methsel">' +
        '<button data-m="print" class="'+(method==='print'?'on':'')+'">Printed</button>' +
        '<button data-m="emb" class="'+(method==='emb'?'on':'')+'">Embroidered</button>' +
      '</div>' +
      '<div class="secLab">'+(method==='print'?'Print locations':'Stitch locations')+'</div>' +
      [1,2,3].map(function(n){ var ie=['Front Only','Front &amp; Back','Front, Back &amp; Sleeve'][n-1];
        return '<label class="optcard'+(locs===n?' on':'')+'" data-loc="'+n+'"><span class="oct">'+n+' Location'+(n>1?'s':'')+'</span><span class="ocs">ie: '+ie+'</span><span class="ocb">'+n+' LOC'+(n>1?'S':'')+'</span></label>';
      }).join('') +
      '<div class="declabel">All work is <b>full color, high quality</b>. Not sure yet? Leave it \u2014 we\'ll confirm with you.</div>' +
      '<div class="estp"><div class="estp-lab">Price per shirt</div><div class="estp-price"><sup>$</sup><big>'+pricePer().toFixed(2).split('.')[0]+'</big><sup>.'+pricePer().toFixed(2).split('.')[1]+'</sup></div>' +
        '<div class="estp-grid"><div class="et"><span>Qty</span><b>'+totalQty()+'</b></div><div class="et"><span>Decoration</span><b>'+(method==='print'?'Print':'Embroidery')+' \u00d7'+locs+'</b></div></div>' +
        '<div class="estp-calc"><span>'+money(pricePer())+' \u00d7 '+totalQty()+'</span><span class="tot">'+money(pricePer()*totalQty())+'</span></div>' +
        '<div class="estp-note">Estimate \u00b7 final quote confirmed by our team</div></div>';
    b.querySelectorAll('[data-m]').forEach(function(x){ x.addEventListener('click', function(){ method=x.getAttribute('data-m'); stepDeco(); }); });
    b.querySelectorAll('[data-loc]').forEach(function(x){ x.addEventListener('click', function(){ locs=+x.getAttribute('data-loc'); stepDeco(); }); });
    document.getElementById('btDfoot').innerHTML = '<button class="ghost" id="btBack">Back</button><button class="primary" id="btNext">Next: Send</button>';
    document.getElementById('btBack').addEventListener('click', function(){ goStep(1); });
    document.getElementById('btNext').addEventListener('click', function(){ goStep(3); });
  }

  function stepSend(){
    var b = document.getElementById('btDbody');
    if (sent){ b.innerHTML = '<div style="padding:30px;text-align:center"><div style="font-size:40px">\u2705</div><h3>Quote request sent!</h3><p style="color:#8a8aa0">Our team will follow up shortly with your final pricing.</p></div>'; document.getElementById('btDfoot').innerHTML=''; return; }
    b.innerHTML =
      '<div class="secLab">Where do we send your quote?</div>' +
      '<div class="field"><label>Name</label><input id="cName" value="'+esc(contact.name)+'" placeholder="Your name"></div>' +
      '<div class="field"><label>Email</label><input id="cEmail" value="'+esc(contact.email)+'" placeholder="you@email.com"></div>' +
      '<div class="field"><label>Phone (optional)</label><input id="cPhone" value="'+esc(contact.phone)+'" placeholder="(630) 555-0100"></div>' +
      '<div class="field"><label>Need it by? / notes</label><textarea rows="3" id="cNotes" placeholder="Art, deadline, anything else\u2026">'+esc(contact.notes)+'</textarea></div>';
    b.querySelector('#cName').addEventListener('input', function(e){ contact.name=e.target.value; });
    b.querySelector('#cEmail').addEventListener('input', function(e){ contact.email=e.target.value; });
    b.querySelector('#cPhone').addEventListener('input', function(e){ contact.phone=e.target.value; });
    b.querySelector('#cNotes').addEventListener('input', function(e){ contact.notes=e.target.value; });
    document.getElementById('btDfoot').innerHTML = '<button class="ghost" id="btBack">Back</button><button class="primary" id="btSend">Send quote request</button>';
    document.getElementById('btBack').addEventListener('click', function(){ goStep(2); });
    document.getElementById('btSend').addEventListener('click', function(){
      if(!contact.name || !contact.email){ alert('Please add your name and email.'); return; }
      sent = true; stepSend();   // submission wiring to the quote desk comes in the next step
    });
  }

  /* ---------- search ---------- */
  var sT;
  function bindSearch(){
    document.getElementById('btSearch').addEventListener('input', function(e){
      clearTimeout(sT); var v=e.target.value;
      sT = setTimeout(function(){ F.s=v; F.page=1; loadGrid(); }, 300);
    });
  }

  /* ---------- go ---------- */
  bindMenus(); bindSearch(); loadFacets(); loadGrid();
})();
