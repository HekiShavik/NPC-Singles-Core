(function () {
  'use strict';

  if (typeof NPS_STOCK_AUTOCREATE === 'undefined') return;

  const queues = new WeakMap();

  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NPS_STOCK_AUTOCREATE.nonce);
    Object.entries(data || {}).forEach(([key, value]) => fd.append(key, value));

    const response = await fetch(NPS_STOCK_AUTOCREATE.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    });

    const json = await response.json().catch(() => null);
    if (!json) throw new Error('Ugyldigt svar (ikke JSON).');
    if (json.success === false) {
      throw new Error(json.data && json.data.message ? json.data.message : 'Fejl.');
    }

    const payload = json.data;
    if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'ok')) {
      if (!payload.ok) throw new Error(payload.message || 'Fejl.');
      return payload.data;
    }
    return payload;
  }

  function normalizeStock(value) {
    const parsed = parseInt(String(value || '0'), 10);
    return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
  }

  function rowFor(target) {
    return target && target.closest ? target.closest('tr[data-key][data-card][data-finish]') : null;
  }

  function stockInput(row) {
    return row ? row.querySelector('input[data-field="stock"]') : null;
  }

  function setFieldMessage(row, text, state) {
    if (!row) return;
    const msg = row.querySelector('span[data-msg="stock"]');
    if (!msg) return;

    msg.textContent = text || '';
    Array.from(msg.classList).forEach(className => {
      if (/-msg--(?:saving|ok|err|dirty|staged)$/.test(className)) {
        msg.classList.remove(className);
      }
    });

    if (!state) return;
    const fieldClass = Array.from(msg.classList).find(className => /-fieldmsg$/.test(className));
    if (!fieldClass) return;
    const prefix = fieldClass.replace(/-fieldmsg$/, '');
    msg.classList.add(`${prefix}-msg--${state}`);
  }

  function setBadge(row, label, state) {
    if (!row) return;
    const badge = row.querySelector('span[data-badge]');
    if (!badge) return;

    badge.textContent = label;
    let prefix = '';
    Array.from(badge.classList).forEach(className => {
      const match = className.match(/^(.*)-badge--(?:none|ok|warn)$/);
      if (!match) return;
      prefix = match[1];
      badge.classList.remove(className);
    });
    if (prefix && state) badge.classList.add(`${prefix}-badge--${state}`);
  }

  function markSelected(row) {
    if (!row) return;
    const checkbox = row.querySelector('input[data-sel="1"], input[type="checkbox"][data-key]');
    if (checkbox) checkbox.checked = true;
  }

  function currentSetId() {
    const selector = String(NPS_STOCK_AUTOCREATE.setIdSelector || '');
    if (!selector) return '';
    const input = document.querySelector(selector);
    return input ? String(input.value || '').trim() : '';
  }

  function languageFor(row) {
    const parts = String(row && row.getAttribute('data-key') || '').split('|');
    const lang = parts.length >= 3 ? String(parts[parts.length - 1] || '').trim() : '';
    return (lang || 'EN').toUpperCase();
  }

  function savedStock(input) {
    return normalizeStock(input ? input.getAttribute('data-saved') : 0);
  }

  function isSessionCreated(row) {
    return !!row && row.getAttribute('data-nps-session-created') === '1';
  }

  function shouldOwnStockEvent(row, input) {
    if (!row || !input) return false;
    if (isSessionCreated(row)) return true;
    const postId = String(row.getAttribute('data-post') || '').trim();
    return postId === '' && normalizeStock(input.value) > 0;
  }

  function enqueue(row, task) {
    const previous = queues.get(row) || Promise.resolve();
    const next = previous.catch(() => {}).then(task);
    queues.set(row, next);
    next.catch(() => {}).finally(() => {
      if (queues.get(row) === next) queues.delete(row);
    });
    return next;
  }

  function updateSavedStock(row, input, stock) {
    const value = String(stock);
    input.value = value;
    input.setAttribute('data-saved', value);
    row.setAttribute('data-stock', value);
  }

  async function deleteSessionProduct(row, input, postId) {
    setFieldMessage(row, 'Fjerner…', 'saving');
    await post(NPS_STOCK_AUTOCREATE.deleteAction, { postId });

    row.setAttribute('data-post', '');
    row.setAttribute('data-status', 'none');
    row.setAttribute('data-stock', '0');
    row.removeAttribute('data-nps-session-created');
    updateSavedStock(row, input, 0);
    setBadge(row, 'Findes ikke', 'none');
    setFieldMessage(row, 'Fjernet ✓', 'ok');
    setTimeout(() => {
      if (String(row.getAttribute('data-post') || '') === '') setFieldMessage(row, '');
    }, 1200);
  }

  async function persistStock(row, input, desired) {
    desired = normalizeStock(desired);
    const initialPostId = String(row.getAttribute('data-post') || '').trim();
    const sessionCreated = isSessionCreated(row);

    if (initialPostId && !sessionCreated) return;
    if (initialPostId && desired === savedStock(input)) return;
    if (!initialPostId && desired <= 0) {
      updateSavedStock(row, input, 0);
      setFieldMessage(row, '');
      return;
    }

    markSelected(row);

    try {
      let postId = initialPostId;
      let createdNow = false;

      if (!postId) {
        const setId = currentSetId();
        const cardId = String(row.getAttribute('data-card') || '').trim();
        const finish = String(row.getAttribute('data-finish') || '').trim();
        if (!setId || !cardId || !finish) {
          throw new Error('Kunne ikke bestemme set, kort eller finish.');
        }

        setFieldMessage(row, 'Opretter…', 'saving');
        const created = await post(NPS_STOCK_AUTOCREATE.createAction, {
          setId,
          cardId,
          finish,
          lang: languageFor(row),
        });

        postId = String(created && created.post_id ? created.post_id : '').trim();
        if (!postId) throw new Error('Varen blev ikke oprettet.');

        row.setAttribute('data-post', postId);
        row.setAttribute('data-status', 'draft');
        row.setAttribute('data-nps-session-created', '1');
        setBadge(row, 'Kladde', 'warn');
        createdNow = true;
      }

      if (desired <= 0 && isSessionCreated(row)) {
        await deleteSessionProduct(row, input, postId);
        return;
      }

      setFieldMessage(row, createdNow ? 'Sætter lager…' : 'Gemmer…', 'saving');
      const result = await post(NPS_STOCK_AUTOCREATE.setStockAction, {
        postId,
        stock: desired,
      });
      const stock = normalizeStock(result && result.stock !== undefined ? result.stock : desired);
      updateSavedStock(row, input, stock);
      setFieldMessage(row, createdNow ? 'Oprettet ✓' : 'Gemt ✓', 'ok');
      setTimeout(() => {
        if (normalizeStock(input.value) === savedStock(input)) setFieldMessage(row, '');
      }, 1200);
    } catch (error) {
      setFieldMessage(row, error && error.message ? error.message : 'Fejl', 'err');
    }
  }

  document.addEventListener('blur', event => {
    const input = event.target && event.target.matches && event.target.matches('input[data-field="stock"]') ? event.target : null;
    if (!input) return;
    const row = rowFor(input);
    if (!shouldOwnStockEvent(row, input)) return;

    event.stopImmediatePropagation();
    const desired = normalizeStock(input.value);
    void enqueue(row, () => persistStock(row, input, desired));
  }, true);

  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    const input = event.target && event.target.matches && event.target.matches('input[data-field="stock"]') ? event.target : null;
    if (!input) return;
    const row = rowFor(input);
    if (!shouldOwnStockEvent(row, input)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const desired = normalizeStock(input.value);
    void enqueue(row, () => persistStock(row, input, desired));
  }, true);

  document.addEventListener('click', event => {
    const button = event.target && event.target.closest ? event.target.closest('button[data-step][data-key]') : null;
    if (!button) return;
    const row = rowFor(button);
    if (!row) return;

    const postId = String(row.getAttribute('data-post') || '').trim();
    if (postId && !isSessionCreated(row)) return;

    const input = stockInput(row);
    if (!input) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const delta = parseInt(String(button.getAttribute('data-step') || '0'), 10) || 0;
    const desired = Math.max(0, normalizeStock(input.value) + delta);
    input.value = String(desired);
    markSelected(row);
    setFieldMessage(row, '…', 'dirty');
    void enqueue(row, () => persistStock(row, input, desired));
  }, true);
})();
