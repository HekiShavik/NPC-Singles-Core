(function () {
  'use strict';

  function panel() {
    return document.querySelector('.nps-image-repair');
  }

  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NPS_IMAGE_REPAIR.nonce);
    Object.entries(data || {}).forEach(([key, value]) => fd.append(key, value));

    const response = await fetch(NPS_IMAGE_REPAIR.ajaxUrl, {
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
      if (!response.ok) {
        throw new Error(`Serverfejl (HTTP ${response.status}).`);
      }
      throw new Error('Ugyldigt svar fra serveren.');
    }

    if (json.success === false) {
      throw new Error(json.data && json.data.message ? json.data.message : 'Billedreparation fejlede.');
    }

    return json.data || {};
  }

  function renderCounts(root, counts) {
    const summary = root.querySelector('.nps-image-repair__summary');
    const run = root.querySelector('.nps-image-repair__run');
    const total = Number(counts && counts.total ? counts.total : 0);
    const published = Number(counts && counts.published ? counts.published : 0);

    if (summary) {
      summary.textContent = total > 0
        ? `${total} produkt(er) mangler billede, heraf ${published} publiceret.`
        : 'Ingen produkter mangler featured image.';
    }
    if (run) run.disabled = total <= 0;
  }

  function renderFailures(root, failures) {
    let box = root.querySelector('.nps-image-repair__failures');
    if (!Array.isArray(failures) || !failures.length) {
      if (box) box.remove();
      return;
    }

    if (!box) {
      box = document.createElement('div');
      box.className = 'nps-image-repair__failures';
      root.appendChild(box);
    }

    box.innerHTML = '<p><strong>Kunne ikke repareres i denne kørsel:</strong></p>' +
      '<ul>' + failures.map(item => {
        const title = String(item.title || `Produkt ${item.post_id || ''}`);
        const reason = String(item.reason || 'Ukendt fejl');
        const esc = value => value.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
        return `<li>${esc(title)}: ${esc(reason)}</li>`;
      }).join('') + '</ul>';
  }

  async function refresh(root) {
    const status = root.querySelector('.nps-image-repair__status');
    try {
      const counts = await post('nps_image_repair_count', { gameId: NPS_IMAGE_REPAIR.gameId });
      renderCounts(root, counts);
      if (status) status.textContent = '';
    } catch (error) {
      if (status) status.textContent = error.message || 'Kunne ikke tælle manglende billeder.';
    }
  }

  async function runBatch(root) {
    const run = root.querySelector('.nps-image-repair__run');
    const status = root.querySelector('.nps-image-repair__status');
    if (!run || run.disabled) return;

    const requested = Math.max(1, Number(NPS_IMAGE_REPAIR.batchSize || 10));
    let repaired = 0;
    let failed = 0;
    let attempted = 0;
    let failures = [];
    let latestCounts = null;

    run.disabled = true;
    renderFailures(root, []);

    try {
      for (let i = 0; i < requested; i++) {
        if (status) status.textContent = `Behandler ${i + 1} / ${requested}...`;

        // One image per HTTP request. WordPress generates several intermediate
        // image sizes during a sideload, so repairing ten products inside one
        // PHP request can otherwise hit max_execution_time on normal hosting.
        const result = await post('nps_image_repair_batch', {
          gameId: NPS_IMAGE_REPAIR.gameId,
          limit: 1,
        });

        const thisAttempted = Number(result.attempted || 0);
        attempted += thisAttempted;
        repaired += Number(result.repaired || 0);
        failed += Number(result.failed || 0);
        latestCounts = result.counts || latestCounts;

        if (Array.isArray(result.failures) && result.failures.length) {
          failures = failures.concat(result.failures);
        }

        if (latestCounts) renderCounts(root, latestCounts);
        if (thisAttempted <= 0 || Number(latestCounts && latestCounts.total ? latestCounts.total : 0) <= 0) {
          break;
        }
      }

      renderFailures(root, failures);
      if (status) {
        status.textContent = attempted > 0
          ? `${repaired} repareret · ${failed} fejlede.`
          : 'Intet at reparere.';
      }
    } catch (error) {
      renderFailures(root, failures);
      if (status) {
        const prefix = attempted > 0 ? `${repaired} repareret før stop · ` : '';
        status.textContent = prefix + (error.message || 'Billedreparation fejlede.');
      }
      if (!latestCounts || Number(latestCounts.total || 0) > 0) {
        run.disabled = false;
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof NPS_IMAGE_REPAIR === 'undefined') return;
    const root = panel();
    if (!root) return;

    const run = root.querySelector('.nps-image-repair__run');
    if (run) run.addEventListener('click', () => runBatch(root));
    refresh(root);
  });
})();
