(function () {
  'use strict';
  var tabsEl = document.getElementById('tabs');
  var panel = document.getElementById('panel');
  var data = [];
  var current = null;      // id du biome affiché
  var flash = '';
  var typing = false;
  var me = { validated: false, draft: null };
  var invBar = document.getElementById('inv-bar');
  var confirming = false;

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }

  function post(action, biomeId, extra) {
    var body = new URLSearchParams(Object.assign({ action: action, biome_id: biomeId, csrf: window.CSRF }, extra));
    return fetch('api.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  var taxons = [];
  fetch('api.php?taxons=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
    .then(function (j) { taxons = j.taxons || []; }).catch(function () {});

  function load() {
    return fetch('api.php', { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = 'index.php'; throw new Error('401'); }
        return r.json();
      })
      .then(function (j) {
        data = j.biomes || [];
        me = j.me || me;
        if (!data.some(function (b) { return b.id === current; })) current = data.length ? data[0].id : null;
        render();
      });
  }

  function renderTabs() {
    tabsEl.textContent = '';
    data.forEach(function (b) {
      var mine = b.species.filter(function (s) { return s.mine; }).length;
      var t = el('button', {
        type: 'button', role: 'tab', class: 'tab' + (b.id === current ? ' active' : ''),
        'aria-selected': b.id === current ? 'true' : 'false'
      }, [
        el('span', { text: (b.emoji ? b.emoji + ' ' : '') + b.name }),
        el('small', { text: mine + ' / ' + b.species.length })
      ]);
      t.addEventListener('click', function () { current = b.id; flash = ''; render(); });
      tabsEl.appendChild(t);
    });
  }

  function renderPanel() {
    panel.textContent = '';
    var b = data.filter(function (x) { return x.id === current; })[0];
    if (!b) { panel.appendChild(el('p', { class: 'muted', text: "Aucun biome n'est défini pour le moment." })); return; }

    panel.appendChild(el('h2', { text: (b.emoji ? b.emoji + ' ' : '') + b.name }));
    if (b.description) panel.appendChild(el('p', { class: 'muted', text: b.description }));

    var input = el('input', { type: 'text', name: 'name', maxlength: '80', list: 'dl', autocomplete: 'off',
      placeholder: 'Nom d’un être vivant (ex. : Escargot de Bourgogne)', required: 'required' });
    var dl = el('datalist', { id: 'dl' });
    // Autocomplétion à partir de 5 lettres : livret (nom français ou latin) + noms déjà saisis par la classe
    function fold(x) { return x.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/œ/g, 'oe').replace(/[^a-z0-9]+/g, ' ').trim(); }
    function suggest() {
      dl.textContent = '';
      var q = fold(input.value);
      if (q.length < 5) return;
      var seen = {}, n = 0;
      function add(name, label) {
        if (n >= 12 || seen[name]) return;
        seen[name] = 1; n++;
        var o = el('option', { value: name }); if (label) o.label = label; dl.appendChild(o);
      }
      taxons.forEach(function (t) { if (b.habitat && t[2] !== b.habitat) return; if (fold(t[0]).indexOf(q) !== -1 || fold(t[1]).indexOf(q) !== -1) add(t[0], t[1]); });
      b.species.forEach(function (s) { if (fold(s.name).indexOf(q) !== -1) add(s.name, ''); });
    }
    input.addEventListener('input', suggest);
    var form = el('form', { class: 'addform' }, [input, dl, el('button', { class: 'btn', type: 'submit', text: 'Ajouter' })]);
    input.addEventListener('focus', function () { typing = true; });
    input.addEventListener('blur', function () { typing = false; });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var v = input.value.trim();
      if (!v) return;
      post('add', b.id, { name: v }).then(function (j) { flash = j.message || ''; return load(); });
    });
    if (me.validated) { input.disabled = true; form.querySelector('button').disabled = true; }
    panel.appendChild(form);
    if (flash) panel.appendChild(el('p', { class: 'flash', text: flash }));

    var n = b.species.length;
    panel.appendChild(el('h3', { text: n + ' espèce' + (n > 1 ? 's' : '') + ' dans ce biome' }));
    if (!n) panel.appendChild(el('p', { class: 'muted', text: 'Personne n’a encore rien noté ici. Sois le premier !' }));

    var list = el('ul', { class: 'species' });
    b.species.forEach(function (s) {
      var actions;
      if (s.mine) {
        var rm = el('button', { type: 'button', class: 'link danger', text: 'Retirer' });
        rm.addEventListener('click', function () {
          post('remove', b.id, { key: s.key }).then(function (j) { flash = j.message || ''; return load(); });
        });
        actions = rm;
      } else {
        var add = el('button', { type: 'button', class: 'link', text: '+ Moi aussi' });
        add.addEventListener('click', function () {
          post('add', b.id, { name: s.name }).then(function (j) { flash = j.message || ''; return load(); });
        });
        actions = add;
      }
      list.appendChild(el('li', { class: s.mine ? 'mine' : '' }, [
        el('span', { class: 'sp-name', text: s.name }),
        el('span', { class: 'sp-count', text: s.count + ' élève' + (s.count > 1 ? 's' : '') }),
        actions
      ]));
    });
    panel.appendChild(list);
  }

  function renderBar() {
    invBar.textContent = '';
    if (me.validated) {
      var re = el('button', { type: 'button', class: 'btn small ghost', text: 'Rouvrir mon inventaire' });
      re.addEventListener('click', function () { post('reopen', 0, {}).then(function (j) { flash = j.message || ''; return load(); }); });
      invBar.appendChild(el('span', { text: '✅ Inventaire validé. ' }));
      invBar.appendChild(el('a', { class: 'btn small', href: 'classe.php', text: 'Voir le bilan de la classe' }));
      invBar.appendChild(re);
      return;
    }
    var total = data.reduce(function (n, b) { return n + b.species.filter(function (s) { return s.mine; }).length; }, 0);
    var draft = el('button', { type: 'button', class: 'btn small ghost', text: '💾 Enregistrer le brouillon' });
    draft.addEventListener('click', function () { post('draft', 0, {}).then(function (j) { flash = j.message || ''; return load(); }); });
    var val = el('button', { type: 'button', class: 'btn small', text: confirming ? 'Confirmer : je valide, je ne pourrai plus modifier sans rouvrir' : '✅ Valider l’inventaire' });
    val.addEventListener('click', function () {
      if (!confirming) { confirming = true; renderBar(); return; }
      post('validate', 0, {}).then(function (j) {
        confirming = false;
        if (j.redirect) { location.href = j.redirect; return; }
        flash = j.message || ''; return load();
      });
    });
    invBar.appendChild(el('span', { class: 'muted', text: total + ' observation' + (total > 1 ? 's' : '') + ' · ' + (me.draft ? 'brouillon enregistré à ' + me.draft : 'brouillon non enregistré') + ' (les ajouts sont gardés automatiquement) ' }));
    invBar.appendChild(draft);
    invBar.appendChild(val);
    if (confirming) {
      var cancel = el('button', { type: 'button', class: 'link', text: 'Annuler' });
      cancel.addEventListener('click', function () { confirming = false; renderBar(); });
      invBar.appendChild(cancel);
    }
  }

  function render() { renderBar(); renderTabs(); renderPanel(); }

  load();
  // Rafraîchit toutes les 20 s (sauf pendant la saisie) pour voir les ajouts des camarades
  setInterval(function () { if (!typing && !document.hidden) load(); }, 20000);
})();
