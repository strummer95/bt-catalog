/* BT Catalog storefront — data-driven from the REST API.
   Builds the whole UI inside #btcat-root; styled by catalog.css (the approved mock styles). */
(function () {
  var CFG = window.btcatCfg || { rest: '/wp-json/boomerts/v1/' };
  var REST = CFG.rest.replace(/\/?$/, '/');
  var SIZES_ORDER = ['XS','S','M','L','XL','2XL','3XL','4XL','5XL'];
  var FAMILIES = [['Black','#1d1d1d'],['White','#ffffff'],['Grey','#9aa0a8'],['Blue','#2049b8'],
    ['Red','#b3132a'],['Green','#1f7a44'],['Yellow','#e8a417'],['Orange','#e8601c'],
    ['Pink','#e535ab'],['Purple','#5b2a86'],['Neutral','#d8c6a0']];

  var F = { s:'', brand:'', category:'', fit:'', color:'', quality:'', sort:'', page:1 };
  var current = null, currentColor = null, curPid = null;

  /* ---------- shareable URL state ---------- */
  // NOTE: 'q' and 'pg' on purpose — 's' and 'page' are WordPress-reserved
  // query vars, and a full page load of /catalog/?s=... makes WP run a site
  // search instead of rendering the catalog page (hello 404 on browser Back).
  function syncURL(push){
    var q = [];
    if (F.s)        q.push('q=' + encodeURIComponent(F.s));
    if (F.brand)    q.push('brand=' + encodeURIComponent(F.brand));
    if (F.category) q.push('category=' + encodeURIComponent(F.category));
    if (F.fit)      q.push('fit=' + encodeURIComponent(F.fit));
    if (F.color)    q.push('color=' + encodeURIComponent(F.color));
    if (F.quality)  q.push('quality=' + encodeURIComponent(F.quality));
    if (F.sort)     q.push('sort=' + encodeURIComponent(F.sort));
    if (F.page > 1) q.push('pg=' + F.page);
    if (curPid)     q.push('pid=' + encodeURIComponent(curPid));
    var url = location.pathname + (q.length ? ('?' + q.join('&')) : '');
    try { if (push) history.pushState(null, '', url); else history.replaceState(null, '', url); } catch(e){}
  }
  function readURL(){
    var p;
    try { p = new URLSearchParams(location.search); } catch(e){ return null; }
    F.s        = p.get('q') || '';
    F.brand    = p.get('brand') || '';
    F.category = p.get('category') || '';
    F.fit      = p.get('fit') || '';
    F.color    = p.get('color') || '';
    F.quality  = p.get('quality') || '';
    F.sort     = p.get('sort') || '';
    F.page     = parseInt(p.get('pg') || '1', 10) || 1;
    return p.get('pid');
  }
  var quote = [], dStep = 1, method = 'print', locs = 1, embType = 'text', sent = false;
  var lastEst = null;
  var contact = { name:'', email:'', phone:'', notes:'' };
  var btScrollMem = 0;

  var root = document.getElementById('btcat-root');
  if (!root) return;

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function money(n){ return '$' + (Math.round(n*100)/100).toFixed(2); }
  // Short supplier tag shown before the style number (internal-friendly hint).
  function supLabel(s){ return s === 'ss' ? 'SS ' : s === 'sanmar' ? 'SM ' : s === 'egpro' ? 'EG ' : ''; }
  function api(path){ return fetch(REST + path, { credentials:'same-origin' }).then(function(r){ return r.json(); }); }

  /* ---------- shell ---------- */
  root.innerHTML =
    '<section class="hero"><div class="wrap herorow">' +
      '<h1>Browse Products. Get a <span>Quote.</span></h1>' +
      '<button id="btFab" class="btcat-fab">My Quote \u00b7 <span id="btBadge">0</span></button>' +
    '</div></section>' +
    '<div class="wrap"><div class="catnav">' +
      '<nav class="cmenus">' +
        '<div class="cm"><span class="cmlabel">Brands <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mBrands"></div></div>' +
        '<div class="cm"><span class="cmlabel">Categories <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mCats"></div></div>' +
        '<div class="cm"><span class="cmlabel">Fit <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mFit"></div></div>' +
        '<div class="cm"><span class="cmlabel">Colors <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mColors"></div></div>' +
        '<div class="cm"><span class="cmlabel">Quality <span class="cmcaret">\u25be</span></span><div class="cmpop mega-pop" id="mQuality"></div></div>' +
      '</nav>' +
      '<div class="csearch">\uD83D\uDD0D<input id="btSearch" placeholder="Search style # or product\u2026"></div>' +
    '</div></div>' +
    '<div class="wrap shell">' +
      '<aside class="btside" id="btSide">' +
        '<div class="fsec collapsed"><div class="fhead">Categories</div><div class="fbody" id="fCats"></div></div>' +
        '<div class="fsec collapsed"><div class="fhead">Fit</div><div class="fbody" id="fFit"></div></div>' +
        '<div class="fsec collapsed"><div class="fhead">Colors</div><div class="fbody fcolors" id="fColors"></div></div>' +
        '<div class="fsec collapsed"><div class="fhead">Brands</div><div class="fbody fscroll" id="fBrands"></div></div>' +
        '<div class="fsec collapsed"><div class="fhead">Quality</div><div class="fbody" id="fQuality"></div></div>' +
      '</aside>' +
      '<main>' +
      '<div class="toolbar"><div class="count"><b id="btCount">0</b> styles</div><div style="display:flex;align-items:center;gap:8px"><div id="btActive"></div>' +
        '<select id="btSort" class="sort">' +
          '<option value="">Sort: Featured</option>' +
          '<option value="price_asc">Price: Low to High</option>' +
          '<option value="price_desc">Price: High to Low</option>' +
          '<option value="name_asc">Name: A to Z</option>' +
          '<option value="brand_asc">Brand: A to Z</option>' +
        '</select></div></div>' +
      '<div class="grid" id="btGrid"></div>' +
      '<div class="pager" id="btPager"></div>' +
    '</main></div>' +
    '<div id="btPdp" class="pdp" style="display:none"></div>' +
    '<div id="btDrawer" class="drawer" style="display:none"></div>' +
    '<div id="btScrim" class="scrim" style="display:none;position:fixed;inset:0;z-index:75;background:rgba(0,0,0,.45)"></div>';

  // Quote button styling (inline, above the search bar)
  var fab = document.getElementById('btFab');
  fab.style.cssText = 'background:#e535ab;color:#fff;border:0;' +
    'font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:.5px;font-size:14px;padding:9px 18px;' +
    'border-radius:8px;box-shadow:0 2px 8px rgba(229,53,171,.3);cursor:pointer';
  fab.addEventListener('click', openDrawer);

  /* ---------- menus ---------- */
  function bindMenus(){
    var labels = root.querySelectorAll('.cmlabel');
    labels.forEach(function(l){ l.addEventListener('click', function(e){ e.stopPropagation(); toggleMenu(l.parentNode); }); });
    document.addEventListener('click', function(e){ if(!e.target.closest('.cm')) closeMenus(); });
    root.querySelectorAll('.fsec .fhead').forEach(function(h){ h.addEventListener('click', function(){ h.parentNode.classList.toggle('collapsed'); }); });
  }
  function toggleMenu(cm){ var o = cm.classList.contains('open'); closeMenus(); if(!o) cm.classList.add('open'); }
  function closeMenus(){ root.querySelectorAll('.cm.open').forEach(function(e){ e.classList.remove('open'); }); }

  function loadFacets(){
    api('catalog/facets').then(function(f){
      var b = (f && f.brands) || [], c = (f && f.categories) || [], fits = (f && f.fits) || [], quals = (f && f.qualities) || [];
      var bcols = (function(){
        var n = b.length, ncol = window.innerWidth < 600 ? 2 : (window.innerWidth < 900 ? 3 : 5);
        var per = Math.ceil(n / ncol) || 1, out = '';
        for (var ci = 0; ci < ncol; ci++) {
          var slice = b.slice(ci * per, (ci + 1) * per);
          if (!slice.length) continue;
          out += '<div class="brandcol">' + slice.map(function(x){ return '<div class="megai" data-brand="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') + '</div>';
        }
        return out;
      })();
      document.getElementById('mBrands').innerHTML = '<div class="mega megabrands">' + bcols + '</div>';
      document.getElementById('mCats').innerHTML =
        '<div class="mega"><div class="megacol">' +
        c.map(function(x){ return '<div class="megai" data-cat="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') +
        '</div></div>';
      document.getElementById('mFit').innerHTML =
        '<div class="mega"><div class="megacol">' +
        fits.map(function(x){ return '<div class="megai" data-fit="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') +
        '</div></div>';
      document.getElementById('mColors').innerHTML =
        '<div class="mega"><div class="megacol">' +
        FAMILIES.map(function(fm){ return '<div class="megai colori" data-color="'+esc(fm[0])+'"><span class="cdot" style="background:'+fm[1]+'"></span>'+esc(fm[0])+'</div>'; }).join('') +
        '</div></div>';
      document.getElementById('mQuality').innerHTML =
        '<div class="mega"><div class="megacol">' +
        quals.map(function(x){ return '<div class="megai" data-quality="'+esc(x)+'">'+esc(x)+'</div>'; }).join('') +
        '</div></div>';

      // sidebar lists (same data attrs as the header menus -> bound together below)
      var sCats = document.getElementById('fCats');
      var sCols = document.getElementById('fColors');
      var sBr   = document.getElementById('fBrands');
      if (sCats) sCats.innerHTML = c.map(function(x){ return '<div class="fitem" data-cat="'+esc(x)+'">'+esc(x)+'</div>'; }).join('');
      var sFit = document.getElementById('fFit');
      if (sFit) sFit.innerHTML = fits.map(function(x){ return '<div class="fitem" data-fit="'+esc(x)+'">'+esc(x)+'</div>'; }).join('');
      var sQual = document.getElementById('fQuality');
      if (sQual) sQual.innerHTML = quals.map(function(x){ return '<div class="fitem" data-quality="'+esc(x)+'">'+esc(x)+'</div>'; }).join('');
      if (sBr)   sBr.innerHTML   = b.map(function(x){ return '<div class="fitem" data-brand="'+esc(x)+'">'+esc(x)+'</div>'; }).join('');
      if (sCols) sCols.innerHTML = FAMILIES.map(function(fm){ return '<div class="fitem fcolor" data-color="'+esc(fm[0])+'"><span class="cdot" style="background:'+fm[1]+'"></span>'+esc(fm[0])+'</div>'; }).join('');

      root.querySelectorAll('[data-brand]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('brand', el.getAttribute('data-brand')); }); });
      root.querySelectorAll('[data-cat]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('category', el.getAttribute('data-cat')); }); });
      root.querySelectorAll('[data-color]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('color', el.getAttribute('data-color')); }); });
      root.querySelectorAll('[data-fit]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('fit', el.getAttribute('data-fit')); }); });
      root.querySelectorAll('[data-quality]').forEach(function(el){ el.addEventListener('click', function(){ setFilter('quality', el.getAttribute('data-quality')); }); });
      markActive();
    });
  }

  function setFilter(key, val){
    F[key] = (F[key] === val) ? '' : val;  // toggle
    F.page = 1; closeMenus(); renderActive(); syncURL(); loadGrid();
  }
  function renderActive(){
    var bits = [];
    ['brand','category','fit','color','quality'].forEach(function(k){
      if (F[k]) bits.push('<span class="chip" data-clear="'+k+'" style="display:inline-block;background:#f1f1fb;color:#27267e;border-radius:20px;padding:4px 12px;font-size:13px;margin-left:8px;cursor:pointer">'+esc(F[k])+' \u00d7</span>');
    });
    var el = document.getElementById('btActive');
    el.innerHTML = bits.join('');
    el.querySelectorAll('[data-clear]').forEach(function(c){ c.addEventListener('click', function(){ F[c.getAttribute('data-clear')]=''; F.page=1; renderActive(); syncURL(); loadGrid(); }); });
    markActive();
  }
  function markActive(){
    function on(el){
      return (el.getAttribute('data-brand') && el.getAttribute('data-brand') === F.brand) ||
             (el.getAttribute('data-cat')   && el.getAttribute('data-cat')   === F.category) ||
             (el.getAttribute('data-color') && el.getAttribute('data-color') === F.color) ||
             (el.getAttribute('data-fit')   && el.getAttribute('data-fit')   === F.fit) ||
             (el.getAttribute('data-quality') && el.getAttribute('data-quality') === F.quality);
    }
    root.querySelectorAll('.fitem').forEach(function(el){ el.classList.toggle('active', !!on(el)); });
    root.querySelectorAll('.megai').forEach(function(el){ el.classList.toggle('on', !!on(el)); });
  }

  /* ---------- grid ---------- */
  function loadGrid(){
    var q = 'catalog?page=' + F.page + '&per=24';
    if (F.s) q += '&s=' + encodeURIComponent(F.s);
    if (F.brand) q += '&brand=' + encodeURIComponent(F.brand);
    if (F.category) q += '&category=' + encodeURIComponent(F.category);
    if (F.fit) q += '&fit=' + encodeURIComponent(F.fit);
    if (F.quality) q += '&quality=' + encodeURIComponent(F.quality);
    if (F.color) q += '&color=' + encodeURIComponent(F.color);
    if (F.sort) q += '&sort=' + encodeURIComponent(F.sort);
    if (!F.s && !F.brand && !F.category && !F.fit && !F.color && !F.quality && !F.sort) q += '&featured=1';
    var grid = document.getElementById('btGrid');
    grid.innerHTML = '<div style="grid-column:1/-1;padding:40px;text-align:center;color:#8a8aa0">Loading\u2026</div>';
    api(q).then(function(d){
      document.getElementById('btCount').textContent = d.total || 0;
      var cwrap = document.getElementById('btCount').parentNode;
      if (cwrap) cwrap.lastChild.textContent = d.featured ? ' featured styles' : ' styles';
      if (!d.items || !d.items.length){ grid.innerHTML = '<div class="noresults" style="grid-column:1/-1;text-align:center;padding:50px;color:#8a8aa0">No styles match.</div>'; document.getElementById('btPager').innerHTML=''; return; }
      grid.innerHTML = d.items.map(function(p){
        var img = p.thumb ? '<img src="'+esc(p.thumb)+'" loading="lazy" onerror="this.style.display=\'none\'">'
                          : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc;font-size:13px">No image</div>';
        var onSale = !!(p.sale || p.was);
        var priceHtml;
        if (p.pmin != null && p.pmax != null && p.pmax > p.pmin) {
          // Colorways price differently (specials or white-vs-colors pricing):
          // show the range; red + Sale badge when any colorway is on special.
          priceHtml = '<div class="price'+(onSale?' onsale':'')+'">'+money(p.pmin)+'\u2013'+money(p.pmax)+' <small>/ea</small></div>';
        } else if (p.was) {
          priceHtml = '<div class="price onsale">'+money(p.price)+' <small>/ea</small> <s class="was">'+money(p.was)+'</s></div>';
        } else {
          priceHtml = '<div class="price">'+money((p.pmin != null ? p.pmin : p.price))+' <small>/ea</small></div>';
        }
        return '<div class="pcard" data-id="'+p.id+'">' +
          '<div class="pimg">'+(p.popular ? '<span class="poptag">Popular</span>' : '')+(onSale ? '<span class="saletag">Sale</span>' : '')+img+'</div>' +
          '<div class="pbody"><div class="pbrand">'+esc(p.brand)+'</div><div class="pname">'+esc(p.name||p.style)+'</div>' +
          '<div class="pstyle">'+supLabel(p.supplier)+'Style '+esc(p.style)+'</div>' +
          '<div class="row">'+priceHtml+
          '<div class="colorcount">'+p.colors+' colors available</div></div></div></div>';
      }).join('');
      grid.querySelectorAll('.pcard').forEach(function(){});
      renderPager(d.page, d.pages);
    });
  }
  function renderPager(page, pages){
    var el = document.getElementById('btPager');
    if (pages <= 1){ el.innerHTML=''; return; }
    el.innerHTML = '<button class="pgbtn" '+(page<=1?'disabled':'')+' data-go="'+(page-1)+'">\u2039 Prev</button>' +
      '<span class="pginfo">Page '+page+' of '+pages+'</span>' +
      '<button class="pgbtn" '+(page>=pages?'disabled':'')+' data-go="'+(page+1)+'">Next \u203a</button>';
    el.querySelectorAll('[data-go]').forEach(function(b){ b.addEventListener('click', function(){ if(b.disabled) return; F.page=parseInt(b.getAttribute('data-go'),10); syncURL(); loadGrid(); window.scrollTo({top:root.offsetTop,behavior:'smooth'}); }); });
  }

  /* ---------- preferred default colorway (navy-first), mirrors rest.php ---------- */
  function hexRgb(hex){ var h=String(hex||'').replace('#',''); if(h.length===3){h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];} if(!/^[0-9a-fA-F]{6}$/.test(h)) return null; return [parseInt(h.substr(0,2),16),parseInt(h.substr(2,2),16),parseInt(h.substr(4,2),16)]; }
  function colorRank(name, hex){
    var n=String(name||'').trim().toLowerCase();
    if (n==='navy') return 0;
    if (n.indexOf('navy')!==-1) return 1;
    var rgb=hexRgb(hex), lum=rgb?((rgb[0]+rgb[1]+rgb[2])/3):null;
    if ((rgb && lum<120 && (n.indexOf('blue')!==-1 || (rgb[2]>rgb[0]+15 && rgb[2]>rgb[1]+15))) || /midnight|indigo|royal|cobalt|marine/.test(n)) return 2;
    if (/gray|grey|charcoal|graphite|slate|oxford/.test(n)) return 3;
    if (rgb){ var mx=Math.max.apply(null,rgb), mn=Math.min.apply(null,rgb); if ((mx-mn)<=30 && lum>=50 && lum<=215) return 3; }
    return 99;
  }
  function preferredColorIdx(colors){
    var best=-1, br=999;
    for (var i=0;i<colors.length;i++){ if(!colors[i] || !colors[i].img) continue; var r=colorRank(colors[i].name, colors[i].hex); if(r<br){br=r;best=i;} }
    if (best>=0) return best;
    for (var j=0;j<colors.length;j++){ if(colors[j] && colors[j].img) return j; }
    return 0;
  }

  /* ---------- product detail ---------- */
  function openPDP(id, fromPop){
    api('catalog/item?id=' + id).then(function(p){
      if (!p || p.error){ console.error('BT Catalog: item load failed for id ' + id, p); alert('Sorry — could not load that product. Please try again.'); return; }
      var colors = Array.isArray(p.colors) ? p.colors : [];
      var specs  = Array.isArray(p.specs)  ? p.specs  : [];
      var sizes  = (Array.isArray(p.sizes) && p.sizes.length) ? p.sizes : ['S','M','L','XL','2XL'];
      current = p; current.colors = colors; current.sizes = sizes;
      currentColor = (colors[preferredColorIdx(colors)] || {}).name || (colors[0] && colors[0].name) || null;

      var pdp = document.getElementById('btPdp');
      pdp.innerHTML =
        '<div class="wrap"><span class="back">\u2190 Back to catalog</span>' +
        '<div class="pdp-grid"><div class="pdp-img" id="btPdpImg"></div><div>' +
          '<div class="pbrand">'+esc(p.brand)+'</div><h1>'+esc(p.name||p.style)+'</h1>' +
          '<div class="pstyle">'+supLabel(p.supplier)+'Style '+esc(p.style)+'</div>' +
          '<div class="price" id="btPdpPrice"></div>' +
          '<div class="priceNote">Per-piece retail before decoration. Final price comes back on your quote.</div>' +
          '<div class="desc">'+(p.desc||'')+'</div>' +
          '<ul class="specs">'+ specs.map(function(s){ return '<li><span>'+esc(s[0])+'</span><span>'+esc(s[1])+'</span></li>'; }).join('') +'</ul>' +
          '<div class="lab">Sizes &amp; quantity</div>' +
          '<div class="sizegrid" id="btSizes"></div>' +
          '<button class="addbtn" id="btAdd" style="margin-top:12px">Add to Quote</button>' +
          '<div class="lab">Color <span id="btColorName" style="color:#8a8aa0;text-transform:none;letter-spacing:0"></span></div>' +
          '<div class="colorgrid" id="btColors2"></div>' +
        '</div></div></div>';
      pdp.style.cssText = '';
      pdp.className = 'pdp show';
      root.classList.add('bt-pdp-open');
      btScrollMem = window.scrollY || window.pageYOffset || 0;
      window.scrollTo(0, 0);
      pdp.querySelector('.back').addEventListener('click', closePDP);

      renderColors(current);
      renderSizes(current);
      document.getElementById('btAdd').addEventListener('click', addToQuote);
      var wasOpen = !!curPid;
      curPid = String(id);
      if (fromPop) { syncURL(); }               // history already moved
      else { syncURL(!wasOpen); pdpPushed = !wasOpen || pdpPushed; }
    }).catch(function(err){ console.error('BT Catalog: item fetch error', err); alert('Sorry — could not load that product.'); });
  }
  var pdpPushed = false;
  function closePDP(skipHistory){
    var p=document.getElementById('btPdp'); p.className='pdp'; p.style.cssText='display:none';
    root.classList.remove('bt-pdp-open'); window.scrollTo(0, btScrollMem||0); curPid=null;
    if (!skipHistory){
      if (pdpPushed){ pdpPushed=false; history.back(); return; }   // popstate finishes the URL cleanup
      syncURL();
    }
    pdpPushed = false;
  }
  // Browser Back/Forward: close or open the product view in place instead of
  // doing a full page load (which is what used to 404 on WP's search hijack).
  window.addEventListener('popstate', function(){
    var pid = readURL();
    var si2 = document.getElementById('btSearch'); if (si2) si2.value = F.s;
    var so2 = document.getElementById('btSort');   if (so2) so2.value = F.sort;
    if (!pid && curPid){ closePDP(true); renderActive(); loadGrid(); return; }
    if (pid && pid !== curPid){ openPDP(pid, true); return; }
    if (!pid){ renderActive(); loadGrid(); }
  });

  function selColor(){
    return current.colors.filter(function(x){ return x.name===currentColor; })[0] || current.colors[0] || {};
  }
  function colorPrice(c){
    var price = (c && c.price != null) ? c.price : current.price;
    var was   = (c && c.price != null) ? c.was   : current.was;
    return { price: price, was: was || null };
  }
  function updatePdpPrice(){
    var el = document.getElementById('btPdpPrice'); if (!el) return;
    var pr = colorPrice(selColor());
    el.className = pr.was ? 'price onsale' : 'price';
    el.innerHTML = money(pr.price) + ' <small style="font-size:13px;color:#8a8aa0">/ea retail</small>' +
      (pr.was ? ' <s class="was">'+money(pr.was)+'</s> <span class="salepill">Sale</span>' : '');
  }

  function renderColors(p){
    var box = document.getElementById('btColors2');
    box.innerHTML = '<div class="colorprev" id="btColorPrev"></div>' + p.colors.map(function(c){
      var sel = c.name === currentColor ? ' sel' : '';
      var bg = c.swatch
        ? 'background-image:url('+c.swatch+');background-size:cover;background-position:center'
        : ('background:' + (c.hex ? '#' + String(c.hex).replace('#','') : '#dddddd'));
      var saleTag = (c.was != null && c.was > 0) ? '<span class="csale">Sale '+money(c.price)+'</span>' : '';
      return '<div class="copt'+sel+'" data-c="'+esc(c.name)+'"><div class="csq" style="'+bg+'"></div><span class="clabel">'+esc(c.name)+'</span>'+saleTag+'</div>';
    }).join('');
    setColorName();
    box.querySelectorAll('.copt').forEach(function(s){ s.addEventListener('click', function(){
      currentColor = s.getAttribute('data-c');
      box.querySelectorAll('.copt').forEach(function(x){ x.classList.remove('sel'); });
      s.classList.add('sel'); setColorName(); swapImage(); updatePdpPrice();
    }); });
    swapImage(); updatePdpPrice();
  }
  function setColorName(){ var el=document.getElementById('btColorName'); if(el) el.textContent = currentColor ? ('\u2014 '+currentColor) : ''; }
  function swapImage(){
    var c = current.colors.filter(function(x){ return x.name===currentColor; })[0] || current.colors[0];
    var has = c && c.img;
    var img = document.getElementById('btPdpImg');
    if (img) img.innerHTML = has ? '<img src="'+esc(c.img)+'" onerror="this.parentNode.innerHTML=\'<div style=&quot;color:#ccc&quot;>No image</div>\'">'
                                 : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc">No image</div>';
    var pv = document.getElementById('btColorPrev');
    if (pv) pv.innerHTML = has ? '<img src="'+esc(c.img)+'">' : '';
  }
  function renderSizes(p){
    var sizes = p.sizes && p.sizes.length ? p.sizes : ['S','M','L','XL','2XL'];
    document.getElementById('btSizes').innerHTML = sizes.map(function(z){
      return '<div class="sizebox"><div class="sz">'+esc(z)+'</div><input type="number" min="0" value="0" data-sz="'+esc(z)+'"></div>';
    }).join('');
  }

  function addToQuote(){
    var sizes = {};
    document.querySelectorAll('#btSizes input').forEach(function(i){ var v=parseInt(i.value||0,10); if(v>0) sizes[i.getAttribute('data-sz')]=v; });
    var qty = Object.keys(sizes).reduce(function(a,k){ return a+sizes[k]; }, 0);
    if (qty === 0){ alert('Add at least one size quantity.'); return; }
    var c = selColor();
    var pr = colorPrice(c);
    quote.push({ id:current.id, supplier:current.supplier, brand:current.brand, name:current.name||current.style, style:current.style,
      color:currentColor||'', img:c.img||'', price:pr.price, sizes:sizes, qty:qty });
    updateBadge(); closePDP(); dStep=1; openDrawer();
  }

  /* ---------- quote drawer ---------- */
  function updateBadge(){ var n=quote.reduce(function(a,l){return a+l.qty;},0); document.getElementById('btBadge').textContent=n; }
  function openDrawer(){
    var d = document.getElementById('btDrawer');
    document.getElementById('btScrim').style.display='block';
    d.style.cssText = 'display:flex;flex-direction:column;position:fixed;top:0;right:0;bottom:0;z-index:80;'+
      'width:min(420px,100vw);max-width:100vw;background:#fff;box-shadow:-8px 0 30px rgba(0,0,0,.18);transform:translateX(0)';
    d.classList.add('show');
    document.documentElement.style.overflow='hidden';
    renderDrawer();
  }
  function closeDrawer(){
    document.getElementById('btScrim').style.display='none';
    var d=document.getElementById('btDrawer'); d.classList.remove('show'); d.style.display='none';
    document.documentElement.style.overflow='';
  }
  document.getElementById('btScrim').addEventListener('click', closeDrawer);

  function totalQty(){ return quote.reduce(function(a,l){return a+l.qty;},0); }

  // Live price from the same Quick Quote endpoint the employee portal uses.
  function postPrice(cb){
    var params = 'qty=' + encodeURIComponent(totalQty()) +
                 '&garment=custom&retail=' + encodeURIComponent(quote[0] ? quote[0].price : 0);
    if (method==='emb') params += '&method=embroidery&embType=' + encodeURIComponent(embType);
    else                params += '&method=print&locations=' + encodeURIComponent(locs);
    fetch(REST + 'price', { method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params })
      .then(function(r){ return r.json(); }).then(cb).catch(function(){ cb(null); });
  }

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
        '<div class="n">'+esc(l.brand)+' '+esc(l.name)+'</div><div class="s">'+supLabel(l.supplier)+'Style '+esc(l.style)+' \u00b7 '+esc(l.color)+'</div>' +
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
    var sub = (method==='print')
      ? '<div class="secLab">Print locations</div>' +
        [1,2,3].map(function(n){ var ie=['Front Only','Front &amp; Back','Front, Back &amp; Sleeve'][n-1];
          return '<label class="optcard'+(locs===n?' on':'')+'" data-loc="'+n+'"><span class="oct">'+n+' Location'+(n>1?'s':'')+'</span><span class="ocs">ie: '+ie+'</span><span class="ocb">'+n+' LOC'+(n>1?'S':'')+'</span></label>';
        }).join('')
      : '<div class="secLab">Embroidery type</div>' +
        [['text','Names / Text'],['logo','Logo'],['hard','Hard-to-handle']].map(function(t){
          return '<label class="optcard'+(embType===t[0]?' on':'')+'" data-emb="'+t[0]+'"><span class="oct">'+t[1]+'</span></label>';
        }).join('');
    var qtyBlock = '<div class="secLab">Sizes &amp; quantity</div>' +
      quote.map(function(l,idx){
        var inputs = SIZES_ORDER.map(function(z){
          return '<label class="qsz"><span>'+z+'</span><input type="number" min="0" value="'+(l.sizes[z]||'')+'" placeholder="0" data-i="'+idx+'" data-z="'+z+'"></label>';
        }).join('');
        return '<div class="qline"><div class="meta"><div class="n">'+esc(l.brand)+' '+esc(l.name)+'</div><div class="s">'+supLabel(l.supplier)+'Style '+esc(l.style)+' \u00b7 '+esc(l.color)+'</div><div class="qsizes">'+inputs+'</div><div class="qsum" id="qsum'+idx+'">'+l.qty+' pcs \u00b7 '+money(l.price)+'/ea</div></div></div>';
      }).join('');
    b.innerHTML =
      '<div class="secLab">How are we decorating it?</div>' +
      '<div class="methsel">' +
        '<button data-m="print" class="'+(method==='print'?'on':'')+'">Printed</button>' +
        '<button data-m="emb" class="'+(method==='emb'?'on':'')+'">Embroidered</button>' +
      '</div>' +
      sub + qtyBlock +
      '<div class="declabel">All work is <b>full color, high quality</b>. Not sure yet? Leave it \u2014 we\'ll confirm with you.</div>' +
      '<div class="estp" id="btEst"><div class="estp-note">Calculating\u2026</div></div>';
    b.querySelectorAll('[data-m]').forEach(function(x){ x.addEventListener('click', function(){ method=x.getAttribute('data-m'); stepDeco(); }); });
    b.querySelectorAll('[data-loc]').forEach(function(x){ x.addEventListener('click', function(){ locs=+x.getAttribute('data-loc'); stepDeco(); }); });
    b.querySelectorAll('[data-emb]').forEach(function(x){ x.addEventListener('click', function(){ embType=x.getAttribute('data-emb'); stepDeco(); }); });
    document.getElementById('btDfoot').innerHTML = '<button class="ghost" id="btBack">Back</button><button class="primary" id="btNext">Next: Send</button>';
    document.getElementById('btBack').addEventListener('click', function(){ goStep(1); });
    document.getElementById('btNext').addEventListener('click', function(){ goStep(3); });

    function renderEst(){
    var decoLabel = (method==='emb')
      ? ({text:'Names/Text',logo:'Logo',hard:'Hard-to-handle'}[embType]||'Embroidery')
      : ('Print \u00d7'+locs);
    postPrice(function(d){
      var est = document.getElementById('btEst'); if(!est) return;
      lastEst = d;
      if(!d){ est.innerHTML = '<div class="estp-note">Price will be confirmed on your quote.</div>'; return; }
      if(d.perShirt == null){   // embroidery 84+ → by quote
        est.innerHTML = '<div class="estp-lab">Price per shirt</div><div class="estp-price"><big style="font-size:26px">By quote</big></div>' +
          '<div class="estp-grid"><div class="et"><span>Qty</span><b>'+totalQty()+'</b></div><div class="et"><span>Decoration</span><b>'+decoLabel+'</b></div></div>' +
          '<div class="estp-note">Larger embroidery orders are custom-quoted by our team.</div>';
        return;
      }
      var ps = (+d.perShirt).toFixed(2).split('.');
      est.innerHTML =
        '<div class="estp-lab">Price per shirt</div>' +
        '<div class="estp-price"><sup>$</sup><big>'+ps[0]+'</big><sup>.'+ps[1]+'</sup></div>' +
        '<div class="estp-grid"><div class="et"><span>Qty</span><b>'+totalQty()+'</b></div><div class="et"><span>Decoration</span><b>'+decoLabel+'</b></div></div>' +
        '<div class="estp-calc"><span>'+money(d.perShirt)+' \u00d7 '+totalQty()+(d.discPct?(' \u00b7 '+d.discPct+'% off'):'')+'</span><span class="tot">'+money(d.total)+'</span></div>' +
        '<div class="estp-note">Estimate \u00b7 final quote confirmed by our team</div>';
    });
    }
    var estTimer=null;
    b.querySelectorAll('.qsizes input').forEach(function(i){ i.addEventListener('input', function(){
      updateLineSize(+i.getAttribute('data-i'), i.getAttribute('data-z'), i.value);
      clearTimeout(estTimer); estTimer=setTimeout(renderEst,300);
    }); });
    renderEst();
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
      sT = setTimeout(function(){ F.s=v; F.page=1; syncURL(); loadGrid(); }, 300);
    });
  }

  /* ---------- sort ---------- */
  function bindSort(){
    var sel = document.getElementById('btSort');
    if (!sel) return;
    sel.value = F.sort;
    sel.addEventListener('change', function(){ F.sort = sel.value; F.page = 1; syncURL(); loadGrid(); });
  }

  /* ---------- go ---------- */
  document.getElementById('btGrid').addEventListener('click', function(e){
    var card = e.target.closest('.pcard');
    if (card && card.getAttribute('data-id')) openPDP(card.getAttribute('data-id'));
  });
  bindMenus(); bindSearch(); bindSort(); loadFacets();
  var bootPid = readURL();
  var si = document.getElementById('btSearch'); if (si) si.value = F.s;
  renderActive();
  loadGrid();
  if (bootPid) openPDP(bootPid, true);   // boot: replace, don't push
})();
