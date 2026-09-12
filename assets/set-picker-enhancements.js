(function () {
  'use strict';

  let queued = false;

  function hasClassEnding(el, suffix) {
    return !!(el && el.classList && Array.from(el.classList).some(name => name.endsWith(suffix)));
  }

  function findByClassSuffix(root, suffix) {
    if (!root || !root.querySelectorAll) return null;
    return Array.from(root.querySelectorAll('[class]')).find(el => hasClassEnding(el, suffix)) || null;
  }

  function syncEmptyPickerFilter(picker) {
    if (!picker || picker.hidden) return;
    if (!hasClassEnding(picker, '-seriespicker') && !hasClassEnding(picker, '-seriespicker--overlay')) return;

    const filter = picker.querySelector('input[id$="_series_filter"]');
    if (!filter || String(filter.value || '').trim() !== '') return;

    // Game admin bundles normally clear the visible search field when the set
    // picker opens. Some of them keep the previously rendered filtered list as
    // an optimisation, which leaves an empty field showing stale search results.
    // Re-fire the normal input handler so visible state and filter state always
    // agree. Existing secondary filters such as "only created" remain in force.
    filter.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function refresh() {
    queued = false;

    document.querySelectorAll('section[class]').forEach(group => {
      if (!hasClassEnding(group, '-seriesgroup')) return;

      const head = findByClassSuffix(group, '-seriesgroup__head');
      if (!head) return;

      const art = findByClassSuffix(head, '-seriesbanner__art');
      const title = findByClassSuffix(head, '-seriesgroup__title');
      if (!art || !title) return;

      const existing = art.querySelector('[data-nps-series-name-fallback="1"]');
      const hasImage = !!art.querySelector('img[src]:not([src=""])');

      if (hasImage) {
        if (existing) existing.remove();
        art.querySelectorAll('[data-nps-hidden-empty-thumb="1"]').forEach(node => {
          node.hidden = false;
          node.removeAttribute('data-nps-hidden-empty-thumb');
        });
        return;
      }

      const label = String(title.textContent || '').trim();
      if (!label) return;

      art.querySelectorAll('[class]').forEach(node => {
        if (Array.from(node.classList).some(name => name.endsWith('-setthumb--empty'))) {
          node.hidden = true;
          node.setAttribute('data-nps-hidden-empty-thumb', '1');
        }
      });

      if (existing) {
        if (String(existing.textContent || '') !== label) existing.textContent = label;
        return;
      }

      const fallback = document.createElement('span');
      fallback.setAttribute('data-nps-series-name-fallback', '1');
      fallback.textContent = label;
      fallback.style.display = 'block';
      fallback.style.width = '100%';
      fallback.style.padding = '14px 18px';
      fallback.style.boxSizing = 'border-box';
      fallback.style.textAlign = 'center';
      fallback.style.fontSize = '22px';
      fallback.style.fontWeight = '600';
      fallback.style.lineHeight = '1.25';
      fallback.style.color = '#1d2327';
      fallback.style.overflowWrap = 'anywhere';
      art.appendChild(fallback);
    });
  }

  function queueRefresh() {
    if (queued) return;
    queued = true;
    window.requestAnimationFrame(refresh);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', queueRefresh, { once: true });
  } else {
    queueRefresh();
  }

  const observer = new MutationObserver(records => {
    records.forEach(record => {
      if (record.type === 'attributes' && record.attributeName === 'hidden' && !record.target.hidden) {
        // Let the game bundle finish clearing/focusing its search field first.
        window.setTimeout(() => syncEmptyPickerFilter(record.target), 0);
      }
    });
    queueRefresh();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['hidden'],
  });
})();
