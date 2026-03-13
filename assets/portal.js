(function(){
  var cfg = window.AdminHavnPortal || {};
  // cache for responsive re-rendering of timeline on resize
  var __lastTimeline = null;
  // timeline prefetch/cache (keeps arrows snappy)
  var __tlCache = new Map();
  function __tlKey(scale,start,end){
    return String(scale||'month') + '|' + String(start||'') + '|' + String(end||'');
  }
  function setShellHeight(){
    var root = document.getElementById('ah-portal-root');
    if(!root) return;
    var top = root.getBoundingClientRect().top;
    var h = Math.max(360, Math.floor(window.innerHeight - top - 16));
    root.style.setProperty('--ah-shell-h', h + 'px');
  }
  function q(s,el){return (el||document).querySelector(s)}
  function qa(s,el){return Array.prototype.slice.call((el||document).querySelectorAll(s))}
  function esc(s){
    s = String(s==null?'':s);
    s = s.replaceAll('&','&amp;');
    s = s.replaceAll('<','&lt;');
    s = s.replaceAll('>','&gt;');
	    	s = s.replaceAll('"','&quot;');
	    	s = s.replaceAll("'",'&#039;');
    return s;
  }
  function api(action,data){
    var fd = new FormData();
    fd.append('action',action);
    fd.append('_ajax_nonce',cfg.nonce||'');
    Object.keys(data||{}).forEach(function(k){fd.append(k, data[k]==null?'':String(data[k]))});
    return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json()});
  }
  
  
  function parseQuery(){
    var out={};
    try{
      var qs=window.location.search||'';
      qs.replace(/^\?/,'').split('&').forEach(function(part){
        if(!part) return;
        var kv=part.split('=');
        out[decodeURIComponent(kv[0]||'')] = decodeURIComponent((kv[1]||'').replace(/\+/g,' '));
      });
    }catch(e){}
    return out;
  }

  function ymd(d){
    var y=d.getFullYear();
    var m=String(d.getMonth()+1).padStart(2,'0');
    var da=String(d.getDate()).padStart(2,'0');
    return y+'-'+m+'-'+da;
  }
  function addDays(d,n){ var x=new Date(d.getTime()); x.setDate(x.getDate()+n); return x; }
  function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function addMonths(d,n){ return new Date(d.getFullYear(), d.getMonth()+n, 1); }

  function clamp(n,min,max){ return Math.max(min, Math.min(max, n)); }

  // Best-effort: hvor mange måneder/uker som "får plass" basert på skjermbredde.
  // Brukes for å velge periode vi henter fra backend. Selve tidslinjen kan fortsatt
  // horisontal-scrolles ved behov.
  function computeSpan(scale){
    var w = Math.max(320, window.innerWidth || 1200);
    // Grov beregning: venstreside + paddings/rammer tar ca 600px i praksis.
    var usable = Math.max(260, w - 620);
    if(scale==='week'){
      // 1 uke = 7 dager, typisk 2-8 uker synlig
      var weeks = clamp(Math.floor(usable / 260), 2, 8);
      return { weeks: weeks, months: null };
    }
    // month: typisk 2-6 måneder synlig
    var months = clamp(Math.floor(usable / 320), 2, 6);
    return { weeks: null, months: months };
  }

  function loadTimeline(start,end,scale){
    var key = __tlKey(scale||'month', start, end);
    if(__tlCache.has(key)) return Promise.resolve(__tlCache.get(key));
    return api('admin_havn_portal_utleie_timeline',{start:start,end:end}).then(function(res){
      // cache both success and failure briefly (failures still useful to avoid hammering)
      __tlCache.set(key, res);
      // cap cache size
      try{
        if(__tlCache.size > 18){
          var first = __tlCache.keys().next().value;
          __tlCache.delete(first);
        }
      }catch(e){}
      return res;
    });
  }

  function renderTimeline(data, start, end, scale){
    q('#ah-title').textContent = 'Utleie – tidslinje';
    q('#ah-meta').innerHTML = '<span class="ah-hint">Periode: <strong>'+esc(start)+' → '+esc(end)+'</strong></span>';
    var actions = q('.ah-actions');
    if(actions) actions.style.display='none';

    var panel = q('#ah-panel-body');
    if(!panel) return;

    scale = (scale||'month');
    if(scale!=='week' && scale!=='month') scale = 'month';

    var sDate = new Date(start+'T00:00:00');
    var eDate = new Date(end+'T00:00:00');
    var MS_DAY = 24*3600*1000;
    var totalDays = Math.max(1, Math.round((eDate - sDate)/MS_DAY));

    // Visningsmodus
    // - week: 7 dager (dag-kolonner)
    // - month: 3 måneder vindu (uke-kolonner)
    var useWeekly = (scale==='month');
    var UNIT_DAYS = useWeekly ? 7 : 1;
    var totalCols = Math.max(1, Math.ceil(totalDays / UNIT_DAYS));

    // Kolonnebredde (fast, lesbar)
    var COL_W = useWeekly ? 48 : 44;
    var totalW = totalCols * COL_W;

    // cache for resize
    __lastTimeline = { data: data, start: start, end: end, scale: scale };

    function isoWeek(d){
      // ISO week number
      var date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
      var dayNum = date.getUTCDay() || 7;
      date.setUTCDate(date.getUTCDate() + 4 - dayNum);
      var yearStart = new Date(Date.UTC(date.getUTCFullYear(),0,1));
      return Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
    }

    // Normalize payload
    var spots = (data && data.spots) ? data.spots : [];
    var leases = (data && data.leases) ? data.leases : [];

    // Sorter spots stabilt (bredde -> kode), og gjør klar gruppering på venstresiden.
    spots = spots.slice().sort(function(a,b){
      var aw = parseFloat(String(a.width||a.bredde||'').replace(',','.'));
      var bw = parseFloat(String(b.width||b.bredde||'').replace(',','.'));
      if(isNaN(aw)) aw = 9999;
      if(isNaN(bw)) bw = 9999;
      if(aw !== bw) return aw - bw;
      var ac = String(a.code||a.batplasskode||a.title||'');
      var bc = String(b.code||b.batplasskode||b.title||'');
      return ac.localeCompare(bc,'nb');
    });
    var bySpot = {};
    leases.forEach(function(l){
      var sid = String(l.batplass_id||l.spot_id||l.batplassId||'');
      if(!sid) return;
      if(!bySpot[sid]) bySpot[sid]=[];
      bySpot[sid].push(l);
    });

    // Month segments + column cells (daily/weekly grid)
    var monthSegs=[];
    var colCells=[]; // {i,label,wknd,alt}
    var curMonth = null;
    var curCount = 0;
    function pushMonth(label, count){
      if(!label || !count) return;
      monthSegs.push({label: label, count: count});
    }
    for(var i=0;i<totalCols;i++){
      var d=addDays(sDate, i*UNIT_DAYS);
      var mLabel = String(d.getMonth()+1).padStart(2,'0') + '.' + d.getFullYear();
      if(curMonth===null){ curMonth=mLabel; curCount=0; }
      if(mLabel!==curMonth){
        pushMonth(curMonth, curCount);
        curMonth=mLabel; curCount=0;
      }
      curCount++;

      var label = useWeekly ? ('U' + String(isoWeek(d)).padStart(2,'0')) : String(d.getDate()).padStart(2,'0');
      var wknd = (!useWeekly) && ((d.getDay()===0)||(d.getDay()===6));
      var alt  = useWeekly && (i%2===1);
      colCells.push({i:i, label: label, wknd: wknd, alt: alt});
    }
    pushMonth(curMonth, curCount);

panel.innerHTML =
      '<div class="ah-tl-controls">'
        + '<div class="ah-tl-controls__left">'
          + '<button type="button" class="ah-tl-btn" id="ah-tl-prev" title="Forrige periode">◀</button>'
          + '<button type="button" class="ah-tl-btn" id="ah-tl-next" title="Neste periode">▶</button>'
          + '<span class="ah-tl-controls__label">Vis:</span>'
          + '<select id="ah-tl-scale" class="ah-tl-select">'
            + '<option value="month">Måned</option>'
            + '<option value="week">Uke</option>'
          + '</select>'
        + '</div>'
        + '<div class="ah-tl-controls__right">'
          + '<span class="ah-hint">Tips: horisontal scroll for flere dager</span>'
        + '</div>'
      + '</div>'
      + '<div class="ah-tl2">' +
        '<div class="ah-tl2__left">' +
          '<div class="ah-tl2__h">Bredde / Båtplass</div>' +
          '<div class="ah-tl2__left-body" id="ah-tl2-left">' +
            (function(){
              var out = '';
              var last = null;
              spots.forEach(function(sp){
                var w = sp.width || sp.bredde || '';
                var wNorm = String(w||'').replace('.',',');
                var key = wNorm ? (wNorm + ' m') : 'Ukjent bredde';
                if(key !== last){
                  out += '<div class="ah-tl2__group">'+esc(key)+'</div>';
                  last = key;
                }
                out += '<div class="ah-tl2__label" data-id="'+esc(sp.id)+'">'+esc(sp.code||('ID '+sp.id))+'</div>';
              });
              return out;
            })() +
          '</div>' +
        '</div>' +
        '<div class="ah-tl2__right">' +
          // Main scroll area (both axis)
          '<div class="ah-tl2__scroll" id="ah-tl2-scroll">' +
            '<div class="ah-tl2__months" style="width:'+totalW+'px">' +
              monthSegs.map(function(m){
                return '<div class="ah-tl2__month" style="width:'+(m.count*COL_W)+'px">'+esc(m.label)+'</div>';
              }).join('') +
            '</div>' +
            '<div class="ah-tl2__days" style="width:'+totalW+'px">' +
              colCells.map(function(dc){
                return '<div class="ah-tl2__day '+(dc.wknd?'is-wknd':'')+(dc.alt?' is-alt':'')+'" style="width:'+COL_W+'px">'+esc(dc.label)+'</div>';
              }).join('') +
            '</div>' +
            '<div class="ah-tl2__rows">' +
              spots.map(function(sp){
                var lid = String(sp.id);
                var ls = bySpot[lid]||[];
                var wkndBands = (!useWeekly) ? colCells.filter(function(dc){return dc.wknd;}).map(function(dc){
                  return '<div class="ah-tl2__wknd" style="left:'+(dc.i*COL_W)+'px;width:'+COL_W+'px"></div>';
                }).join('') : '';
                var bars = ls.map(function(l){
                  var lf=new Date(l.from+'T00:00:00');
                  var lt=new Date(l.to+'T00:00:00');
                  var a = Math.max(0, Math.floor(((lf - sDate)/MS_DAY)/UNIT_DAYS));
                  var b = Math.min(totalCols, Math.ceil(((lt - sDate)/MS_DAY)/UNIT_DAYS));
                  var leftPx = a*COL_W;
                  var widthPx = Math.max(COL_W, (b-a)*COL_W);
                  var label = (l.name||'') + ' (' + l.from.slice(5) + '–' + l.to.slice(5) + ')';
                  return '<div class="ah-tl2__bar" data-utleie-id="'+esc(l.id)+'" data-spot-id="'+esc(sp.id)+'" style="left:'+leftPx+'px;width:'+widthPx+'px" title="'+esc(label)+'">'+esc(l.name||'')+'</div>';
                }).join('');
                return '<div class="ah-tl2__row" data-id="'+esc(sp.id)+'" style="width:'+totalW+'px">'+wkndBands+bars+'</div>';
              }).join('') +
            '</div>' +
          '</div>' +
          // Extra horizontal scrollbar (always visible on Windows, helpful on Mac overlay scrollbars)
          '<div class="ah-tl2__hscroll" id="ah-tl2-hscroll" aria-hidden="true">'
            + '<div class="ah-tl2__hscroll-inner" style="width:'+totalW+'px"></div>'
          + '</div>'
        + '</div>' +
      '</div>';

    // Range + navigation (client-side, no full page reload)
    try{
      var scaleSel = q('#ah-tl-scale', panel);
      if(scaleSel){
        scaleSel.value = String(scale);
        scaleSel.addEventListener('change', function(){
          timelineNavigate(this.value, new Date(start+'T00:00:00'));
        });
      }

      function shift(dir){
        var sc = String(scale||'month');
        if(sc!=='week' && sc!=='month') sc='month';
        var st = new Date(start+'T00:00:00');
        if(isNaN(st.getTime())) st = new Date();
        var nst;
        if(sc==='week') nst = addDays(st, dir*7);
        else nst = addMonths(startOfMonth(st), dir*1);
        timelineNavigate(sc, nst);
      }
      var prevBtn = q('#ah-tl-prev', panel);
      var nextBtn = q('#ah-tl-next', panel);
      if(prevBtn) prevBtn.addEventListener('click', function(){shift(-1)});
      if(nextBtn) nextBtn.addEventListener('click', function(){shift(1)});
    }catch(e){}

    function timelineNavigate(newScale, newStartDate){
      try{
        var sc = String(newScale||scale||'month');
        if(sc!=='week' && sc!=='month') sc='month';
        var sd = newStartDate;
        if(!(sd instanceof Date) || isNaN(sd.getTime())) sd = new Date();

        var startDate, endDate;
        if(sc==='week'){
          var spanW = computeSpan('week').weeks;
          startDate = new Date(sd.getFullYear(), sd.getMonth(), sd.getDate());
          endDate = addDays(startDate, spanW*7);
        } else {
          var spanM = computeSpan('month').months;
          startDate = startOfMonth(sd);
          endDate = addMonths(startDate, spanM);
        }
        var ns = ymd(startDate);
        var ne = ymd(endDate);

        // Update URL (deep-link) without reloading
        var qs = parseQuery();
        qs.mode = 'timeline';
        qs.scale = sc;
        qs.start = ns;
        var parts=[];
        Object.keys(qs).forEach(function(k){ if(!k) return; parts.push(encodeURIComponent(k)+'='+encodeURIComponent(qs[k])); });
        try{ window.history.replaceState({}, '', '?' + parts.join('&')); }catch(e){}

        // Load & render
        var panelEl = q('#ah-panel-body');
        if(panelEl){
          var hint = q('.ah-tl-controls__right .ah-hint', panel);
          if(hint) hint.textContent = 'Laster…';
        }
        loadTimeline(ns, ne, sc).then(function(r){
          if(r && r.success){
            renderTimeline(r.data, r.data.start, r.data.end, sc);
            schedulePrefetch(sc, startDate);
          } else {
            if(panelEl) panelEl.textContent = (r&&r.data&&r.data.message)||'Kunne ikke laste tidslinje.';
          }
        });
      }catch(e){}
    }

    function schedulePrefetch(sc, startDate){
      try{
        // wait a beat so UI stays responsive
        setTimeout(function(){
          var prevStart, nextStart;
          if(sc==='week'){
            prevStart = addDays(startDate, -7);
            nextStart = addDays(startDate, 7);
          } else {
            prevStart = addMonths(startOfMonth(startDate), -1);
            nextStart = addMonths(startOfMonth(startDate), 1);
          }
          // compute end for those starts
          var span = (sc==='week') ? computeSpan('week').weeks : computeSpan('month').months;
          var prevEnd = (sc==='week') ? addDays(prevStart, span*7) : addMonths(prevStart, span);
          var nextEnd = (sc==='week') ? addDays(nextStart, span*7) : addMonths(nextStart, span);
          loadTimeline(ymd(prevStart), ymd(prevEnd), sc).catch(function(){});
          loadTimeline(ymd(nextStart), ymd(nextEnd), sc).catch(function(){});
        }, 220);
      }catch(e){}
    }
// Sync vertical scroll between left labels and right rows
    var leftBody = q('#ah-tl2-left', panel);
    var scroll = q('#ah-tl2-scroll', panel);
    var hscroll = q('#ah-tl2-hscroll', panel);
    if(leftBody && scroll){
      scroll.addEventListener('scroll', function(){ leftBody.scrollTop = scroll.scrollTop; if(hscroll) hscroll.scrollLeft = scroll.scrollLeft; });
      leftBody.addEventListener('scroll', function(){ scroll.scrollTop = leftBody.scrollTop; });
    }
    if(scroll && hscroll){
      // Keep x scroll in sync both ways
      var syncing = false;
      scroll.addEventListener('scroll', function(){
        if(syncing) return; syncing = true;
        hscroll.scrollLeft = scroll.scrollLeft;
        syncing = false;
      });
      hscroll.addEventListener('scroll', function(){
        if(syncing) return; syncing = true;
        scroll.scrollLeft = hscroll.scrollLeft;
        syncing = false;
      });
    }

    // Click behaviour:
    // 1) Click an existing bar -> open details
    // 2) Click empty grid twice (from/to) -> jump to list view with dates prefilled
    var sel = { spotId: null, a: null, el: null };

    function clearSel(){
      if(sel.el && sel.el.parentNode) sel.el.parentNode.removeChild(sel.el);
      sel = { spotId: null, a: null, el: null };
    }

    function setSel(rowEl, a, b){
      if(!rowEl) return;
      if(!sel.el){
        sel.el = document.createElement('div');
        sel.el.className = 'ah-tl2__sel';
        rowEl.appendChild(sel.el);
      }
      var lo = Math.min(a,b);
      var hi = Math.max(a,b);
      sel.el.style.left = (lo*COL_W) + 'px';
      sel.el.style.width = Math.max(COL_W, (hi-lo+1)*COL_W) + 'px';
    }

    function gotoCreate(spotId, a, b){
      var lo = Math.min(a,b);
      var hi = Math.max(a,b);
      var fromD = addDays(sDate, lo*UNIT_DAYS);
      var toD = addDays(sDate, ((hi+1)*UNIT_DAYS) - 1);
      var qs = parseQuery();
      qs.mode = 'list';
      qs.select = String(spotId);
      qs.from = ymd(fromD);
      qs.to = ymd(toD);
      // Behold scale/start (nyttig når man går tilbake)
      if(!qs.scale) qs.scale = String(scale);
      if(!qs.start) qs.start = start;
      var parts=[];
      Object.keys(qs).forEach(function(k){ if(!k) return; parts.push(encodeURIComponent(k)+'='+encodeURIComponent(qs[k])); });
      window.location.search = '?' + parts.join('&');
    }

    panel.addEventListener('click', function(e){
      var bar = e.target.closest('.ah-tl2__bar');
      if(bar){
        clearSel();
        var spotId = bar.getAttribute('data-spot-id');
        if(spotId){
          setActive(spotId);
          loadDetail(spotId);
        }
        return;
      }

      var row = e.target.closest('.ah-tl2__row');
      if(!row) return;
      // Ignore clicks on sticky header etc.
      if(e.target.closest('.ah-tl2__months') || e.target.closest('.ah-tl2__days')) return;

      var spotId2 = row.getAttribute('data-id');
      if(!spotId2) return;

      // Compute column
      var rect = row.getBoundingClientRect();
      var x = e.clientX - rect.left;
      var col = Math.max(0, Math.min(totalCols-1, Math.floor(x / COL_W)));

      if(sel.spotId !== spotId2 || sel.a === null){
        clearSel();
        sel.spotId = spotId2;
        sel.a = col;
        setSel(row, col, col);
        return;
      }

      // Second click: finalize
      setSel(row, sel.a, col);
      gotoCreate(spotId2, sel.a, col);
    });
  }
