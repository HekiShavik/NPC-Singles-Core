(function () {
  'use strict';

  if (typeof NPS_CARD_SEARCH === 'undefined') return;

  const config = NPS_CARD_SEARCH || {};
  const SEARCH_DEBOUNCE_MS = 750;

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));
  }

  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', String(config.nonce || ''));
    Object.entries(data || {}).forEach(([key, value]) => fd.append(key, value));

    const response = await fetch(String(config.ajaxUrl || ''), {
      method: 'POST', credentials: 'same-origin', body: fd,
    });
    const text = await response.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (_) { json = null; }
    if (!json) throw new Error(response.ok ? 'Ugyldigt svar fra serveren.' : `Serverfejl (HTTP ${response.status}).`);
    if (json.success === false) throw new Error(json.data && json.data.message ? json.data.message : 'Handlingen fejlede.');
    return json.data || {};
  }

  function gameConfig(gameId) {
    return (Array.isArray(config.games) ? config.games : []).find(game => String(game.id || '') === String(gameId || '')) || null;
  }

  function activeLanguage() {
    const prefix = String(config.uiPrefix || '').trim();
    if (!prefix) return '';
    const input = document.getElementById(`${prefix}_lang`);
    return input ? String(input.value || '').trim().toUpperCase() : '';
  }

  function finishText(item) {
    const finishes = Array.isArray(item && item.finishes) ? item.finishes : [];
    return finishes.map(value => String(value || '').replaceAll('_', ' ')).filter(Boolean).join(' / ');
  }

  function optionalLine(label, value, className) {
    value = String(value || '').trim();
    if (!value) return '';
    return `<span class="${esc(className || 'nps-card-search__meta')}"><strong>${esc(label)}:</strong> ${esc(value)}</span>`;
  }

  function presentationLines(item) {
    const presentation = item && item.presentation && typeof item.presentation === 'object' ? item.presentation : {};
    const meta = Array.isArray(presentation.meta) ? presentation.meta : [];
    return meta.map(entry => {
      if (typeof entry === 'string') return `<span class="nps-card-search__meta">${esc(entry)}</span>`;
      if (!entry || typeof entry !== 'object') return '';
      const label = String(entry.label || '').trim();
      const value = String(entry.value || '').trim();
      if (!value) return '';
      return label
        ? `<span class="nps-card-search__meta"><strong>${esc(label)}:</strong> ${esc(value)}</span>`
        : `<span class="nps-card-search__meta">${esc(value)}</span>`;
    }).join('');
  }

  function hintHtml(item) {
    const presentation = item && item.presentation && typeof item.presentation === 'object' ? item.presentation : {};
    const hints = Array.isArray(presentation.hints) ? presentation.hints.map(v => String(v || '').trim()).filter(Boolean) : [];
    if (!hints.length) return '';
    return `<span class="nps-card-search__hints"><strong>Kendetegn:</strong> ${hints.map(esc).join(' · ')}</span>`;
  }

  function renderResults(root, items, indexed) {
    const results = root.querySelector('.nps-card-search__results');
    if (!results) return;
    if (!Array.isArray(items) || items.length === 0) {
      results.innerHTML = Number(indexed || 0) > 0
        ? '<p class="description">Ingen match i det lokale indeks.</p>'
        : '<p class="description"><strong>Søgeindekset er tomt.</strong> Åbn Indstillinger og opdatér søgeindekset for dette spil.</p>';
      return;
    }

    results.innerHTML = items.map(item => {
      const payload = item && item.payload && typeof item.payload === 'object' ? item.payload : {};
      const oracleName = String(payload.oracle_name || '').trim();
      const version = String(payload.version || '').trim();
      const artist = String(payload.artist || '').trim();
      const illustrator = String(payload.illustrator || '').trim();
      const setCode = String(payload.set_code || item.set_id || '').trim().toUpperCase();
      const finishes = finishText(item);
      const language = String(item.language || '').trim().toUpperCase();
      const subtitle = [
        String(item.set_name || '').trim(),
        setCode ? `${setCode} #${String(item.collector_number || '').trim()}` : (String(item.collector_number || '').trim() ? `#${String(item.collector_number || '').trim()}` : ''),
      ].filter(Boolean).join(' · ');
      const details = [String(item.rarity || '').trim(), finishes, language].filter(Boolean).join(' · ');

      return `
        <button type="button" class="nps-card-search__result" data-set-id="${esc(item.set_id || '')}" data-card-id="${esc(item.card_id || '')}">
          <span class="nps-card-search__image">
            ${item.image_url ? `<img src="${esc(item.image_url)}" alt="" loading="lazy" decoding="async">` : '<span class="nps-card-search__no-image">Intet billede</span>'}
          </span>
          <span class="nps-card-search__body">
            <strong class="nps-card-search__name">${esc(item.card_name || '')}${version ? ` <span class="nps-card-search__version">— ${esc(version)}</span>` : ''}</strong>
            ${oracleName && oracleName !== String(item.card_name || '').trim() ? `<span class="nps-card-search__alias">${esc(oracleName)}</span>` : ''}
            ${subtitle ? `<span class="nps-card-search__meta">${esc(subtitle)}</span>` : ''}
            ${details ? `<span class="nps-card-search__meta">${esc(details)}</span>` : ''}
            ${optionalLine('Artist', artist, 'nps-card-search__meta')}
            ${optionalLine('Illustrator', illustrator, 'nps-card-search__meta')}
            ${presentationLines(item)}
            ${hintHtml(item)}
          </span>
        </button>`;
    }).join('');
  }

  function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

  async function waitFor(getter, timeoutMs, intervalMs) {
    const started = Date.now();
    while ((Date.now() - started) < timeoutMs) {
      const value = getter();
      if (value) return value;
      await sleep(intervalMs || 100);
    }
    return null;
  }

  async function openSearchResult(setId, cardId, status) {
    const prefix = String(config.uiPrefix || '').trim();
    if (!prefix) throw new Error('Spillets UI-prefix mangler.');
    const setInput = document.getElementById(`${prefix}_set`);
    const setIdInput = document.getElementById(`${prefix}_set_id`);
    if (!setInput || !setIdInput) throw new Error('Setvælgeren blev ikke fundet.');

    if (String(setIdInput.value || '').toLowerCase() !== String(setId || '').toLowerCase()) {
      if (status) status.textContent = 'Åbner sæt…';
      setInput.click();
      const picker = document.getElementById(`${prefix}_series_picker`);
      const selector = `[data-set-id="${CSS.escape(String(setId || ''))}"]`;
      const setButton = await waitFor(() => picker ? picker.querySelector(selector) : document.querySelector(selector), 10000, 100);
      if (!setButton) throw new Error('Sættet findes ikke i setvælgeren. Opdatér set-listen under Indstillinger.');
      setButton.click();
    }

    if (status) status.textContent = 'Indlæser kort…';
    const cardSelector = `[data-cardblock="${CSS.escape(String(cardId || ''))}"]`;
    const card = await waitFor(() => document.querySelector(cardSelector), 25000, 120);
    if (!card) throw new Error('Kortet blev ikke fundet i det valgte sæt. Opdatér set-kort-data under Indstillinger.');
    card.classList.add('nps-card-search-target');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => card.classList.remove('nps-card-search-target'), 3500);
  }

  function createSearchPanel() {
    const gameId = String(config.gameId || '');
    const prefix = String(config.uiPrefix || '');
    if (!gameId || !prefix) return null;
    if (document.getElementById(`${prefix}_global_card_search`)) return null;
    const gameWrap = document.querySelector(`.${CSS.escape(prefix)}-wrap`);
    if (!gameWrap) return null;
    const heading = gameWrap.querySelector('h1');
    if (!heading) return null;

    const panel = document.createElement('div');
    panel.className = 'nps-card-search card';
    panel.innerHTML = `
      <div class="nps-card-search__heading"><div><h2>Find kort</h2><p class="description">Søg på tværs af alle lokalt indekserede printings i dette spil.</p></div></div>
      <div class="nps-card-search__row">
        <input type="search" class="regular-text nps-card-search__input" placeholder="Søg efter kortnavn, sæt eller nummer…" autocomplete="off">
        <button type="button" class="button button-primary nps-card-search__button">Find</button>
        <span class="nps-card-search__status" aria-live="polite"></span>
      </div>
      <div class="nps-card-search__results"></div>`;
    heading.insertAdjacentElement('afterend', panel);
    return panel;
  }

  function wireSearch() {
    const root = createSearchPanel();
    if (!root) return;
    const input = root.querySelector('.nps-card-search__input');
    const button = root.querySelector('.nps-card-search__button');
    const status = root.querySelector('.nps-card-search__status');
    const results = root.querySelector('.nps-card-search__results');
    const languageInput = document.getElementById(`${String(config.uiPrefix || '').trim()}_lang`);
    let timer = null;
    let searchSequence = 0;

    async function runSearch() {
      const term = String(input.value || '').trim();
      const language = activeLanguage();
      const sequence = ++searchSequence;
      if (term.length < 2) {
        results.innerHTML = '';
        status.textContent = 'Skriv mindst 2 tegn.';
        button.disabled = false;
        return;
      }

      button.disabled = true;
      status.textContent = 'Søger lokalt…';
      try {
        const data = await post('nps_local_card_search', { gameId: config.gameId, term, language });
        if (sequence !== searchSequence || String(input.value || '').trim() !== term || activeLanguage() !== language) return;
        const items = Array.isArray(data.items) ? data.items : [];
        renderResults(root, items, Number(data.indexed || 0));
        status.textContent = `${items.length} match vist · ${Number(data.indexed || 0).toLocaleString('da-DK')} printings indekseret.`;
      } catch (error) {
        if (sequence !== searchSequence || String(input.value || '').trim() !== term || activeLanguage() !== language) return;
        results.innerHTML = '';
        status.textContent = error.message || 'Søgning fejlede.';
      } finally {
        if (sequence === searchSequence) button.disabled = false;
      }
    }

    button.addEventListener('click', () => { window.clearTimeout(timer); runSearch(); });
    input.addEventListener('keydown', event => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      window.clearTimeout(timer);
      runSearch();
    });
    input.addEventListener('input', () => {
      window.clearTimeout(timer);
      searchSequence += 1;
      if (String(input.value || '').trim().length < 3) return;
      timer = window.setTimeout(runSearch, SEARCH_DEBOUNCE_MS);
    });
    if (languageInput) {
      languageInput.addEventListener('change', () => {
        window.clearTimeout(timer);
        searchSequence += 1;
        results.innerHTML = '';
        status.textContent = '';
        if (String(input.value || '').trim().length >= 2) timer = window.setTimeout(runSearch, 0);
      });
    }

    results.addEventListener('click', async event => {
      const result = event.target.closest('.nps-card-search__result');
      if (!result || result.disabled) return;
      const setId = String(result.getAttribute('data-set-id') || '');
      const cardId = String(result.getAttribute('data-card-id') || '');
      if (!setId || !cardId) return;
      result.disabled = true;
      status.textContent = 'Åbner print…';
      try {
        await openSearchResult(setId, cardId, status);
        status.textContent = 'Print åbnet i kortlisten.';
      } catch (error) {
        status.textContent = error.message || 'Kunne ikke åbne printet.';
      } finally {
        result.disabled = false;
      }
    });
  }

  function updateIndexRow(gameId, progress) {
    const row = document.querySelector(`[data-nps-index-game="${CSS.escape(String(gameId))}"]`);
    if (!row) return;
    const count = row.querySelector('.nps-card-index__count');
    const status = row.querySelector('.nps-card-index__status');
    if (count && progress && progress.indexed !== undefined) count.textContent = `${Number(progress.indexed || 0).toLocaleString('da-DK')} printings`;
    if (status && progress) {
      const processed = Number(progress.processed || 0);
      const total = Number(progress.totalSets || 0);
      status.textContent = progress.done
        ? `Færdig · ${Number(progress.indexed || 0).toLocaleString('da-DK')} printings.`
        : `${processed} / ${total} sæt · ${Number(progress.accepted || 0).toLocaleString('da-DK')} kort behandlet…`;
    }
  }

  function clarifyExternalIndexRows() {
    (Array.isArray(config.games) ? config.games : []).forEach(game => {
      if (!game || !game.externalIndex) return;
      const row = document.querySelector(`[data-nps-index-game="${CSS.escape(String(game.id || ''))}"]`);
      if (!row) return;
      const link = row.querySelector('a.button');
      const status = row.querySelector('.nps-card-index__status');
      if (link) link.textContent = `Åbn ${String(game.name || 'spillets')} indstillinger`;
      if (status) status.textContent = 'Søgeindekset opdateres i spillets egne indstillinger.';
    });
  }

  function wireIndexSettings() {
    document.querySelectorAll('.nps-card-index__rebuild').forEach(button => {
      button.addEventListener('click', async () => {
        if (button.disabled) return;
        const gameId = String(button.getAttribute('data-game') || '');
        if (!gameId) return;
        const row = button.closest('[data-nps-index-game]');
        const status = row ? row.querySelector('.nps-card-index__status') : null;
        button.disabled = true;
        if (status) status.textContent = 'Forbereder indeks…';
        try {
          let progress = await post('nps_local_card_index_start', { gameId });
          updateIndexRow(gameId, progress);
          const token = String(progress.token || '');
          if (!token) throw new Error('Indekskørslen returnerede ikke et token.');
          while (!progress.done) {
            progress = await post('nps_local_card_index_step', { gameId, token });
            updateIndexRow(gameId, progress);
          }
        } catch (error) {
          if (status) status.textContent = error.message || 'Indeksering fejlede.';
        } finally {
          button.disabled = false;
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (String(config.mode || '') === 'bulk') wireSearch();
    if (String(config.mode || '') === 'settings') {
      clarifyExternalIndexRows();
      wireIndexSettings();
    }
  });
})();
