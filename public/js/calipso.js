/* Calipso : comportements de l'interface (sans dépendance). Le portail reste utilisable sans JavaScript. */
(function () {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9@]+/g, ' ').trim();
  const words = s => norm(s).split(' ').filter(Boolean);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const store = {
    get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } }
  };

  /* ---- Vue DSN : affiche ce qui sera créé dans EasyVista ---- */
  function initLogicToggle() {
    const t = $('#logicToggle');
    if (!t) return;
    if (store.get('calipso-dsn') === '1') { t.checked = true; document.body.classList.add('show-logic'); }
    t.addEventListener('change', () => {
      document.body.classList.toggle('show-logic', t.checked);
      store.set('calipso-dsn', t.checked ? '1' : '0');
    });
  }

  /* ---- Accueil : orientation en direct pendant la saisie ---- */
  function initOrientation() {
    const form = $('#sform'), input = $('#q'), box = $('#results');
    if (!form || !input || !box) return;
    const url = form.dataset.orientationUrl;
    let timer, controller, mode = new URLSearchParams(location.search).get('mode') || '';

    const load = () => {
      const q = input.value.trim();
      if (controller) controller.abort();
      controller = new AbortController();
      const params = new URLSearchParams();
      if (q) params.set('q', q);
      if (q && mode) params.set('mode', mode);
      fetch(url + '?' + params, {headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal})
        .then(r => r.ok ? r.text() : Promise.reject(r.status))
        .then(html => {
          box.innerHTML = html;
          history.replaceState(null, '', params.toString() ? '?' + params : location.pathname);
        })
        .catch(e => { if (e && e.name === 'AbortError') return; });
    };

    input.addEventListener('input', () => { clearTimeout(timer); mode = ''; timer = setTimeout(load, 200); });
    form.addEventListener('submit', e => { e.preventDefault(); clearTimeout(timer); mode = ''; load(); });
    $$('[data-example]').forEach(b => b.addEventListener('click', () => {
      input.value = b.dataset.example; mode = ''; load();
      box.scrollIntoView({behavior: 'smooth', block: 'start'});
    }));
    box.addEventListener('click', e => {
      const a = e.target.closest('[data-mode]');
      if (!a) return;
      e.preventDefault(); mode = a.dataset.mode; load();
    });
  }

  /* ---- Listes filtrables (sujets d'incident, ressources d'accès, rubriques) ---- */
  function initFilters() {
    const lists = new Set([...$$('[data-filter]').map(i => i.dataset.filter), ...$$('[data-chips]').map(c => c.dataset.chips)]);
    lists.forEach(sel => {
      const list = $(sel);
      if (!list) return;
      const input = $(`[data-filter="${sel}"]`), chips = $(`[data-chips="${sel}"]`);
      const rows = $$('.row', list);
      const limit = parseInt(list.dataset.limit || '0', 10);
      const empty = $('[data-empty]', list), more = $('[data-more]', list);
      const texts = rows.map(r => norm(r.textContent));
      let cat = chips ? (($('[aria-pressed="true"]', chips) || {}).dataset || {}).chip || 'all' : 'all';

      const draw = () => {
        const tq = words(input ? input.value : '');
        let shown = 0, matching = 0;
        rows.forEach((r, i) => {
          const okCat = cat === 'all' || (r.dataset.cats || '').split(' ').includes(cat);
          const okText = tq.every(t => texts[i].includes(t));
          const ok = okCat && okText;
          if (ok) matching++;
          const visible = ok && (!limit || shown < limit);
          if (visible) shown++;
          r.classList.toggle('is-hidden', !visible);
        });
        if (empty) empty.hidden = matching > 0;
        if (more) {
          more.hidden = matching <= shown;
          more.textContent = `${matching - shown} autres résultats : précisez votre recherche.`;
        }
      };
      if (input) input.addEventListener('input', draw);
      if (chips) $$('[data-chip]', chips).forEach(b => b.addEventListener('click', () => {
        cat = b.dataset.chip;
        $$('[data-chip]', chips).forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        draw();
      }));
      draw();
    });
  }

  /* ---- « Pour qui ? » et recherche de collègue ---- */
  function initBeneficiary() {
    $$('[data-beneficiary]').forEach(field => {
      const box = $('[data-colleague]', field);
      const input = $('input[name=collegue]', field);
      const ul = $('ul', box);
      const sync = () => {
        const other = $('#pour-collegue', field).checked;
        box.classList.toggle('is-hidden', !other);
        input.required = other;
        if (other) input.focus();
      };
      $$('input[name=pour]', field).forEach(r => r.addEventListener('change', sync));

      let timer;
      input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { ul.hidden = true; return; }
        timer = setTimeout(() => {
          fetch(box.dataset.url + '?q=' + encodeURIComponent(q), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.ok ? r.json() : [])
            .then(people => {
              ul.innerHTML = people.map(p => `<li><button type="button" data-value="${esc(p.valeur)}">${esc(p.nom)}<small>${esc(p.service || p.email)}</small></button></li>`).join('');
              ul.hidden = people.length === 0;
            })
            .catch(() => { ul.hidden = true; });
        }, 250);
      });
      ul.addEventListener('click', e => {
        const b = e.target.closest('[data-value]');
        if (!b) return;
        input.value = b.dataset.value; ul.hidden = true;
      });
      document.addEventListener('click', e => { if (!box.contains(e.target)) ul.hidden = true; });
    });
  }

  /* ---- Parcours accès : changer d'action recharge la liste ---- */
  function initAccess() {
    const form = $('[data-access-form]');
    if (!form) return;
    $$('[data-autosubmit]', form).forEach(r => r.addEventListener('change', () => {
      const p = new URLSearchParams(location.search);
      p.set('action', r.value);
      const res = $('input[name=ressource]', form);
      if (res) p.set('ressource', res.value);
      location.search = p.toString();
    }));
  }

  /* ---- Mes demandes : panneau de détail ---- */
  function initDrawer() {
    const drawer = $('#drawer'), scrim = $('.scrim'), list = $('#ticket-list');
    if (!drawer || !scrim) return;
    const listUrl = drawer.dataset.listUrl;
    const baseTitle = document.title.replace(/^[A-Z]\d{6}_\d+ · /, '');
    const setTitle = url => {
      const m = url && url.match(/\/mes-demandes\/([^/?]+)/);
      document.title = m ? `${decodeURIComponent(m[1])} · ${baseTitle}` : baseTitle;
    };
    let lastFocus = null, controller = null;

    const isOpen = () => drawer.classList.contains('open');
    const markCurrent = url => $$('.tkrow', list || document).forEach(r =>
      r.setAttribute('aria-current', url && r.getAttribute('href') === url ? 'true' : 'false'));
    const show = () => {
      drawer.classList.add('open'); scrim.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false'); document.body.classList.add('drawer-open');
    };
    const focusClose = () => { const c = $('[data-drawer-close]', drawer); if (c) c.focus(); };

    function open(url, push) {
      lastFocus = document.activeElement;
      if (controller) controller.abort();
      controller = new AbortController();
      drawer.innerHTML = '<div class="drawer-loading">Chargement…</div>';
      show(); markCurrent(url); setTitle(url);
      if (push) history.pushState({drawer: url}, '', url);
      fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal})
        .then(r => r.ok ? r.text() : Promise.reject(r.status))
        .then(html => { drawer.innerHTML = html; focusClose(); })
        .catch(e => {
          if (e && e.name === 'AbortError') return;
          // En cas d'échec, on charge la page complète (le panneau y est ouvert côté serveur)
          location.href = url;
        });
    }
    function close(push) {
      if (!isOpen()) return;
      drawer.classList.remove('open'); scrim.classList.remove('open');
      drawer.setAttribute('aria-hidden', 'true'); document.body.classList.remove('drawer-open');
      markCurrent(null); setTitle(null);
      if (push) history.pushState({}, '', listUrl);
      if (lastFocus && document.body.contains(lastFocus)) lastFocus.focus();
    }

    if (isOpen()) { document.body.classList.add('drawer-open'); focusClose(); }

    if (list) list.addEventListener('click', e => {
      const row = e.target.closest('.tkrow[data-drawer]');
      if (!row || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      open(row.dataset.drawer, true);
    });
    scrim.addEventListener('click', () => close(true));
    drawer.addEventListener('click', e => {
      if (e.target.closest('[data-drawer-close]')) { e.preventDefault(); close(true); return; }
      const copy = e.target.closest('[data-copy-link]');
      if (copy) {
        const done = () => { copy.title = 'Lien copié'; copy.classList.add('copied'); setTimeout(() => { copy.title = 'Copier le lien de cette demande'; copy.classList.remove('copied'); }, 2000); };
        if (navigator.clipboard) navigator.clipboard.writeText(copy.dataset.copyLink).then(done, () => window.prompt('Lien de la demande :', copy.dataset.copyLink));
        else window.prompt('Lien de la demande :', copy.dataset.copyLink);
      }
    });
    drawer.addEventListener('change', e => {
      if (e.target.matches('[data-autosubmit-file]') && e.target.files.length) e.target.form.submit();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen()) close(true); });
    window.addEventListener('popstate', () => {
      const m = location.pathname.match(/\/mes-demandes\/([^/]+)$/);
      if (m) open(location.pathname + location.search, false); else close(false);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initLogicToggle();
    initOrientation();
    initFilters();
    initBeneficiary();
    initAccess();
    initDrawer();
  });
})();