function calcRental(object, from, to, year){
    return api('admin_havn_calc_rental', {object: object||'boat', from: from||'', to: to||'', year: year||''});
  }
  function setupRentalCalc(scope){
    window.__ah_calc_ok = true;
    var form = q('#ah-form', scope||document);
    if(!form) return;
    var fromEl = q('input[name="admin_havn_utleie_fra"]', form);
    var toEl   = q('input[name="admin_havn_utleie_til"]', form);
    var belEl  = q('input[name="admin_havn_utleie_belop"]', form);
    var box    = q('#ah-calc', scope||document);
    if(!fromEl || !toEl || !belEl || !box) return;

    function run(){
      var from = fromEl.value;
      var to = toEl.value;
      if(!from || !to){
        box.textContent = 'Velg fra/til dato for å beregne pris.';
        window.__ah_calc_ok = false;
        setDirty(window.__ah_dirty);
        return;
      }
      box.textContent = 'Beregner…';
      window.__ah_calc_ok = false;
      setDirty(window.__ah_dirty);

      var year = from ? parseInt(from.slice(0,4),10) : '';
      calcRental('boat', from, to, year).then(function(res){
        if(!res || !res.ok){
          box.textContent = (res && res.error) ? res.error : 'Kunne ikke beregne pris.';
          belEl.value = '';
          window.__ah_calc_ok = false;
          setDirty(window.__ah_dirty);
          return;
        }
        belEl.value = String(res.total != null ? res.total : '');
        var lines = (res.breakdown||[]).map(function(b){ return '• ' + (b.label||''); }).join('\n');
        var minTxt = '';
        if(res.min_days && res.min_days>0){
          if(res.min_ok){
            minTxt = '\nMinste leietid OK (min ' + Math.ceil(res.min_days/7) + ' uker).';
          } else {
            // Tillat kortere periode, men informer om at prisen er beregnet som minimum.
            var mw = Math.ceil(res.min_days/7);
            minTxt = '\nKortere enn minste leietid ('+mw+' uker). Pris er beregnet som minimumsperiode.';
          }
        }
        box.textContent = 'Sum: ' + belEl.value + ' NOK\n' + lines + minTxt;
        // Beregning OK så lenge vi fikk et beløp – minste leietid håndteres i prisen.
        window.__ah_calc_ok = true;
        setDirty(window.__ah_dirty);
      }).catch(function(){
        box.textContent = 'Kunne ikke beregne pris (nettverk).';
        window.__ah_calc_ok = false;
        setDirty(window.__ah_dirty);
      });
    }

    fromEl.addEventListener('change', run);
    toEl.addEventListener('change', run);
    run();
  }

