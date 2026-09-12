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
        existing.textContent = label;
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

  const observer = new MutationObserver(queueRefresh);
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
