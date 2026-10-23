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
      if (progress.done) {
        const failures = Array.isArray(progress.failedSets) ? progress.failedSets : [];
        status.textContent = failures.length
          ? `Færdig med ${failures.length} advarsel(er) · ${Number(progress.indexed || 0).toLocaleString('da-DK')} printings. Fejlede sæt blev bevaret fra eksisterende indeks, hvis de fandtes.`
          : `Færdig · ${Number(progress.indexed || 0).toLocaleString('da-DK')} printings.`;
      } else {
        const next = progress.nextSet || {};
        const nextLabel = String(next.name || next.id || '').trim();
        const nextText = nextLabel ? ` · Næste: ${next.position || processed + 1}/${total} ${nextLabel}` : '';
        const last = progress.lastStep || null;
        const timingText = last
          ? ` · Sidst: ${Number(last.totalMs || 0)} ms (cache ${Number(last.cacheMs || 0)}, DB ${Number(last.dbMs || 0)})`
          : '';
        const failureText = last && last.failed
          ? ` · ADVARSEL: ${String(last.setName || last.setId || 'sæt')} blev sprunget over`
          : '';
        status.textContent = `${processed} / ${total} sæt · ${Number(progress.accepted || 0).toLocaleString('da-DK')} kort behandlet${nextText}${timingText}${failureText}…`;
      }
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
          document.dispatchEvent(new CustomEvent('nps-card-index-updated', { detail: { gameId } }));
        } catch (error) {
          if (status) status.textContent = error.message || 'Indeksering fejlede.';
        } finally {
          button.disabled = false;
        }
      });
    });
  }

  function wireRaritySummary() {
    const root = document.querySelector('.nps-card-rarity');
    if (!root) return;

    const gameSelect = root.querySelector('.nps-card-rarity__game');
    const languageSelect = root.querySelector('.nps-card-rarity__language');
    const setSelect = root.querySelector('.nps-card-rarity__set');
    const refreshButton = root.querySelector('.nps-card-rarity__refresh');
    const status = root.querySelector('.nps-card-rarity__status');
    const results = root.querySelector('.nps-card-rarity__results');
    let requestId = 0;

    if (Array.from(gameSelect.options).some(option => option.value === String(config.gameId || ''))) {
      gameSelect.value = String(config.gameId);
    }

    async function refresh(resetLanguage, resetSet) {
      const gameId = String(gameSelect.value || '');
      const language = resetLanguage ? '' : String(languageSelect.value || '');
      const setId = (resetLanguage || resetSet) ? '' : String(setSelect.value || '');
      const thisRequest = ++requestId;
      languageSelect.disabled = true;
      setSelect.disabled = true;
      refreshButton.disabled = true;
      status.textContent = 'Henter fordeling fra det lokale indeks…';

      try {
        const data = await post('nps_local_card_rarity_summary', { gameId, language, setId });
        if (thisRequest !== requestId) return;
        const languages = Array.isArray(data.languages) ? data.languages : [];
        languageSelect.innerHTML = languages.length
          ? languages.map(entry =>
            '<option value="' + esc(entry.language) + '">' + esc(entry.language || '(uden sprog)') +
            ' (' + Number(entry.count || 0).toLocaleString('da-DK') + ')</option>'
          ).join('')
          : '<option value="">Ingen sprog</option>';
        languageSelect.disabled = languages.length === 0;
        if (data.language) languageSelect.value = String(data.language);

        const sets = Array.isArray(data.sets) ? data.sets : [];
        setSelect.innerHTML = sets.length
          ? sets.map(entry =>
            '<option value="' + esc(entry.set_id) + '">' + esc(entry.set_name || entry.set_id) +
            ' (' + Number(entry.count || 0).toLocaleString('da-DK') + ')</option>'
          ).join('')
          : '<option value="">Ingen sæt i indekset</option>';
        setSelect.disabled = sets.length === 0;
        if (data.setId) setSelect.value = String(data.setId);

        const rarities = Array.isArray(data.rarities) ? data.rarities : [];
        const finishes = Array.isArray(data.finishes) ? data.finishes : [];
        const finishTotals = data.finishTotals && typeof data.finishTotals === 'object' ? data.finishTotals : {};
        const total = Number(data.total || 0);
        const inSetTotal = Number(data.inSetTotal || 0);
        const registeredTotal = Number(data.registeredTotal || 0);
        if (!rarities.length) {
          results.innerHTML = '<p class="description">Ingen kort er indekseret for dette sprog.</p>';
        } else {
          const finishHeaders = finishes.map(finish =>
            '<th class="nps-card-rarity__number">' + esc(finish.label || finish.key) + '</th>'
          ).join('');
          const body = rarities.map(entry => {
            const count = Number(entry.count || 0);
            const share = total ? (100 * count / total).toLocaleString('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0,00';
            const finishCounts = entry.finishCounts && typeof entry.finishCounts === 'object' ? entry.finishCounts : {};
            const cells = finishes.map(finish =>
              '<td class="nps-card-rarity__number">' +
                Number(finishCounts[finish.key] || 0).toLocaleString('da-DK') + '</td>'
            ).join('');
            return '<tr><td>' + esc(entry.rarity || 'Ikke angivet') + '</td>' +
              '<td class="nps-card-rarity__number">' + Number(entry.inSet || 0).toLocaleString('da-DK') + '</td>' +
              '<td class="nps-card-rarity__number">' + Number(entry.registered || 0).toLocaleString('da-DK') + '</td>' +
              cells +
              '<td class="nps-card-rarity__number">' + count.toLocaleString('da-DK') + '</td>' +
              '<td class="nps-card-rarity__number">' + share + ' %</td></tr>';
          }).join('');
          const finishFooter = finishes.map(finish =>
            '<th class="nps-card-rarity__number">' +
              Number(finishTotals[finish.key] || 0).toLocaleString('da-DK') + '</th>'
          ).join('');
          results.innerHTML = '<table class="widefat striped"><thead><tr><th>Sjældenhed</th>' +
            '<th class="nps-card-rarity__number">I sættet</th><th class="nps-card-rarity__number">Registreret</th>' +
            finishHeaders +
            '<th class="nps-card-rarity__number">Antal</th><th class="nps-card-rarity__number">Andel</th></tr></thead><tbody>' +
            body +
            '<tr><th>I alt</th><th class="nps-card-rarity__number">' + inSetTotal.toLocaleString('da-DK') +
            '</th><th class="nps-card-rarity__number">' + registeredTotal.toLocaleString('da-DK') +
            '</th>' + finishFooter +
            '<th class="nps-card-rarity__number">' + total.toLocaleString('da-DK') +
            '</th><th class="nps-card-rarity__number">100,00 %</th></tr></tbody></table>' +
            '<p class="description">Finishkolonnerne tæller unikke kort inden for hver variant. Et kort med både Normal og Foil tæller i begge kolonner, men kun én gang under Registreret.</p>';
        }
        const selectedSet = sets.find(entry => String(entry.set_id) === String(data.setId || ''));
        status.textContent = (selectedSet ? String(selectedSet.set_name || selectedSet.set_id) + ': ' +
          registeredTotal.toLocaleString('da-DK') + ' af ' + inSetTotal.toLocaleString('da-DK') + ' unikke kort registreret. · ' : '') +
          total.toLocaleString('da-DK') + ' printings i hele indekset (' + String(data.language || 'intet sprog') + ').';
      } catch (error) {
        if (thisRequest !== requestId) return;
        results.innerHTML = '';
        status.textContent = error.message || 'Kunne ikke hente fordelingen.';
      } finally {
        if (thisRequest === requestId) {
          refreshButton.disabled = false;
          languageSelect.disabled = !Array.from(languageSelect.options).some(option => option.value !== '');
          setSelect.disabled = !Array.from(setSelect.options).some(option => option.value !== '');
        }
      }
    }

    gameSelect.addEventListener('change', () => refresh(true, true));
    languageSelect.addEventListener('change', () => refresh(false, true));
    setSelect.addEventListener('change', () => refresh(false, false));
    refreshButton.addEventListener('click', () => refresh(false, false));
    document.addEventListener('nps-card-index-updated', event => {
      if (event.detail && String(event.detail.gameId || '') === String(gameSelect.value || '')) refresh(false, false);
    });
    refresh(true, true);
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (String(config.mode || '') === 'bulk') wireSearch();
    if (String(config.mode || '') === 'settings') {
      clarifyExternalIndexRows();
      wireIndexSettings();
      wireRaritySummary();
    }
  });
})();