function badge(text, cls){return '<span class="'+esc(cls||'ah-badge')+'">'+esc(text||'—')+'</span>'}
  function setActive(id){qa('#ah-list-body tr').forEach(function(tr){tr.classList.toggle('ah-active', tr.getAttribute('data-id')===String(id))})}
  function renderList(rows){
    var tb = q('#ah-list-body');
    if(cfg.view==='faktura'){
      tb.innerHTML = rows.map(function(r){
        var no = r.agreement_no||r.id;
        var left = 'Avtale #' + esc(no);
        var sub = esc(r.customer||'') + ' • ' + esc(r.type||'') + (r.created_at?(' • '+esc(String(r.created_at).slice(0,10))):'');
        var st = (r.status||'').toString();
        var total = r.total!=null ? ('NOK ' + esc(r.total)) : '';
        return '<tr data-id="'+esc(no)+'"><td><div style="font-weight:750">'+left+'</div><small>'+sub+'</small></td>'
          +'<td style="width:1%;white-space:nowrap">'+badge(st||'—','ah-badge ah-badge--status')+'<div style="text-align:right"><small>'+total+'</small></div></td></tr>';
      }).join('');
      return;
    }
    if(cfg.view==='utleie'){
      // Gruppér båtplasser på bredde i venstre liste
      var out='';
      var last=null;
      rows.forEach(function(r){
        var w = r.bredde || r.width || '';
        var wNorm = String(w||'').replace('.',',');
        var key = wNorm ? (wNorm + ' m') : 'Ukjent bredde';
        if(key !== last){
          out += '<tr class="ah-group"><td colspan="2">'+esc(key)+'</td></tr>';
          last = key;
        }
        var left = esc(r.batplasskode);
        var sub = 'Status: ' + esc(r.batplass_status||'—') + ' • ' + esc(r.utleie_status||'');
        out += '<tr data-id="'+esc(r.id)+'"><td><div style="font-weight:750">'+left+'</div><small>'+sub+'</small></td>'
          +'<td style="width:1%;white-space:nowrap">'
          + badge(r.utleie_badge_text, r.utleie_badge_class)
          +'</td></tr>';
      });
      tb.innerHTML = out;
      return;
    }

    tb.innerHTML = rows.map(function(r){
      var left = cfg.view==='batplasser' ? esc(r.batplasskode) : esc(r.fullt_navn);
      var sub = cfg.view==='batplasser'
        ? ('Pir '+esc(r.pir||'—')+' • Plass '+esc(r.plassnr||'—')+' • Kan leies ut: '+esc(r.kan_leies_ut||'Nei'))
        : ('Medlemsnr '+esc(r.medlemsnr||'—')+' • Båtplass '+esc(r.batplasskode||'—') + (r.kjoept_bredde?(' • Kjøpt '+esc(r.kjoept_bredde)+' m') : ''));

      if(cfg.view==='dugnad'){
        var st = (r.sum_timer!=null ? (String(r.sum_timer) + ' t') : '0 t');
        return '<tr data-id="'+esc(r.id)+'"><td><div style="font-weight:750">'+left+'</div><small>'+sub+'</small></td><td style="width:1%;white-space:nowrap">'+badge(st,'ah-badge ah-badge--status')+'</td></tr>';
      }

      return '<tr data-id="'+esc(r.id)+'"><td><div style="font-weight:750">'+left+'</div><small>'+sub+'</small></td><td style="width:1%;white-space:nowrap">'+badge(r.status,r.status_badge_class)+'</td></tr>';
    }).join('');
  }
  function msg(ok, text){
    var el = q('#ah-msg');
    if(!el) return;
    el.className = ok ? 'ah-msg-ok' : 'ah-msg-err';
    el.textContent = text || '';
  }
  function setDirty(v){
    window.__ah_dirty = !!v;
    var b = q('#ah-save');
    if(!b) return;
    // For Utleie: vi tillater lagring/generering selv om perioden er kortere enn minste leietid.
    // Prisen beregnes da som minimumsperiode og dette spesifiseres på faktura.
    b.disabled = !(!!v);
  }
  function bindDirty(form){
    function onChange(e){
      // Kun bruker-initierte endringer skal trigge "ulagrede endringer".
      // Programmatisk oppdatering (kalkyler/prefill) har isTrusted=false.
      if (e && e.isTrusted === false) return;
      setDirty(true);
    }
    form.addEventListener('input', onChange);
    form.addEventListener('change', onChange);
  }
  function collect(){
    var form = q('#ah-form');
    if(!form) return null;
    var data = {id: form.getAttribute('data-id')};
    var uid = form.getAttribute('data-utleie-id');
    if(uid) data.utleie_id = uid;
    qa('input[name],select[name],textarea[name]', form).forEach(function(el){data[el.name]=el.value});
    return data;
  }
  function renderMember(d){
    q('#ah-title').textContent = d.fullt_navn || '—';
    q('#ah-meta').innerHTML = badge(d.batplass_status, d.batplass_status_badge_class)
      + '<span class="ah-hint">Båtplass: <strong>'+esc(d.batplasskode||'—')+'</strong></span>'
      + '<span class="ah-hint">Medlemsnr: <strong>'+esc(d.medlemsnr||'—')+'</strong></span>'
      + '<span class="ah-hint">Kjøpt bredde: <strong>'+esc(d.admin_havn_kjoept_bredde||'—')+'</strong> m</span>';
    q('#ah-panel-body').innerHTML =
      '<form id="ah-form" class="ah-form" data-id="'+esc(d.id)+'">'
      +'<div class="ah-field"><label>Fornavn</label><input name="admin_havn_fornavn" value="'+esc(d.admin_havn_fornavn)+'"></div>'
      +'<div class="ah-field"><label>Etternavn</label><input name="admin_havn_etternavn" value="'+esc(d.admin_havn_etternavn)+'"></div>'
      +'<div class="ah-field"><label>Medlemskategori</label><input name="admin_havn_medlemskategori" value="'+esc(d.admin_havn_medlemskategori)+'"></div>'
      +'<div class="ah-field"><label>Dugnadsplikt</label><select name="admin_havn_dugnadsplikt"><option value="">—</option><option value="Ja">Ja</option><option value="Nei">Nei</option></select></div>'
      +'<div class="ah-field"><label>E-post</label><input name="admin_havn_epost" value="'+esc(d.admin_havn_epost)+'"></div>'
      +'<div class="ah-field"><label>Telefon</label><input name="admin_havn_telefon" value="'+esc(d.admin_havn_telefon)+'"></div>'
      +'<div class="ah-field ah-full"><label>Adresse</label><input name="admin_havn_adresse" value="'+esc(d.admin_havn_adresse)+'"></div>'
      +'<div class="ah-field"><label>Postnr</label><input name="admin_havn_postnr" value="'+esc(d.admin_havn_postnr)+'"></div>'
      +'<div class="ah-field"><label>Poststed</label><input name="admin_havn_poststed" value="'+esc(d.admin_havn_poststed)+'"></div>'
      +'<div class="ah-field"><label>Båtplasskode</label><input name="admin_havn_batplasskode" value="'+esc(d.admin_havn_batplasskode)+'"></div>'
      +'<div class="ah-field"><label>Kjøpt bredde (m)</label><input name="admin_havn_kjoept_bredde" value="'+esc(d.admin_havn_kjoept_bredde)+'" placeholder="f.eks 3,5"></div>'
      +'<div class="ah-field"><label>Medlemsnr (låst)</label><input value="'+esc(d.medlemsnr)+'" readonly></div>'
      +'<div class="ah-field"><label>Ønskes solgt</label><select name="admin_havn_onskes_solgt"><option value="">—</option><option value="Ja">Ja</option><option value="Nei">Nei</option></select></div>'
      +'<div class="ah-field"><label>Til salgs registrert dato</label><input type="date" name="admin_havn_til_salgs_dato" value="'+esc(d.admin_havn_til_salgs_dato)+'"></div>'
      +'<div class="ah-field"><label>Avslutt medlemskap</label><select name="admin_havn_avslutt_medlemskap"><option value="">—</option><option value="Ja">Ja</option><option value="Nei">Nei</option></select></div>'
      +'<div class="ah-field"><label>Medlemskap avsluttet dato</label><input type="date" name="admin_havn_avsluttet_dato" value="'+esc(d.admin_havn_avsluttet_dato)+'"></div>'
      +'<div class="ah-field ah-full"><div id="ah-msg"></div></div>'
      +'</form>';
    var form = q('#ah-form');
    form.querySelector('select[name=admin_havn_dugnadsplikt]').value = d.admin_havn_dugnadsplikt || '';
    form.querySelector('select[name=admin_havn_onskes_solgt]').value = d.admin_havn_onskes_solgt || '';
    form.querySelector('select[name=admin_havn_avslutt_medlemskap]').value = d.admin_havn_avslutt_medlemskap || '';
    bindDirty(form);
    setDirty(false);
  }

  function renderDugnad(d){
    q('#ah-title').textContent = (d.fullt_navn||'Dugnad') + '';
    var tot = (d.sum_timer!=null) ? String(d.sum_timer) : '0';
    q('#ah-meta').innerHTML = '<span class="ah-hint">Medlemsnr: <strong>'+esc(d.medlemsnr||'—')+'</strong></span>'
      + '<span class="ah-hint">År: <strong>'+esc(d.aar||'')+'</strong></span>'
      + '<span class="ah-hint">Registrert: <strong>'+esc(tot)+'</strong> timer</span>';

    // Ikke tillat redigering av medlemskort her – kun dugnadstimer.
    q('#ah-panel-body').innerHTML =
      '<form id="ah-form" class="ah-form" data-id="'+esc(d.id)+'">'
      +'<div class="ah-field"><label>Dato</label><input type="date" name="dugnad_dato" value="'+esc(d.default_dato||'')+'"></div>'
      +'<div class="ah-field"><label>Timer</label><input name="dugnad_timer" value="" placeholder="f.eks. 2"></div>'
      +'<div class="ah-field ah-full"><label>Notat</label><input name="dugnad_notat" value="" placeholder="(valgfritt)"></div>'
      +'<div class="ah-field ah-full"><div class="ah-hint" style="margin-top:6px">Legg inn ny linje og trykk <strong>Lagre</strong> (knappen registrerer timer).</div></div>'
      +'<div class="ah-field ah-full" style="margin-top:8px">'
        +'<table class="ah-lines"><thead><tr><th>Dato</th><th style="text-align:right">Timer</th><th>Notat</th></tr></thead><tbody>'
        + (d.entries||[]).map(function(e){
            return '<tr><td>'+esc(String(e.dato||'').slice(0,10))+'</td><td style="text-align:right">'+esc(e.timer||'0')+'</td><td>'+esc(e.notat||'')+'</td></tr>';
          }).join('')
        +'</tbody></table>'
      +'</div>'
      +'<div class="ah-field ah-full"><div id="ah-msg"></div></div>'
      +'</form>';

    // På dugnad-siden bruker vi samme knapp, men teksten er mer presis
    var b = q('#ah-save');
    if(b) b.textContent = 'Registrer timer';
    bindDirty(q('#ah-form'));
    setDirty(false);
  }
  function renderBatplass(d){
    q('#ah-title').textContent = d.batplasskode || '—';
    q('#ah-meta').innerHTML = badge(d.status, d.status_badge_class)
      + '<span class="ah-hint">Pir: <strong>'+esc(d.pir||'—')+'</strong></span>'
      + '<span class="ah-hint">Plassnr: <strong>'+esc(d.plassnr||'—')+'</strong></span>'
      + '<span class="ah-hint">Kan leies ut: <strong>'+esc(d.admin_havn_kan_leies_ut||'Nei')+'</strong></span>';

    q('#ah-panel-body').innerHTML =
      '<form id="ah-form" class="ah-form" data-id="'+esc(d.id)+'">'
      +'<div class="ah-field"><label>Båtplasskode</label><input name="admin_havn_batplasskode_bp" value="'+esc(d.admin_havn_batplasskode_bp)+'"></div>'
      +'<div class="ah-field"><label>Status</label><select name="admin_havn_status"><option>Opptatt</option><option>Sperret</option><option>Til salgs</option></select></div>'
      +'<div class="ah-field"><label>Kan leies ut</label><select name="admin_havn_kan_leies_ut"><option value="Nei">Nei</option><option value="Ja">Ja</option></select></div>'
      +'<div class="ah-field"><label>Pir</label><input name="admin_havn_pir" value="'+esc(d.admin_havn_pir)+'"></div>'
      +'<div class="ah-field"><label>Plassnr</label><input name="admin_havn_plassnr" value="'+esc(d.admin_havn_plassnr)+'"></div>'
      +'<div class="ah-field"><label>Bredde (m)</label><input name="admin_havn_bredde_m" value="'+esc(d.admin_havn_bredde_m)+'"></div>'
      +'<div class="ah-field"><label>Utrigger-lengde (m)</label><input name="admin_havn_utrigger_m" value="'+esc(d.admin_havn_utrigger_m)+'"></div>'
      +'<div class="ah-field"><label>Antall kWh</label><input name="admin_havn_kwh" value="'+esc(d.admin_havn_kwh)+'"></div>'
      +'<div class="ah-field"><label>Lang utriggar</label><select name="admin_havn_lang_utrigger"><option value="">—</option><option value="Ja">Ja</option><option value="Nei">Nei</option></select></div>'
      +'<div class="ah-field"><label>2x gangriggar</label><select name="admin_havn_2x_gangriggar"><option value="">—</option><option value="Ja">Ja</option><option value="Nei">Nei</option></select></div>'
      +'<div class="ah-field ah-full"><label>Notat</label><textarea name="admin_havn_notat">'+esc(d.admin_havn_notat)+'</textarea></div>'
      +'<div class="ah-field ah-full"><div id="ah-msg"></div></div>'
      +'</form>';
    var form = q('#ah-form');
    form.querySelector('select[name=admin_havn_status]').value = d.admin_havn_status || 'Opptatt';
    form.querySelector('select[name=admin_havn_kan_leies_ut]').value = d.admin_havn_kan_leies_ut || 'Nei';
    form.querySelector('select[name=admin_havn_lang_utrigger]').value = d.admin_havn_lang_utrigger || '';
    form.querySelector('select[name=admin_havn_2x_gangriggar]').value = d.admin_havn_2x_gangriggar || '';
    bindDirty(form);
    setDirty(false);
  }

  function renderUtleie(d){
    q('#ah-title').textContent = 'Utleie • ' + (d.batplasskode || '—');
    var inv = d.agreement_no ? '<span class="ah-hint">Fakturanr: <strong>'+esc(d.agreement_no)+'</strong></span>' : '<span class="ah-hint">Fakturanr: <strong>—</strong></span>';
    q('#ah-meta').innerHTML = badge(d.batplass_status, d.batplass_status_badge_class) + ' ' + badge(d.utleie_badge_text, d.utleie_badge_class)
      + '<span class="ah-hint">Pir: <strong>'+esc(d.pir||'—')+'</strong></span><span class="ah-hint">Plassnr: <strong>'+esc(d.plassnr||'—')+'</strong></span>' + inv;

    // Utleie: vi sender kun fakturagrunnlag til Faktura-modulen. Selve faktureringen skjer der.
    var btns = '<button id="ah-save" disabled>Send til Faktura</button>';
    if(d.utleie_id){ btns += '<button id="ah-archive" class="secondary" type="button" style="margin-left:6px">Avslutt og arkiver</button>'; }
    q('.ah-actions').innerHTML = btns;

    q('#ah-panel-body').innerHTML =
      '<form id="ah-form" class="ah-form" data-id="'+esc(d.id)+'" data-utleie-id="'+esc(d.utleie_id||'')+'">'
      +'<div class="ah-field ah-full">'
        +'<label>Leietaker navn</label>'
        +'<input id="ah-tenant-name" name="admin_havn_leietaker_navn" list="ah-tenant-suggestions" placeholder="Begynn å skrive navn (medlem eller tidligere leietaker)…" value="'+esc(d.admin_havn_leietaker_navn)+'">'
        +'<datalist id="ah-tenant-suggestions"></datalist>'
        +'<input type="hidden" name="admin_havn_leietaker_member_id" id="ah-tenant-member-id" value="'+esc(d.admin_havn_leietaker_member_id||'')+'">'
        +'<div id="ah-tenant-hint" class="ah-hint" style="margin-top:6px">'+(d.admin_havn_leietaker_member_id?('Koblet til medlem #'+esc(d.admin_havn_leietaker_member_id)):'')+'</div>'
        +'<div id="ah-tenant-history" class="ah-hint" style="margin-top:6px"></div>'
      +'</div>'
      +'<div class="ah-field"><label>Telefon</label><input name="admin_havn_leietaker_telefon" value="'+esc(d.admin_havn_leietaker_telefon)+'"></div>'
      +'<div class="ah-field"><label>E-post</label><input name="admin_havn_leietaker_epost" value="'+esc(d.admin_havn_leietaker_epost)+'"></div>'
      +'<div class="ah-field ah-full"><label>Adresse</label><input name="admin_havn_leietaker_adresse" value="'+esc(d.admin_havn_leietaker_adresse)+'"></div>'
      +'<div class="ah-field"><label>Fra dato</label><input type="date" name="admin_havn_utleie_fra" value="'+esc(d.admin_havn_utleie_fra)+'"></div>'
      +'<div class="ah-field"><label>Til dato</label><input type="date" name="admin_havn_utleie_til" value="'+esc(d.admin_havn_utleie_til)+'"></div>'
      +'<div class="ah-field"><label>Beløp</label><input name="admin_havn_utleie_belop" readonly value="'+esc(d.admin_havn_utleie_belop)+'"></div>'
      +'<div class="ah-field ah-full"><div id="ah-calc" class="ah-calc">Velg fra/til dato for å beregne pris.</div></div>'
      +'<div class="ah-field"><label>Faktura sendt dato</label><input type="date" name="admin_havn_utleie_faktura_sendt" value="'+esc(d.admin_havn_utleie_faktura_sendt)+'" readonly></div>'
      +'<div class="ah-field ah-full"><label>Notat</label><textarea name="admin_havn_utleie_notat">'+esc(d.admin_havn_utleie_notat)+'</textarea></div>'
      +'<div class="ah-field ah-full"><div id="ah-msg"></div></div>'
      +'</form>';

    var form = q('#ah-form');

    // Prefill fra/til dersom vi kommer fra tidslinjen (mode=list&select=...&from=...&to=...)
    try{
      var qs = parseQuery();
      if(qs && (qs.from || qs.to)){
        var fromEl = form.querySelector('input[name="admin_havn_utleie_fra"]');
        var toEl   = form.querySelector('input[name="admin_havn_utleie_til"]');
        if(fromEl && qs.from && !fromEl.value){ fromEl.value = qs.from; }
        if(toEl && qs.to && !toEl.value){ toEl.value = qs.to; }
        // Trigger kalkyle dersom vi fylte inn datoer programmatisk
        if(fromEl && toEl && (qs.from || qs.to)){
          fromEl.dispatchEvent(new Event('change', {bubbles:true}));
          toEl.dispatchEvent(new Event('change', {bubbles:true}));
        }
      }
    }catch(e){}

    // Lås redigering etter fakturering (hindrer at historikk endres i etterkant).
    var isInvoiced = (String(d.admin_havn_utleie_fakturert||'').toLowerCase()==='ja');
    if(isInvoiced){
      // Deaktiver alle felter
      qa('input,select,textarea', form).forEach(function(el){
        // La notat kunne redigeres? Foreløpig låser vi alt for å unngå avvik.
        el.setAttribute('disabled','disabled');
      });
      var b = q('#ah-save');
      if(b){ b.disabled = true; b.textContent = 'Fakturert'; }
      var m = q('#ah-msg');
      if(m){ m.innerHTML = '<div class="ah-alert ah-alert-info">Denne utleien er fakturert. Endringer er låst. Bruk "Avslutt og arkiver" når perioden er ferdig.</div>'; }
    }
    bindDirty(form);
    setDirty(false);

    // --- Leietaker-autofyll: forslag i navnefeltet (medlemmer + tidligere utleie) ---
    (function(){
      var inp = q('#ah-tenant-name');
      var dl  = q('#ah-tenant-suggestions');
      var hid = q('#ah-tenant-member-id');
      var hint= q('#ah-tenant-hint');
      var hist= q('#ah-tenant-history');
      if(!inp || !dl || !hid) return;

      var cache = {};
      var lastQ = '';
      var t = null;

      function setHistory(items){
        if(!hist) return;
        if(!items || !items.length){ hist.textContent=''; return; }
        hist.innerHTML = '<strong>Tidligere utleie:</strong> ' + items.map(function(x){
          var p = (x.fra||'') + '–' + (x.til||'');
          var b = x.batplasskode ? (' ('+esc(x.batplasskode)+')') : '';
          return '<span style="display:inline-block;margin-right:10px">'+esc(p)+b+'</span>';
        }).join('');
      }

      function applySuggestion(s){
        if(!s) return;
        var setVal = function(sel, val){
          var el = form.querySelector(sel);
          if(!el) return;
          el.value = val || '';
        };
        hid.value = String(s.member_id||'');
        if(hint) hint.textContent = s.member_id ? ('Koblet til medlem #' + s.member_id) : 'Ekstern leietaker';
        setVal('input[name=admin_havn_leietaker_navn]', s.fullt_navn);
        setVal('input[name=admin_havn_leietaker_telefon]', s.telefon);
        setVal('input[name=admin_havn_leietaker_epost]', s.epost);
        setVal('input[name=admin_havn_leietaker_adresse]', s.adresse);
        setHistory([]);
        if(s.member_id){
          api('admin_havn_portal_member_lookup', {member_id:s.member_id}).then(function(r){
            if(r && r.success && r.data && r.data.member){ setHistory(r.data.member.history||[]); }
          }).catch(function(){});
        }
      }

      function renderSuggestions(arr){
        cache = {};
        dl.innerHTML = (arr||[]).map(function(s){
          var label = s.label;
          cache[label] = s;
          return '<option value="'+esc(label)+'"></option>';
        }).join('');
      }

      function doSearch(qs){
        api('admin_havn_portal_leietaker_suggest', {q:qs}).then(function(r){
          if(!r || !r.success || !r.data) return;
          renderSuggestions(r.data.results||[]);
        }).catch(function(){});
      }

      inp.addEventListener('input', function(){
        var v = (inp.value||'').trim();
        // Ny skriving => fjern kobling
        hid.value='';
        if(hint) hint.textContent='';
        if(v.length < 2) { dl.innerHTML=''; return; }
        if(v === lastQ) return;
        lastQ = v;
        if(t) clearTimeout(t);
        t = setTimeout(function(){ doSearch(v); }, 180);
      });

      inp.addEventListener('change', function(){
        var v = (inp.value||'').trim();
        var s = cache[v];
        if(s){ applySuggestion(s); return; }
        if(!v){
          hid.value='';
          if(hint) hint.textContent='';
          setHistory([]);
        }
      });

      // Ved eksisterende kobling: vis historikk
      if(hid.value){
        api('admin_havn_portal_member_lookup', {member_id:hid.value}).then(function(r){
          if(r && r.success && r.data && r.data.member){
            if(hint) hint.textContent = 'Koblet til medlem #' + r.data.member.id;
            setHistory(r.data.member.history||[]);
          }
        }).catch(function(){});
      }
    })();
    // If we arrived from timeline selection, prefill from/to
    try{
      if(cfg && cfg.prefill && String(cfg.prefill.select||'') === String(d.id||'') && cfg.prefill.from && cfg.prefill.to){
        var inFrom = form.querySelector('input[name=admin_havn_utleie_fra]');
        var inTo   = form.querySelector('input[name=admin_havn_utleie_til]');
        if(inFrom && inTo){
          inFrom.value = cfg.prefill.from;
          inTo.value = cfg.prefill.to;
        }
      }
    }catch(e){}
    setupRentalCalc(document);

    // Trigger calc once after initial render/prefill
    try{
      var inFrom2 = form.querySelector('input[name=admin_havn_utleie_fra]');
      if(inFrom2) inFrom2.dispatchEvent(new Event('change', {bubbles:true}));
    }catch(e){}

    // Fakturering skjer i Faktura-modulen.

    var ab = q('#ah-archive');
    if(ab){
      ab.addEventListener('click', function(){
        if(!confirm('Avslutt leie og flytt til arkiv?')) return;
        api('admin_havn_portal_archive_utleie',{utleie_id: form.getAttribute('data-utleie-id')}).then(function(res){
          if(!res || !res.success){msg(false,(res&&res.data&&res.data.message)||'Kunne ikke arkivere');return;}
          msg(true,'Arkivert.');
          setDirty(false);
          api('admin_havn_portal_list',{view:cfg.view,q:q('#ah-q').value||''}).then(function(r2){
            if(r2 && r2.success){renderList(r2.data.rows||[]);} 
          });
          loadDetail(form.getAttribute('data-id'));
        });
      });
    }
  }

  function renderAgreement(d){
    q('#ah-title').textContent = 'Faktura/Avtale #' + esc(d.agreement_no||d.id||'—');
    q('#ah-meta').innerHTML = '<span class="ah-hint">Kunde: <strong>'+esc(d.customer_label||'')+'</strong></span>'
      + '<span class="ah-hint">Type: <strong>'+esc(d.type||'')+'</strong></span>'
      + '<span class="ah-hint">Sum: <strong>NOK '+esc(d.total||'0')+'</strong></span>'
      + '<span class="ah-hint">Opprettet: <strong>'+esc(String(d.created_at||'').slice(0,10))+'</strong></span>';

    q('.ah-actions').innerHTML = '<button id="ah-save" disabled>Oppdater status</button>';

    var lines = (d.lines||[]).map(function(l){
      return '<tr><td>'+esc(l.description||'')+'</td><td style="text-align:right">'+esc(l.qty||'1')+'</td><td style="text-align:right">'+esc(l.unit_price||'0')+'</td><td style="text-align:right">'+esc(l.amount||'0')+'</td></tr>';
    }).join('');

    q('#ah-panel-body').innerHTML =
      '<form id="ah-form" class="ah-form" data-id="'+esc(d.agreement_no||d.id)+'">'
      +'<div class="ah-field"><label>Status</label>'
        +'<select name="status">'
          +'<option value="draft">Utkast</option>'
          +'<option value="generated">Generert</option>'
          +'<option value="sent">Sendt</option>'
          +'<option value="paid">Betalt</option>'
          +'<option value="void">Annullert</option>'
          +'<option value="archived">Arkiv</option>'
        +'</select>'
      +'</div>'
      +'<div class="ah-field ah-full">'
        +'<table class="ah-lines"><thead><tr><th>Linje</th><th style="text-align:right">Ant</th><th style="text-align:right">Pris</th><th style="text-align:right">Sum</th></tr></thead><tbody>'
        + (lines||'') +
        '</tbody></table>'
      +'</div>'
      +'<div class="ah-field ah-full"><div id="ah-msg"></div></div>'
      +'</form>';

    var form = q('#ah-form');
    var curStatus = d.status || 'generated';
    var sel = form.querySelector('select[name=status]');
    sel.value = curStatus;

    // Ikke tillat å velge status bakover i UI (backend håndhever også dette)
    var order = {draft:0, generated:1, sent:2, paid:3, void:98, archived:99};
    var curO = (order[curStatus]!==undefined)?order[curStatus]:1;
    qa('option', sel).forEach(function(o){
      var v=o.value;
      var oO = (order[v]!==undefined)?order[v]:1;
      if(v!=='void' && v!=='archived' && oO < curO) o.disabled = true;
      if((curStatus==='paid' || curStatus==='void') && v!==curStatus && v!=='archived') o.disabled = true;
    });
    bindDirty(form);
    setDirty(false);
    setupRentalCalc(document);
  }

  function loadDetail(id){
    if(window.__ah_dirty){if(!confirm('Du har ulagrede endringer. Vil du bytte uten å lagre?')) return;}
    setActive(id);
    msg(true,'');
    var a = cfg.view==='batplasser' ? 'admin_havn_portal_get_batplass'
          : (cfg.view==='utleie' ? 'admin_havn_portal_get_utleie'
          : (cfg.view==='faktura' ? 'admin_havn_portal_get_agreement'
          : (cfg.view==='dugnad' ? 'admin_havn_portal_get_dugnad'
          : 'admin_havn_portal_get_medlem')));
    api(a,{id:id}).then(function(res){
      if(!res || !res.success){msg(false,(res&&res.data&&res.data.message)||'Kunne ikke laste');return;}
      if(cfg.view==='batplasser') renderBatplass(res.data);
      else if(cfg.view==='utleie') renderUtleie(res.data);
      else if(cfg.view==='faktura') renderAgreement(res.data);
      else if(cfg.view==='dugnad') renderDugnad(res.data);
      else renderMember(res.data);
    });
  }
  function init(){
    setShellHeight();
    window.addEventListener('resize', setShellHeight);
    // Responsive: re-render timeline on resize (uten ny API-kall) slik at 6 mnd skalerer riktig.
    var __rzT = null;
    window.addEventListener('resize', function(){
      if(!__lastTimeline) return;
      if((cfg.view||'')!=='utleie' || (cfg.mode||'')!=='timeline') return;
      clearTimeout(__rzT);
      __rzT = setTimeout(function(){
        try{
          var sc = __lastTimeline.scale||'month';
          var st = new Date((__lastTimeline.start||'')+'T00:00:00');
          if(isNaN(st.getTime())) st = new Date();
          var startDate, endDate;
          if(sc==='week'){
            var wks = computeSpan('week').weeks;
            startDate = new Date(st.getFullYear(), st.getMonth(), st.getDate());
            endDate = addDays(startDate, wks*7);
          } else {
            var mos = computeSpan('month').months;
            startDate = startOfMonth(st);
            endDate = addMonths(startDate, mos);
          }
          var start = ymd(startDate);
          var end = ymd(endDate);

          // Hvis perioden endrer seg pga skjermbredde, hent nytt datasett.
          if(end !== __lastTimeline.end || start !== __lastTimeline.start){
            loadTimeline(start, end, sc).then(function(r){
              if(r && r.success){ renderTimeline(r.data, r.data.start, r.data.end, sc); }
            });
          } else {
            renderTimeline(__lastTimeline.data, __lastTimeline.start, __lastTimeline.end, sc);
          }
        }catch(e){}
      }, 120);
    });
    var root = q('#ah-portal-root');
    if(!root) return;

    if((cfg.view||'')==='utleie' && (cfg.mode||'')==='timeline'){
      // widen right panel in timeline mode
      var shell = q('.ah-shell', root);
      if(shell) shell.classList.add('is-timeline');
      qa('.ah-tab').forEach(function(a){a.classList.toggle('is-active', (a.getAttribute('href')||'').indexOf('mode=timeline')>-1);});
      api('admin_havn_portal_list',{view:cfg.view,q:''}).then(function(res){
        if(res && res.success){renderList(res.data.rows||[]);}
      });
      var qs = parseQuery();
      var scale = String(qs.scale||'month');
      if(scale!=='week' && scale!=='month') scale='month';

      var sd;
      if(qs.start){
        sd = new Date(qs.start+'T00:00:00');
      }
      if(!sd || isNaN(sd.getTime())){
        sd = new Date();
      }

      var startDate, endDate;
      if(scale==='week'){
        // Uke-modus: vis dynamisk antall uker basert på skjermbredde.
        var spanW = computeSpan('week').weeks;
        startDate = new Date(sd.getFullYear(), sd.getMonth(), sd.getDate());
        endDate = addDays(startDate, spanW * 7);
      } else {
        // Måned-modus: vis dynamisk antall måneder basert på skjermbredde.
        var spanM = computeSpan('month').months;
        startDate = startOfMonth(sd);
        endDate = addMonths(startDate, spanM);
      }

      var start = ymd(startDate);
      var end = ymd(endDate);
      loadTimeline(start, end, scale).then(function(r){
        if(r && r.success){
          renderTimeline(r.data, r.data.start, r.data.end, scale);
          // prefetch adjacent periods for snappier arrows
          try{
            setTimeout(function(){
              var prevStart, nextStart;
              if(scale==='week'){
                prevStart = addDays(startDate, -7);
                nextStart = addDays(startDate, 7);
                loadTimeline(ymd(prevStart), ymd(addDays(prevStart, spanW*7)), scale).catch(function(){});
                loadTimeline(ymd(nextStart), ymd(addDays(nextStart, spanW*7)), scale).catch(function(){});
              } else {
                prevStart = addMonths(startDate, -1);
                nextStart = addMonths(startDate, 1);
                loadTimeline(ymd(prevStart), ymd(addMonths(prevStart, spanM)), scale).catch(function(){});
                loadTimeline(ymd(nextStart), ymd(addMonths(nextStart, spanM)), scale).catch(function(){});
              }
            }, 220);
          }catch(e){}
        }
        else { q('#ah-panel-body').textContent = (r&&r.data&&r.data.message)||'Kunne ikke laste tidslinje.'; }
      });
      root.addEventListener('click',function(e){
        var tr = e.target.closest('tr[data-id]');
        if(!tr) return;
        e.preventDefault();
        var id = tr.getAttribute('data-id');
        setActive(id);
        var row = q('#ah-tl-row-'+id);
        if(row) row.scrollIntoView({block:'center'});
      });
      return;
    }
    root.addEventListener('click',function(e){
      var tr = e.target.closest('tr[data-id]');
      if(!tr) return;
      e.preventDefault();
      loadDetail(tr.getAttribute('data-id'));
    });
    // IMPORTANT: '#ah-save' blir byttet ut (spesielt i Utleie), så vi må bruke delegert event.
    root.addEventListener('click',function(e){
      var btn = e.target.closest('#ah-save');
      if(!btn) return;
      e.preventDefault();
      if(btn.disabled) return;
      var data = collect();
      if(!data) return;
      btn.disabled = true;

      var a = cfg.view==='batplasser' ? 'admin_havn_portal_update_batplass'
            : (cfg.view==='utleie' ? 'admin_havn_portal_submit_utleie_to_faktura'
            : (cfg.view==='faktura' ? 'admin_havn_portal_update_agreement_status'
            : (cfg.view==='dugnad' ? 'admin_havn_portal_add_dugnad' : 'admin_havn_portal_update_medlem')));

      // Map payload for faktura-status update
      var payload = data;
      if(cfg.view==='faktura') payload = {agreement_no: data.id, status: data.status};

      api(a,payload).then(function(res){
        if(!res || !res.success){
          msg(false,(res&&res.data&&res.data.message)||'Kunne ikke lagre');
          btn.disabled=false;
          return;
        }
        msg(true, cfg.view==='faktura' ? 'Status oppdatert.' : (cfg.view==='utleie' ? 'Sendt til Faktura (klar til fakturering).' : (cfg.view==='dugnad' ? 'Timer registrert.' : 'Lagret.')));
        setDirty(false);
        api('admin_havn_portal_list',{view:cfg.view,q:q('#ah-q').value||''}).then(function(r2){
          if(r2 && r2.success){renderList(r2.data.rows||[]);setActive(data.id);} 
        });
        loadDetail(data.id);
      });
    });
    q('#ah-q').addEventListener('input',function(){
      api('admin_havn_portal_list',{view:cfg.view,q:this.value||''}).then(function(res){
        if(res && res.success){renderList(res.data.rows||[]);var first=q('#ah-list-body tr[data-id]');if(first) loadDetail(first.getAttribute('data-id'));}
      });
    });
    function pickInitial(){
      var qs = parseQuery();
      var wanted = qs && qs.select ? String(qs.select) : '';
      if(wanted){
        var tr = q('#ah-list-body tr[data-id="'+wanted+'"]');
        if(tr){ loadDetail(wanted); return true; }
      }
      var first=q('#ah-list-body tr[data-id]');
      if(first){ loadDetail(first.getAttribute('data-id')); return true; }
      return false;
    }

    api('admin_havn_portal_list',{view:cfg.view,q:''}).then(function(res){
      if(res && res.success){
        renderList(res.data.rows||[]);
        pickInitial();
      }
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
