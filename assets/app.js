/* TPWeb — comportements de l'interface (aucune dépendance). */
(function () {
  'use strict';

  function each(selector, fn, root) {
    Array.prototype.forEach.call((root || document).querySelectorAll(selector), fn);
  }
  function fold(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[_.\-]/g, ' ').trim();
  }

  // Publication : classes et programmation visibles uniquement en mode « Publier ».
  each('[data-publish-form]', function (form) {
    var details = form.querySelector('[data-levels]');
    if (!details) return;
    function sync() {
      var checked = form.querySelector('input[name="mode"]:checked');
      details.hidden = !checked || checked.value !== 'publish';
    }
    each('input[name="mode"]', function (r) { r.addEventListener('change', sync); }, form);
    sync();
  });

  // Dépôt : réglages visibles uniquement si activé.
  each('[data-deposit-form]', function (form) {
    var toggle = form.querySelector('[data-deposit-toggle]');
    var fields = form.querySelector('[data-deposit-fields]');
    if (!toggle || !fields) return;
    function sync() { fields.hidden = !toggle.checked; }
    toggle.addEventListener('change', sync);
    sync();
  });

  // Groupes par niveau : la case « tout le niveau » coche/décoche ses classes.
  function syncGroup(group) {
    var all = group.querySelector('[data-level-all]');
    if (!all) return;
    var boxes = group.querySelectorAll('.checks input[type="checkbox"]');
    var n = 0;
    Array.prototype.forEach.call(boxes, function (b) { if (b.checked) n++; });
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
  }
  each('[data-level-group]:not(.picker__group)', function (group) {
    var all = group.querySelector('[data-level-all]');
    all.addEventListener('change', function () {
      each('.checks input[type="checkbox"]', function (b) { b.checked = all.checked; }, group);
      syncGroup(group);
    });
    each('.checks input[type="checkbox"]', function (b) { b.addEventListener('change', function () { syncGroup(group); }); }, group);
    syncGroup(group);
  });

  // Sélecteur de classes : doublons synchronisés, compteurs, « tout le niveau », recherche.
  function pickerRefresh(picker) {
    // Classe cochée : ses groupes sont inclus d'office (pastilles grisées).
    each('[data-pill-set]', function (set) {
      var cls = set.querySelector('input[data-parent-class]');
      var covered = !!(cls && cls.checked);
      each('input[data-sub]', function (g) {
        g.disabled = covered;
        if (covered) g.checked = false;
        g.closest('.pill').classList.toggle('is-covered', covered);
      }, set);
    }, picker);
    var values = {}, groups = {};
    each('input[name]:checked', function (b) { (b.hasAttribute('data-sub') ? groups : values)[b.value] = true; }, picker);
    var n = Object.keys(values).length, ng = Object.keys(groups).length;
    var sum = picker.querySelector('[data-picker-summary]');
    if (sum) {
      var z = picker.getAttribute('data-sum-zero'), o = picker.getAttribute('data-sum-one'), m = picker.getAttribute('data-sum-many');
      var parts = [];
      if (n) parts.push(n + ' classe' + (n > 1 ? 's' : ''));
      if (ng) parts.push(ng + ' groupe' + (ng > 1 ? 's' : ''));
      sum.textContent = z
        ? (n === 0 ? z : (n === 1 ? o : m).replace('{n}', n))
        : (parts.length ? parts.join(' + ') + (ng ? ' sélectionné' : ' sélectionnée') + (n + ng > 1 ? 's' : '') : 'Aucune classe sélectionnée');
    }
    each('.picker__group', function (g) {
      var boxes = g.querySelectorAll('.pills input[name]:not([data-sub])'), k = 0;
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) k++; });
      var c = g.querySelector('[data-group-count]');
      if (c) { c.textContent = k ? k + '/' + boxes.length : String(boxes.length); c.classList.toggle('has-selection', k > 0); }
      var all = g.querySelector('[data-level-all]');
      if (all) { all.checked = k > 0 && k === boxes.length; all.indeterminate = k > 0 && k < boxes.length; }
    }, picker);
  }
  each('[data-picker]', function (picker) {
    picker.addEventListener('change', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-level-all')) {
        each('.pills input[name]:not([data-sub])', function (b) { b.checked = t.checked; }, t.closest('.picker__group'));
        each('.pills input[name]:not([data-sub])', function (b) {
          each('input[name][value="' + CSS.escape(b.value) + '"]', function (d) { d.checked = b.checked; }, picker);
        }, t.closest('.picker__group'));
      } else if (t.name) {
        each('input[name][value="' + CSS.escape(t.value) + '"]', function (d) { d.checked = t.checked; }, picker);
      }
      pickerRefresh(picker);
    });
    var filter = picker.querySelector('[data-picker-filter]');
    if (filter) filter.addEventListener('input', function () {
      var q = filter.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
      if (!picker.querySelector('.picker__group')) {
        each('.pill', function (p) { p.classList.toggle('is-hidden', !!q && (p.getAttribute('data-pill') || '').indexOf(q) === -1); }, picker);
        return;
      }
      each('.picker__group', function (g) {
        var hits = 0;
        each('.pill:not(.pill--all)', function (p) {
          var ok = !q || (p.getAttribute('data-pill') || '').indexOf(q) !== -1;
          p.classList.toggle('is-hidden', !ok);
          if (ok) hits++;
        }, g);
        g.hidden = !!q && hits === 0;
        if (q && hits) g.open = true;
      }, picker);
    });
    pickerRefresh(picker);
  });

  // Filtre d'un tableau (comptes enseignants…).
  each('[data-list-filter]', function (input) {
    var targets = document.querySelectorAll(input.getAttribute('data-list-filter'));
    if (!targets.length) return;
    input.addEventListener('input', function () {
      var q = fold(input.value);
      Array.prototype.forEach.call(targets, function (t) {
        var items = t.querySelectorAll('tbody tr, [data-filter-text]');
        Array.prototype.forEach.call(items, function (el) {
          if (!el.hasAttribute('data-filter-text')) return;
          el.hidden = !!q && fold(el.getAttribute('data-filter-text')).indexOf(q) === -1;
        });
      });
    });
  });

  // Formulaire de compte : sections inutiles pour un administrateur (il a tous les droits).
  each('[data-role-select]', function (sel) {
    var form = sel.closest('form');
    function sync() { each('[data-prof-only]', function (s) { s.hidden = sel.value === 'admin'; }, form); }
    sel.addEventListener('change', sync);
    sync();
  });

  // Onglets de la bibliothèque (Contenu / Partage / Dépôt / Informations).
  each('[data-tabs]', function (nav) {
    var panels = document.querySelectorAll('[data-tab-panel]');
    function show(id, push) {
      var found = false;
      Array.prototype.forEach.call(panels, function (p) { if (p.id === id) found = true; });
      if (!found) id = nav.getAttribute('data-default');
      Array.prototype.forEach.call(panels, function (p) { p.hidden = p.id !== id; });
      each('[data-tab]', function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-current', on ? 'page' : 'false');
      }, nav);
      if (push && history.replaceState) history.replaceState(null, '', '#' + id);
    }
    each('[data-tab]', function (t) {
      t.addEventListener('click', function (e) { e.preventDefault(); show(t.getAttribute('data-tab'), true); });
    }, nav);
    show(location.hash.slice(1), false);
  });

  // « Tout / Aucune » : agit sur le sélecteur (ou le groupe de cases) le plus proche.
  each('[data-check]', function (btn) {
    btn.addEventListener('click', function () {
      var scope = btn.closest('[data-picker]') || btn.closest('fieldset') || btn.closest('form');
      var state = btn.getAttribute('data-check') === 'all';
      each('input[type="checkbox"]', function (box) { box.disabled = false; box.checked = state; }, scope);
      if (scope.hasAttribute && scope.hasAttribute('data-picker')) pickerRefresh(scope);
      each('[data-level-group]', syncGroup, scope);
      if (scope.hasAttribute('data-picker')) pickerRefresh(scope);
    });
  });

  // Zone « Niveaux et classes » : la touche Tab insère un retrait.
  each('[data-tab-indent]', function (area) {
    area.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab' || e.shiftKey) return;
      e.preventDefault();
      var start = area.selectionStart, end = area.selectionEnd;
      area.value = area.value.slice(0, start) + '    ' + area.value.slice(end);
      area.selectionStart = area.selectionEnd = start + 4;
    });
  });

  // Confirmation avant les actions destructrices.
  each('form[data-confirm]', function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Affichage d'un formulaire masqué (ex. : renommer).
  each('[data-toggle]', function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-toggle'));
      if (!target) return;
      target.hidden = !target.hidden;
      var input = target.querySelector('input[type="text"]');
      if (!target.hidden && input) { input.focus(); input.select(); }
    });
  });

  // Ouvrir un bloc <details> depuis un lien.
  each('[data-open-details]', function (link) {
    link.addEventListener('click', function () {
      var d = document.getElementById(link.getAttribute('data-open-details'));
      if (d) d.open = true;
    });
  });
  if (location.hash) {
    var target = document.getElementById(location.hash.slice(1));
    if (target && target.tagName === 'DETAILS') target.open = true;
  }

  // Zones de dépôt de fichiers : glisser-déposer + liste des fichiers choisis.
  each('.dropzone', function (zone) {
    var input = zone.querySelector('input[type="file"]');
    var list = zone.querySelector('[data-file-list]');
    function show() {
      if (!list) return;
      var names = Array.prototype.map.call(input.files || [], function (f) { return f.name; });
      list.textContent = names.length ? names.join(', ') : '';
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.remove('is-over'); });
    });
  });

  // Désactive le bouton d'envoi pendant un téléversement (évite les doubles envois).
  each('form[enctype="multipart/form-data"]', function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.lastChild.textContent = ' Envoi en cours…'; }
    });
  });

  // Boutons « Copier ».
  each('[data-copy]', function (btn) {
    btn.addEventListener('click', function () {
      var value = btn.getAttribute('data-copy');
      var original = btn.innerHTML;
      var done = function () {
        btn.textContent = 'Copié';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done);
      } else {
        var area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'absolute';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* ignoré */ }
        document.body.removeChild(area);
      }
    });
  });

  // Menu « Affichage » : thème, taille du texte, lecture facilitée (préférences du poste).
  var root = document.documentElement;
  function store(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* navigation privée */ } }
  function read(key, fallback) { try { return localStorage.getItem(key) || fallback; } catch (e) { return fallback; } }
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function applyTheme() {
    var t = read('tpw-theme', 'auto');
    if (t === 'auto') t = media && media.matches ? 'dark' : 'light';
    root.setAttribute('data-theme', t);
  }
  if (media && media.addEventListener) media.addEventListener('change', applyTheme);

  each('[data-pref]', function (group) {
    var key = group.getAttribute('data-pref');
    var fallback = key === 'tpw-theme' ? 'auto' : 'normal';
    function sync() {
      var current = read(key, fallback);
      each('button', function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-value') === current ? 'true' : 'false'); }, group);
    }
    each('button', function (b) {
      b.addEventListener('click', function () {
        store(key, b.getAttribute('data-value'));
        if (key === 'tpw-theme') applyTheme(); else root.setAttribute('data-text', b.getAttribute('data-value'));
        sync();
      });
    }, group);
    sync();
  });
  each('[data-dys-toggle]', function (box) {
    box.checked = read('tpw-dys', '0') === '1';
    box.addEventListener('change', function () {
      store('tpw-dys', box.checked ? '1' : '0');
      if (box.checked) root.setAttribute('data-dys', '1'); else root.removeAttribute('data-dys');
    });
  });
  // Fermer le menu en cliquant ailleurs.
  document.addEventListener('click', function (e) {
    each('[data-display-menu][open]', function (menu) { if (!menu.contains(e.target)) menu.open = false; });
  });

  // Codes de classe : saisie en majuscules.
  each('.input--code', function (input) {
    input.addEventListener('input', function () {
      var pos = input.selectionStart;
      input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
      if (input.type === 'text') input.setSelectionRange(pos, pos);
    });
  });

  /* ---------- V16 ---------- */

  // Comptes à rebours (« dans 2 j 3 h », « dans 12 min »).
  function remaining(ts) {
    var d = Math.round(ts - Date.now() / 1000);
    if (d <= 0) return 'terminé';
    var days = Math.floor(d / 86400), h = Math.floor(d % 86400 / 3600), m = Math.floor(d % 3600 / 60), sec = d % 60;
    if (days >= 1) return 'dans ' + days + ' j' + (h ? ' ' + h + ' h' : '');
    if (h >= 1) return 'dans ' + h + ' h ' + (m < 10 ? '0' : '') + m;
    if (m >= 1) return 'dans ' + m + ' min';
    return 'dans ' + sec + ' s';
  }
  function clock(ts) {
    var d = Math.max(0, Math.round(ts - Date.now() / 1000));
    var h = Math.floor(d / 3600), m = Math.floor(d % 3600 / 60), s = d % 60;
    return (h ? h + ':' : '') + (m < 10 && h ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  var counters = document.querySelectorAll('[data-countdown]');
  if (counters.length) {
    var tick = function () {
      Array.prototype.forEach.call(counters, function (el) {
        var ts = parseInt(el.getAttribute('data-countdown'), 10);
        var left = ts - Date.now() / 1000;
        el.textContent = el.hasAttribute('data-countdown-short') ? '(reste ' + clock(ts) + ')' : remaining(ts);
        el.classList.toggle('is-urgent', left > 0 && left < 86400);
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  // Impression d'un reçu (ou des codes de secours) seul.
  each('[data-print]', function (btn) {
    btn.addEventListener('click', function () {
      var box = btn.closest('[data-receipt]');
      if (box) { box.classList.add('print-target'); document.body.classList.add('printing'); }
      window.print();
      setTimeout(function () {
        if (box) box.classList.remove('print-target');
        document.body.classList.remove('printing');
      }, 500);
    });
  });

  // Groupes protégés : champ de code affiché quand on coche le groupe.
  each('[data-needs-code]', function (box) {
    var input = document.getElementById(box.getAttribute('data-needs-code'));
    if (!input) return;
    function sync() { input.hidden = !box.checked; if (box.checked) input.focus(); }
    box.addEventListener('change', sync);
  });

  // Dépôt : date limite des retards visible seulement si les retards sont acceptés.
  each('[data-late-select]', function (sel) {
    var field = sel.closest('form').querySelector('[data-late-until]');
    if (!field) return;
    function sync() { field.hidden = sel.value !== 'accept'; }
    sel.addEventListener('change', sync);
    sync();
  });

  // QCM (élève) : avertir s'il reste des questions sans réponse.
  each('[data-quiz-form]', function (form) {
    form.addEventListener('submit', function (e) {
      var empty = 0;
      each('.quiz-q', function (q) { if (!q.querySelector('input:checked')) empty++; }, form);
      if (empty && !window.confirm(empty + ' question' + (empty > 1 ? 's restent' : ' reste') + ' sans réponse. Valider quand même ?')) e.preventDefault();
    });
  });

  // Éditeur de QCM.
  each('[data-quiz-editor]', function (form) {
    var list = form.querySelector('[data-qe-list]');
    var tpl = form.querySelector('[data-qe-template]');
    var optTpl = form.querySelector('[data-qe-option-template]');
    function fresh(t) { return document.importNode(t.content, true).firstElementChild; }
    function renumber() {
      each('[data-qe-question]', function (q, i) {
        var num = q.querySelector('[data-qe-num]');
        if (num) num.textContent = i + 1;
      }, list);
      var empty = form.querySelector('[data-qe-empty]');
      if (empty) empty.hidden = !!list.children.length;
    }
    function addOption(q) {
      var o = fresh(optTpl);
      q.querySelector('[data-qe-options]').appendChild(o);
      o.querySelector('input[type="text"]').focus();
    }
    form.querySelector('[data-qe-add]').addEventListener('click', function () {
      var q = fresh(tpl);
      list.appendChild(q);
      renumber();
      q.querySelector('textarea').focus();
    });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;
      var q = btn.closest('[data-qe-question]');
      if (btn.hasAttribute('data-qe-add-option')) addOption(q);
      else if (btn.hasAttribute('data-qe-remove-option')) {
        var opts = q.querySelectorAll('[data-qe-option]');
        if (opts.length > 2) btn.closest('[data-qe-option]').remove();
        else btn.closest('[data-qe-option]').querySelector('input[type="text"]').value = '';
      } else if (btn.hasAttribute('data-qe-remove')) {
        if (window.confirm('Supprimer cette question ?')) { q.remove(); renumber(); }
      } else if (btn.hasAttribute('data-qe-up') && q.previousElementSibling) {
        list.insertBefore(q, q.previousElementSibling); renumber();
      } else if (btn.hasAttribute('data-qe-down') && q.nextElementSibling) {
        list.insertBefore(q.nextElementSibling, q); renumber();
      }
    });
    // Réponse unique : cocher une bonne réponse décoche les autres.
    list.addEventListener('change', function (e) {
      var box = e.target;
      if (box.getAttribute('data-name') !== 'ok' || !box.checked) return;
      var q = box.closest('[data-qe-question]');
      var multi = q.querySelector('[data-name="multi"]');
      if (multi && multi.checked) return;
      each('[data-name="ok"]', function (o) { if (o !== box) o.checked = false; }, q);
    });
    // Noms des champs recalculés à l'envoi : q[i][text], q[i][opts][j], q[i][ok][]…
    form.addEventListener('submit', function () {
      each('[data-qe-question]', function (q, i) {
        var p = 'q[' + i + ']';
        q.querySelector('[data-name="text"]').name = p + '[text]';
        q.querySelector('[data-name="explain"]').name = p + '[explain]';
        q.querySelector('[data-name="multi"]').name = p + '[multi]';
        each('[data-qe-option]', function (o, j) {
          o.querySelector('[data-name="opts"]').name = p + '[opts][' + j + ']';
          var ok = o.querySelector('[data-name="ok"]');
          ok.name = p + '[ok][]';
          ok.value = j;
        }, q);
      }, list);
    });
    renumber();
  });

  // QR code (double authentification) : bibliothèque chargée seulement si nécessaire.
  var qrBoxes = document.querySelectorAll('[data-qr]');
  if (qrBoxes.length) {
    var sc = document.createElement('script');
    sc.src = 'assets/qrcode.js';
    sc.onload = function () {
      Array.prototype.forEach.call(qrBoxes, function (box) {
        var qr = window.qrcode(0, 'M');
        qr.addData(box.getAttribute('data-qr'));
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
      });
    };
    document.head.appendChild(sc);
  }

  // Mode projection : flèches du clavier, plein écran.
  var present = document.querySelector('[data-present]');
  if (present) {
    var stage = document.getElementById('stage');
    var fsBtn = document.querySelector('[data-fullscreen]');
    var toggleFs = function () {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
    };
    if (fsBtn) fsBtn.addEventListener('click', toggleFs);
    document.addEventListener('keydown', function (e) {
      if (e.target.closest && e.target.closest('input, textarea, select')) return;
      if (e.key === 'ArrowRight' || e.key === 'PageDown') { var n = present.getAttribute('data-next'); if (n) location.href = n; }
      else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { var p = present.getAttribute('data-prev'); if (p) location.href = p; }
      else if (e.key === 'f' || e.key === 'F') toggleFs();
    });
    // Le focus revient sur la page pour que les flèches fonctionnent après un clic.
    if (stage) stage.addEventListener('mouseleave', function () { window.focus(); });
  }

  // Fiche élève : groupes de la classe choisie.
  each('[data-class-select]', function (sel) {
    var form = sel.closest('form');
    function sync() { each('[data-class-groups]', function (p) { p.hidden = p.getAttribute('data-class-groups') !== sel.value; }, form); }
    sel.addEventListener('change', sync);
    sync();
  });

  // Répartition dans les groupes : cocher / décocher toute une colonne.
  each('[data-col-toggle]', function (btn) {
    btn.addEventListener('click', function () {
      var boxes = document.querySelectorAll('.matrix-box[data-col="' + btn.getAttribute('data-col-toggle') + '"]');
      var all = Array.prototype.every.call(boxes, function (b) { return b.checked; });
      Array.prototype.forEach.call(boxes, function (b) { b.checked = !all; });
    });
  });

  // Liste des élèves : sélection multiple et actions groupées.
  each('[data-bulk-form]', function (form) {
    var bar = form.querySelector('[data-bulk-bar]');
    var count = form.querySelector('[data-bulk-count]');
    var all = form.querySelector('[data-bulk-all]');
    var op = form.querySelector('[data-bulk-op]');
    function items() { return Array.prototype.slice.call(form.querySelectorAll('[data-bulk-item]')); }
    function visible(b) { return !b.closest('tr').hidden; }
    function sync() {
      var n = items().filter(function (b) { return b.checked; }).length;
      bar.hidden = n === 0;
      count.textContent = n + ' élève' + (n > 1 ? 's' : '') + ' sélectionné' + (n > 1 ? 's' : '');
      var vis = items().filter(visible);
      var nv = vis.filter(function (b) { return b.checked; }).length;
      all.checked = vis.length > 0 && nv === vis.length;
      all.indeterminate = nv > 0 && nv < vis.length;
    }
    function syncOp() {
      each('[data-bulk-for]', function (sel) {
        var on = sel.getAttribute('data-bulk-for') === op.value;
        sel.hidden = !on; sel.disabled = !on;
      }, form);
    }
    all.addEventListener('change', function () { items().filter(visible).forEach(function (b) { b.checked = all.checked; }); sync(); });
    form.addEventListener('change', function (e) { if (e.target.hasAttribute('data-bulk-item')) sync(); });
    op.addEventListener('change', syncOp);
    form.querySelector('[data-bulk-clear]').addEventListener('click', function () { items().forEach(function (b) { b.checked = false; }); sync(); });
    form.addEventListener('submit', function (e) {
      var isDelete = (e.submitter && e.submitter.hasAttribute('data-bulk-delete')) || op.value === 'delete';
      if (isDelete && !window.confirm(form.getAttribute('data-confirm-delete'))) e.preventDefault();
    });
    syncOp(); sync();
  });

  /* ---------- V18 ---------- */

  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  // Nouveautés depuis la dernière visite de la classe (mémorisée dans le navigateur).
  var visit = document.querySelector('[data-visit]');
  if (visit) {
    var cls = visit.getAttribute('data-visit');
    var sKey = 'tpw-prev-' + cls, lKey = 'tpw-visit-' + cls;
    var prev = null;
    try { prev = sessionStorage.getItem(sKey); } catch (e) {}
    if (prev === null) { prev = read(lKey) || '0'; try { sessionStorage.setItem(sKey, prev); } catch (e) {} }
    store(lKey, String(Math.floor(Date.now() / 1000)));
    prev = parseInt(prev, 10) || 0;
    if (prev > 0) {
      var fresh = {};
      each('[data-news]', function (li) {
        if (parseInt(li.getAttribute('data-news'), 10) > prev) {
          li.classList.add('is-fresh');
          var f = li.querySelector('[data-fav]');
          fresh[f ? f.getAttribute('data-fav') : String(Math.random())] = true;
        }
      });
      var n = Object.keys(fresh).length;
      var banner = document.querySelector('[data-fresh-banner]');
      if (n && banner) {
        var d = new Date(prev * 1000);
        banner.querySelector('[data-fresh-text]').textContent = n + ' nouveauté' + (n > 1 ? 's' : '') + ' depuis ta dernière visite (le ' + d.toLocaleDateString('fr-FR') + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) + ')';
        banner.hidden = false;
      }
    }
  }

  // Favoris : sur le serveur pour l'élève connecté par l'ENT, dans le navigateur sinon.
  var favBlock = document.querySelector('[data-fav-block]');
  var favMode = favBlock ? favBlock.getAttribute('data-fav-mode') : (document.body.getAttribute('data-student') ? 'server' : 'local');
  var favLevel = favBlock ? favBlock.getAttribute('data-level') : '';
  var levelMatch = location.search.match(/[?&]level=([^&]+)/);
  if (!favLevel && levelMatch) favLevel = decodeURIComponent(levelMatch[1].replace(/\+/g, ' '));
  var favKey = 'tpw-fav-' + favLevel;
  function localFavs() { try { return JSON.parse(read(favKey) || '[]'); } catch (e) { return []; } }
  function renderLocalFavs() {
    if (!favBlock || favMode !== 'local') return;
    var list = favBlock.querySelector('[data-fav-list]');
    var favs = localFavs();
    list.innerHTML = '';
    favs.forEach(function (f) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'doc';
      a.href = f.href;
      a.innerHTML = '<span class="doc__icon"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg></span><span class="doc__body"><span class="doc__title"></span><span class="doc__meta"></span></span>';
      a.querySelector('.doc__title').textContent = f.title;
      a.querySelector('.doc__meta').textContent = f.meta || '';
      li.appendChild(a);
      list.appendChild(li);
    });
    favBlock.hidden = favs.length === 0;
  }
  if (favMode === 'local' && favLevel) {
    var favPaths = localFavs().map(function (f) { return f.path; });
    each('[data-fav]', function (b) {
      var on = favPaths.indexOf(b.getAttribute('data-fav')) !== -1;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderLocalFavs();
  }
  each('[data-fav]', function (btn) {
    btn.addEventListener('click', function () {
      var path = btn.getAttribute('data-fav');
      var on = !btn.classList.contains('is-on');
      function apply(state) {
        each('[data-fav="' + CSS.escape(path) + '"]', function (b) {
          b.classList.toggle('is-on', state);
          b.setAttribute('aria-pressed', state ? 'true' : 'false');
          b.title = state ? 'Retirer des favoris' : 'Ajouter aux favoris';
        });
      }
      if (favMode === 'server') {
        var csrf = document.getElementById('tpw-csrf');
        var body = new URLSearchParams({ action: 'fav_toggle', path: path, csrf: csrf ? csrf.value : '' });
        fetch('index.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j.ok) apply(j.on); });
        return;
      }
      var favs = localFavs().filter(function (f) { return f.path !== path; });
      if (on) favs.unshift({ path: path, title: btn.getAttribute('data-fav-title'), href: btn.getAttribute('data-fav-href'), meta: btn.getAttribute('data-fav-meta') });
      store(favKey, JSON.stringify(favs.slice(0, 30)));
      apply(on);
      renderLocalFavs();
    });
  });

  // Séquences : progression gardée dans le navigateur pour un élève non connecté.
  each('[data-seq-local]', function (box) {
    var key = 'tpw-seq-' + box.getAttribute('data-seq-local');
    var done = [];
    try { done = JSON.parse(read(key) || '[]'); } catch (e) {}
    function paint() {
      var n = 0, items = box.querySelectorAll('[data-seq-item]');
      Array.prototype.forEach.call(items, function (li) {
        var on = done.indexOf(li.getAttribute('data-seq-item')) !== -1;
        li.classList.toggle('is-done', on);
        var b = li.querySelector('[data-seq-toggle]');
        if (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.querySelector('span').textContent = on ? 'Terminé' : 'J’ai terminé'; }
        if (on) n++;
      });
      var bar = box.querySelector('[data-seq-bar]');
      if (bar) bar.style.width = (items.length ? Math.round(n / items.length * 100) : 0) + '%';
      var txt = box.querySelector('[data-seq-count]');
      if (txt) txt.textContent = n + ' / ' + items.length;
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-seq-toggle]');
      if (!b) return;
      var id = b.closest('[data-seq-item]').getAttribute('data-seq-item');
      done = done.indexOf(id) === -1 ? done.concat([id]) : done.filter(function (x) { return x !== id; });
      store(key, JSON.stringify(done));
      paint();
    });
    paint();
  });

  // Application installable (PWA) et mode hors ligne.
  if ('serviceWorker' in navigator && document.body.classList.contains('area-public')
      && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.php').catch(function () {}); });
  }
  var installEvent = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    installEvent = e;
    each('[data-install]', function (b) { b.hidden = false; });
  });
  each('[data-install]', function (b) {
    b.addEventListener('click', function () {
      if (!installEvent) return;
      installEvent.prompt();
      installEvent = null;
      b.hidden = true;
    });
  });
  if (!navigator.onLine) document.body.classList.add('is-offline');
  window.addEventListener('offline', function () { document.body.classList.add('is-offline'); });
  window.addEventListener('online', function () { document.body.classList.remove('is-offline'); });

  // Apparence : revenir à la couleur d'origine.
  each('[data-color-reset]', function (b) {
    b.addEventListener('click', function () {
      b.closest('.color-row').querySelector('input[type="color"]').value = b.getAttribute('data-color-reset');
    });
  });
})();
