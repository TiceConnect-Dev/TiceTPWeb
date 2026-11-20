/* TPWeb — annotation des copies (pdf.js pour l'affichage, pdf-lib pour le PDF corrigé). */
(function () {
  'use strict';
  var root = document.querySelector('[data-annot]');
  if (!root) return;
  var $ = function (s) { return root.querySelector(s); };
  var status = $('[data-status]');
  var pagesEl = $('[data-pages]');
  var kind = root.getAttribute('data-kind');
  var tool = 'pen', color = '#e1000f', size = 2;
  var pages = [];      // { wrap, base, over, w, h (CSS px), items: [] }
  var history = [];    // [pageIndex, item] pour « Annuler »
  var srcBytes = null, srcImage = null;
  var dirty = false;
  var prev = {};
  try { prev = JSON.parse($('[data-annot-prev]').textContent) || {}; } catch (e) { prev = {}; }

  function say(t, err) { status.textContent = t; status.classList.toggle('is-error', !!err); }

  /* ---------- Outils ---------- */
  root.querySelectorAll('[data-tool]').forEach(function (b) {
    b.addEventListener('click', function () { setTool(b.getAttribute('data-tool')); });
  });
  function setTool(t) {
    tool = t;
    root.querySelectorAll('[data-tool]').forEach(function (x) {
      var on = x.getAttribute('data-tool') === t;
      x.classList.toggle('is-active', on);
      x.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    pagesEl.setAttribute('data-cursor', t);
  }
  root.querySelectorAll('[data-color]').forEach(function (b) {
    b.addEventListener('click', function () {
      color = b.getAttribute('data-color');
      root.querySelectorAll('[data-color]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    });
  });
  $('[data-size]').addEventListener('change', function () { size = parseInt(this.value, 10) || 2; });
  $('[data-undo]').addEventListener('click', undo);
  document.addEventListener('keydown', function (e) {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); undo(); }
    var k = { p: 'pen', s: 'hl', t: 'text', e: 'erase' }[e.key.toLowerCase()];
    if (k && !e.ctrlKey && !e.metaKey) setTool(k);
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  function undo() {
    var last = history.pop();
    if (!last) return;
    var p = pages[last[0]];
    var i = p.items.indexOf(last[1]);
    if (i >= 0) p.items.splice(i, 1); else if (last[2] !== undefined) p.items.splice(last[2], 0, last[1]); // gomme annulée
    redraw(p);
    dirty = true;
  }

  /* ---------- Dessin (coordonnées normalisées 0..1, largeur de référence = largeur de page) ---------- */
  function drawItems(ctx, items, W, H) {
    items.forEach(function (it) {
      ctx.save();
      if (it.t === 'pen' || it.t === 'hl') {
        ctx.lineCap = it.t === 'hl' ? 'butt' : 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = it.t === 'hl' ? 'rgba(255, 214, 0, .38)' : it.c;
        ctx.lineWidth = it.w * W;
        ctx.beginPath();
        it.pts.forEach(function (pt, k) { if (k) ctx.lineTo(pt[0] * W, pt[1] * H); else ctx.moveTo(pt[0] * W, pt[1] * H); });
        if (it.pts.length === 1) ctx.lineTo(it.pts[0][0] * W + .1, it.pts[0][1] * H);
        if (it.t === 'hl') ctx.globalCompositeOperation = 'multiply';
        ctx.stroke();
      } else if (it.t === 'text' || it.t === 'check' || it.t === 'cross') {
        var fs = it.s * W;
        ctx.fillStyle = it.t === 'check' ? '#18753c' : (it.t === 'cross' ? '#e1000f' : it.c);
        ctx.font = (it.t === 'text' ? '600 ' : 'bold ') + fs + 'px Marianne, Arial, Helvetica, sans-serif';
        ctx.textBaseline = 'top';
        var txt = it.t === 'check' ? '✓' : (it.t === 'cross' ? '✗' : it.text);
        String(txt).split('\n').forEach(function (line, k) { ctx.fillText(line, it.x * W, it.y * H + k * fs * 1.2); });
      }
      ctx.restore();
    });
  }
  function redraw(p) {
    var ctx = p.over.getContext('2d');
    var r = p.over.width / p.w;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, p.over.width, p.over.height);
    ctx.setTransform(r, 0, 0, r, 0, 0);
    drawItems(ctx, p.items, p.w, p.h);
    if (p.live) drawItems(ctx, [p.live], p.w, p.h);
  }
  function itemBox(it, p) {
    if (it.pts) {
      var xs = it.pts.map(function (q) { return q[0]; }), ys = it.pts.map(function (q) { return q[1]; });
      var m = it.w / 2 + 0.01;
      return [Math.min.apply(null, xs) - m, Math.min.apply(null, ys) - m * p.w / p.h, Math.max.apply(null, xs) + m, Math.max.apply(null, ys) + m * p.w / p.h];
    }
    var lines = String(it.t === 'text' ? it.text : 'X').split('\n');
    var longest = Math.max.apply(null, lines.map(function (l) { return l.length; }));
    return [it.x, it.y, it.x + it.s * .6 * Math.max(1, longest), it.y + it.s * 1.2 * lines.length * p.w / p.h];
  }
  function distToItem(it, x, y, p) {
    if (it.pts) {
      var best = 1e9;
      it.pts.forEach(function (q) { best = Math.min(best, Math.hypot(q[0] - x, (q[1] - y) * p.h / p.w)); });
      return best - it.w / 2;
    }
    var b = itemBox(it, p);
    return (x >= b[0] && x <= b[2] && y >= b[1] && y <= b[3]) ? 0 : 1;
  }

  function attach(p, idx) {
    var drawing = false;
    function pos(e) {
      var r = p.over.getBoundingClientRect();
      return [Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), Math.max(0, Math.min(1, (e.clientY - r.top) / r.height))];
    }
    p.over.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      var q = pos(e);
      if (tool === 'pen' || tool === 'hl') {
        drawing = true;
        p.over.setPointerCapture(e.pointerId);
        p.live = { t: tool, c: color, w: (tool === 'hl' ? [0.012, 0.02, 0.03][size - 1] : [0.0018, 0.0032, 0.0055][size - 1]), pts: [q] };
        redraw(p);
        e.preventDefault();
      } else if (tool === 'check' || tool === 'cross') {
        var s = [0.025, 0.04, 0.06][size - 1];
        add(p, idx, { t: tool, s: s, x: q[0] - s * .3, y: q[1] - s * .55 * p.w / p.h });
      } else if (tool === 'text') {
        e.preventDefault();
        openText(p, idx, q);
      } else if (tool === 'erase') {
        var hit = -1, best = 0.012;
        p.items.forEach(function (it, k) { var d = distToItem(it, q[0], q[1], p); if (d <= best) { best = d; hit = k; } });
        if (hit >= 0) {
          var removed = p.items.splice(hit, 1)[0];
          history.push([idx, removed, hit]);
          dirty = true;
          redraw(p);
        }
      }
    });
    p.over.addEventListener('pointermove', function (e) {
      if (!drawing || !p.live) return;
      var q = pos(e), last = p.live.pts[p.live.pts.length - 1];
      if (Math.hypot(q[0] - last[0], q[1] - last[1]) < 0.0015) return;
      if (p.live.t === 'hl') q[1] = p.live.pts[0][1]; // surligneur : trait horizontal
      p.live.pts.push(q);
      redraw(p);
    });
    var end = function () {
      if (!drawing) return;
      drawing = false;
      var it = p.live;
      p.live = null;
      if (it) add(p, idx, it); else redraw(p);
    };
    p.over.addEventListener('pointerup', end);
    p.over.addEventListener('pointercancel', end);
  }
  function add(p, idx, it) {
    it.pts && (it.pts = it.pts.map(function (q) { return [Math.round(q[0] * 1e4) / 1e4, Math.round(q[1] * 1e4) / 1e4]; }));
    p.items.push(it);
    history.push([idx, it]);
    dirty = true;
    redraw(p);
  }
  function openText(p, idx, q) {
    var s = [0.018, 0.024, 0.034][size - 1];
    var ta = document.createElement('textarea');
    ta.className = 'annot__input';
    ta.rows = 1;
    ta.style.left = (q[0] * 100) + '%';
    ta.style.top = (q[1] * 100) + '%';
    ta.style.color = color;
    ta.style.fontSize = (s * p.w) + 'px';
    ta.setAttribute('aria-label', 'Texte de l’annotation (Entrée pour valider, Maj+Entrée pour aller à la ligne)');
    p.wrap.appendChild(ta);
    ta.focus();
    setTimeout(function () { if (document.activeElement !== ta) ta.focus(); }, 0);
    var done = false, c = color;
    function commit(keep) {
      if (done) return;
      done = true;
      var v = ta.value.replace(/\s+$/, '');
      ta.remove();
      if (keep && v !== '') add(p, idx, { t: 'text', c: c, s: s, x: q[0], y: q[1], text: v });
    }
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(true); }
      if (e.key === 'Escape') { e.preventDefault(); commit(false); }
    });
    ta.addEventListener('input', function () { ta.rows = Math.max(1, ta.value.split('\n').length); });
    ta.addEventListener('blur', function () { commit(true); });
  }

  /* ---------- Pages ---------- */
  function makePage(w, h, idx) {
    var wrap = document.createElement('div');
    wrap.className = 'annot__page';
    wrap.style.aspectRatio = w + ' / ' + h;
    var base = document.createElement('canvas'), over = document.createElement('canvas');
    base.className = 'annot__base';
    over.className = 'annot__over';
    wrap.appendChild(base);
    wrap.appendChild(over);
    var label = document.createElement('span');
    label.className = 'annot__num';
    label.textContent = 'Page ' + (idx + 1);
    wrap.appendChild(label);
    pagesEl.appendChild(wrap);
    var p = { wrap: wrap, base: base, over: over, w: 0, h: 0, ratio: h / w, items: (prev.pages && prev.pages[idx]) || [] };
    pages.push(p);
    return p;
  }
  function fit(p) {
    var cssW = p.wrap.clientWidth, cssH = cssW * p.ratio, dpr = Math.min(2, window.devicePixelRatio || 1);
    p.w = cssW; p.h = cssH;
    p.over.width = Math.round(cssW * dpr);
    p.over.height = Math.round(cssH * dpr);
    return dpr;
  }

  var pdfDoc = null;
  function renderPdfPage(p, i) {
    return pdfDoc.getPage(i + 1).then(function (page) {
      var dpr = fit(p);
      var vp1 = page.getViewport({ scale: 1 });
      var vp = page.getViewport({ scale: p.w * dpr / vp1.width });
      p.base.width = Math.round(vp.width);
      p.base.height = Math.round(vp.height);
      redraw(p);
      return page.render({ canvasContext: p.base.getContext('2d'), viewport: vp }).promise;
    });
  }
  function renderImage(p) {
    var dpr = fit(p);
    p.base.width = Math.round(p.w * dpr);
    p.base.height = Math.round(p.h * dpr);
    p.base.getContext('2d').drawImage(srcImage, 0, 0, p.base.width, p.base.height);
    redraw(p);
  }

  fetch(root.getAttribute('data-src'), { credentials: 'same-origin' }).then(function (r) {
    if (!r.ok) return r.text().then(function () { throw new Error(r.status === 415 ? 'Ce document ne peut pas être converti en PDF.' : 'Copie introuvable (' + r.status + ').'); });
    return r.arrayBuffer();
  }).then(function (buf) {
    srcBytes = buf;
    if (kind === 'image') {
      return new Promise(function (ok, ko) {
        var img = new Image();
        img.onload = function () { srcImage = img; ok(); };
        img.onerror = function () { ko(new Error('Image illisible.')); };
        img.src = URL.createObjectURL(new Blob([buf]));
      }).then(function () {
        var p = makePage(srcImage.naturalWidth, srcImage.naturalHeight, 0);
        attach(p, 0);
        renderImage(p);
      });
    }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js';
    return pdfjsLib.getDocument({ data: new Uint8Array(buf.slice(0)), isEvalSupported: false }).promise.then(function (doc) {
      pdfDoc = doc;
      var chain = Promise.resolve();
      for (var i = 0; i < doc.numPages; i++) (function (i) {
        chain = chain.then(function () { return doc.getPage(i + 1); }).then(function (page) {
          var vp = page.getViewport({ scale: 1 });
          var p = makePage(vp.width, vp.height, i);
          attach(p, i);
          return renderPdfPage(p, i);
        });
      })(i);
      return chain;
    });
  }).then(function () {
    say(pages.length + ' page(s) · stylo, surligneur, texte, ✓ / ✗ — puis « Enregistrer la copie annotée ».');
    root.classList.add('is-ready');
    var t;
    window.addEventListener('resize', function () {
      clearTimeout(t);
      t = setTimeout(function () { pages.forEach(function (p, i) { if (kind === 'image') renderImage(p); else renderPdfPage(p, i); }); }, 200);
    });
  }).catch(function (e) { say('Impossible d’ouvrir la copie : ' + e.message, true); });

  /* ---------- Export PDF ---------- */
  function overlayPng(p, pxW) {
    var c = document.createElement('canvas');
    c.width = Math.round(pxW);
    c.height = Math.round(pxW * p.ratio);
    var ctx = c.getContext('2d');
    drawItems(ctx, p.items, c.width, c.height);
    return new Promise(function (ok) { c.toBlob(function (b) { b.arrayBuffer().then(ok); }, 'image/png'); });
  }
  function imageAsPng() {
    var c = document.createElement('canvas');
    c.width = srcImage.naturalWidth; c.height = srcImage.naturalHeight;
    c.getContext('2d').drawImage(srcImage, 0, 0);
    return new Promise(function (ok) { c.toBlob(function (b) { b.arrayBuffer().then(ok); }, 'image/png'); });
  }

  function buildPdf() {
    var L = window.PDFLib;
    if (kind === 'image') {
      return L.PDFDocument.create().then(function (doc) {
        var ext = root.getAttribute('data-ext');
        var emb = (ext === 'png') ? doc.embedPng(srcBytes) : ((ext === 'jpg' || ext === 'jpeg') ? doc.embedJpg(srcBytes) : imageAsPng().then(function (b) { return doc.embedPng(b); }));
        return Promise.resolve(emb).then(function (img) {
          var p = pages[0], k = 842 / Math.max(img.width, img.height);
          var W = img.width * k, H = img.height * k;
          var page = doc.addPage([W, H]);
          page.drawImage(img, { x: 0, y: 0, width: W, height: H });
          if (!p.items.length) return doc;
          return overlayPng(p, Math.min(4000, W * 3)).then(function (png) { return doc.embedPng(png); }).then(function (ov) {
            page.drawImage(ov, { x: 0, y: 0, width: W, height: H });
            return doc;
          });
        });
      });
    }
    return L.PDFDocument.load(srcBytes, { ignoreEncryption: true }).then(function (doc) {
      var list = doc.getPages();
      var chain = Promise.resolve();
      list.forEach(function (page, i) {
        var p = pages[i];
        if (!p || !p.items.length) return;
        chain = chain.then(function () {
          var box = page.getCropBox(), rot = ((page.getRotation().angle % 360) + 360) % 360;
          var W = box.width, H = box.height, dispW = (rot === 90 || rot === 270) ? H : W;
          return overlayPng(p, Math.min(4000, dispW * 3)).then(function (png) { return doc.embedPng(png); }).then(function (ov) {
            var o = { 0: { x: 0, y: 0, w: W, h: H }, 90: { x: W, y: 0, w: H, h: W }, 180: { x: W, y: H, w: W, h: H }, 270: { x: 0, y: H, w: H, h: W } }[rot] || { x: 0, y: 0, w: W, h: H };
            page.drawImage(ov, { x: box.x + o.x, y: box.y + o.y, width: o.w, height: o.h, rotate: L.degrees(rot) });
          });
        });
      });
      return chain.then(function () { return doc; });
    });
  }

  var saveBtn = $('[data-save]');
  saveBtn.addEventListener('click', function () {
    if (!pages.length) return;
    saveBtn.disabled = true;
    say('Fabrication du PDF annoté…');
    buildPdf().then(function (doc) { return doc.save(); }).then(function (bytes) {
      say('Envoi…');
      var fd = new FormData();
      fd.append('csrf', root.getAttribute('data-csrf'));
      fd.append('action', 'correction_save');
      fd.append('folder', root.getAttribute('data-folder'));
      fd.append('file', root.getAttribute('data-file'));
      fd.append('annots', JSON.stringify({ v: 1, pages: pages.map(function (p) { return p.items; }) }));
      fd.append('pdf', new Blob([bytes], { type: 'application/pdf' }), 'copie.pdf');
      return fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (r) { return r.json(); }).then(function (res) {
      saveBtn.disabled = false;
      if (!res.ok) { say(res.error || 'Échec de l’enregistrement.', true); return; }
      dirty = false;
      say(res.public ? 'Copie annotée enregistrée : l’élève la retrouve dans « Mes travaux ».'
                     : 'Copie annotée enregistrée. Les élèves la verront quand vous cocherez « Les élèves voient leur note et leur commentaire » dans les réglages du dépôt.');
      root.classList.add('is-saved');
    }).catch(function (e) { saveBtn.disabled = false; say('Échec : ' + e.message, true); });
  });
})();
