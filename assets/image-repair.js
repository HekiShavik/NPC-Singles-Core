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

    const json = await response.json().catch(() => null);
    if (!json) throw new Error('Ugyldigt svar fra serveren.');
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

    box.innerHTML = '<p><strong>Kunne ikke repareres i denne batch:</strong></p>' +
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

    run.disabled = true;
    if (status) status.textContent = 'Henter og gemmer billeder...';

    try {
      const result = await post('nps_image_repair_batch', {
        gameId: NPS_IMAGE_REPAIR.gameId,
        limit: NPS_IMAGE_REPAIR.batchSize || 10,
      });

      renderCounts(root, result.counts || {});
      renderFailures(root, result.failures || []);

      if (status) {
        status.textContent = `${Number(result.repaired || 0)} repareret · ${Number(result.failed || 0)} fejlede.`;
      }
    } catch (error) {
      if (status) status.textContent = error.message || 'Billedreparation fejlede.';
      run.disabled = false;
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
