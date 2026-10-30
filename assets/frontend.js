/* No network requests or analytics. Sticky dismissal stays on this browser. */
(() => {
  'use strict';
  const banners = [...document.querySelectorAll('[data-bap-id]')];
  const ids = new Set();
  banners.forEach(banner => {
    if (ids.has(banner.dataset.bapId)) banner.remove();
    ids.add(banner.dataset.bapId);
  });
  const expired = banner => Number(banner.dataset.bapEnd) > 0 && Date.now() >= Number(banner.dataset.bapEnd) * 1000;
  const expire = () => banners.forEach(banner => { if (expired(banner)) banner.hidden = true; });
  expire();
  // Recheck cached end dates and tabs left open overnight.
  window.setInterval(expire, 30000);
  document.addEventListener('visibilitychange', expire);
  const sticky = document.querySelector('.bap-sticky');
  if (!sticky) return;
  const space = document.createElement('div');
  space.id = 'bap-sticky-space';
  space.setAttribute('aria-hidden', 'true');
  document.body.append(space);
  const key = `banner-studio-dismiss-${sticky.dataset.bapId}`;
  const hours = Number(sticky.dataset.bapDismiss);
  const store = () => hours ? window.localStorage : window.sessionStorage;
  let dismissed = false;
  try {
    const until = Number(store().getItem(key));
    dismissed = until > Date.now();
    if (!dismissed) store().removeItem(key);
  } catch (_) { /* Storage can be blocked. The close button still works. */ }
  const mobile = window.matchMedia('(max-width: 767px)');
  const updateSpace = () => {
    const offset = parseFloat(getComputedStyle(sticky).bottom) || 0;
    space.style.setProperty('--bap-sticky-space', sticky.hidden ? '0px' : `${Math.ceil(sticky.getBoundingClientRect().height + offset)}px`);
  };
  const update = () => {
    const focus = document.activeElement;
    const editing = focus && (focus.matches('input,textarea,select') || focus.isContentEditable);
    const overlay = document.documentElement.dataset.bapOverlay === 'true';
    sticky.hidden = dismissed || expired(sticky) || !mobile.matches || editing || overlay || window.scrollY < 160 || document.documentElement.scrollHeight < window.innerHeight * 2;
    updateSpace();
  };
  sticky.querySelector('.bap-close').addEventListener('click', () => {
    dismissed = true;
    try { store().setItem(key, String(hours ? Date.now() + hours * 3600000 : Number.MAX_SAFE_INTEGER)); } catch (_) {}
    update();
  });
  let queued = false;
  const schedule = () => {
    if (queued) return;
    queued = true;
    window.requestAnimationFrame(() => { queued = false; update(); });
  };
  window.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule);
  document.addEventListener('focusin', schedule);
  document.addEventListener('focusout', schedule);
  document.addEventListener('visibilitychange', schedule);
  window.addEventListener('storage', event => {
    if (event.key === key) { dismissed = Number(event.newValue) > Date.now(); schedule(); }
  });
  new MutationObserver(schedule).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bap-overlay'] });
  if ('ResizeObserver' in window) new ResizeObserver(updateSpace).observe(sticky);
  window.setInterval(update, 30000);
  update();
})();
