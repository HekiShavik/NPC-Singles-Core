(function () {
  'use strict';

  if (typeof NPS_IMAGE_HEALTH === 'undefined') return;

  const state = new Map();
  (Array.isArray(NPS_IMAGE_HEALTH.games) ? NPS_IMAGE_HEALTH.games : []).forEach(game => {
    if (!game || !game.id) return;
    state.set(String(game.id), game.counts && typeof game.counts === 'object' ? {
      total: Number(game.counts.total || 0),
      published: Number(game.counts.published || 0),
    } : null);
  });

  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NPS_IMAGE_HEALTH.nonce);
    Object.entries(data || {}).forEach(([key, value]) => fd.append(key, value));

    const response = await fetch(NPS_IMAGE_HEALTH.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    });

    const text = await response.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch (_) {
      json = null;
    }

    if (!json) {
      if (!response.ok) throw new Error(`Serverfejl (HTTP ${response.status}).`);
      throw new Error('Ugyldigt svar fra serveren.');
    }
    if (json.success === false) {
      throw new Error(json.data && json.data.message ? json.data.message : 'Billedkontrol fejlede.');
    }
    return json.data || {};
  }

  function settingsDot() {
    return document.querySelector('.nps-settings-alert');
  }

  function refreshDot() {
    const dot = settingsDot();
    if (!dot) return;
    const hasMissing = Array.from(state.values()).some(counts => counts && Number(counts.total || 0) > 0);
    dot.hidden = !hasMissing;
  }

  function rowFor(gameId) {
    return document.querySelector(`[data-image-health-game="${CSS.escape(String(gameId))}"]`);
  }

  function statusText(counts) {
    if (!counts) return 'Ikke kontrolleret';
    const total = Number(counts.total || 0);
    const published = Number(counts.published || 0);
    return total > 0 ? `${total} mangler, heraf ${published} publiceret` : 'OK';
  }

  function renderGame(gameId, counts) {
    state.set(String(gameId), counts ? {
      total: Number(counts.total || 0),
      published: Number(counts.published || 0),
    } : null);

    const row = rowFor(gameId);
    if (row) {
      const status = row.querySelector('.nps-image-health__game-status');
      const repair = row.querySelector('.nps-image-health__repair');
      if (status) status.textContent = statusText(counts);
      if (repair) repair.disabled = !counts || Number(counts.total || 0) <= 0;
    }
    refreshDot();
  }

  function renderGameError(gameId, message) {
    const row = rowFor(gameId);
    if (!row) return;
    const status = row.querySelector('.nps-image-health__game-status');
    if (status) status.textContent = message || 'Kunne ikke kontrolleres.';
  }

  async function countGame(gameId) {
    const row = rowFor(gameId);
    if (row) {
      const status = row.querySelector('.nps-image-health__game-status');
      if (status) status.textContent = 'Kontrollerer…';
    }

    try {
      const counts = await post('nps_image_health_count', { gameId });
      renderGame(gameId, counts);
      return counts;
    } catch (error) {
      renderGameError(gameId, error.message || 'Kunne ikke kontrolleres.');
      throw error;
    }
  }

  async function checkAll() {
    const button = document.querySelector('.nps-image-health__check-all');
    const overall = document.querySelector('.nps-image-health__overall-status');
    if (button) button.disabled = true;
    if (overall) overall.textContent = 'Kontrollerer alle spil…';

    let failed = 0;
    const games = Array.isArray(NPS_IMAGE_HEALTH.games) ? NPS_IMAGE_HEALTH.games : [];
    for (const game of games) {
      if (!game || !game.id) continue;
      try {
        await countGame(String(game.id));
      } catch (_) {
        failed += 1;
      }
    }

    if (overall) overall.textContent = failed > 0 ? `${failed} spil kunne ikke kontrolleres.` : 'Kontrol færdig.';
    if (button) button.disabled = false;
  }

  function renderFailures(items) {
    const root = document.querySelector('.nps-image-health__failures');
    if (!root) return;
    if (!Array.isArray(items) || !items.length) {
      root.innerHTML = '';
      return;
    }

    const esc = value => String(value).replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));

    root.innerHTML = '<p><strong>Kunne ikke repareres i denne kørsel:</strong></p><ul>' +
      items.map(item => `<li>${esc(item.title || `Produkt ${item.post_id || ''}`)}: ${esc(item.reason || 'Ukendt fejl')}</li>`).join('') +
      '</ul>';
  }

  async function repairGame(button) {
    const gameId = String(button.getAttribute('data-game') || '');
    if (!gameId || button.disabled) return;

    const row = rowFor(gameId);
    const status = row ? row.querySelector('.nps-image-health__repair-status') : null;
    const requested = Math.max(1, Number(NPS_IMAGE_HEALTH.batchSize || 10));
    let attempted = 0;
    let repaired = 0;
    let failed = 0;
    let failures = [];
    let latestCounts = state.get(gameId) || null;

    button.disabled = true;
    renderFailures([]);

    try {
      for (let i = 0; i < requested; i++) {
        if (status) status.textContent = `Behandler ${i + 1} / ${requested}…`;
        const result = await post('nps_image_repair_batch', { gameId, limit: 1 });
        const thisAttempted = Number(result.attempted || 0);
        attempted += thisAttempted;
        repaired += Number(result.repaired || 0);
        failed += Number(result.failed || 0);
        latestCounts = result.counts || latestCounts;

        if (Array.isArray(result.failures) && result.failures.length) {
          failures = failures.concat(result.failures);
        }
        if (latestCounts) renderGame(gameId, latestCounts);
        if (thisAttempted <= 0 || Number(latestCounts && latestCounts.total ? latestCounts.total : 0) <= 0) break;
      }

      latestCounts = await countGame(gameId);
      renderFailures(failures);
      if (status) {
        status.textContent = attempted > 0 ? `${repaired} repareret · ${failed} fejlede.` : 'Intet at reparere.';
      }
      button.disabled = Number(latestCounts && latestCounts.total ? latestCounts.total : 0) <= 0;
    } catch (error) {
      renderFailures(failures);
      if (status) {
        const prefix = attempted > 0 ? `${repaired} repareret før stop · ` : '';
        status.textContent = prefix + (error.message || 'Billedreparation fejlede.');
      }
      button.disabled = false;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    refreshDot();

    if (String(NPS_IMAGE_HEALTH.mode || '') === 'bulk') {
      const gameId = String(NPS_IMAGE_HEALTH.gameId || '');
      if (gameId) countGame(gameId).catch(() => {});
      return;
    }

    if (String(NPS_IMAGE_HEALTH.mode || '') !== 'settings') return;

    document.querySelectorAll('.nps-image-health__repair').forEach(button => {
      button.addEventListener('click', () => repairGame(button));
    });

    const check = document.querySelector('.nps-image-health__check-all');
    if (check) check.addEventListener('click', checkAll);

    checkAll();
  });
})();
