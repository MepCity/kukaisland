(() => {
  'use strict';

  const form = document.querySelector('form.checkout.kuka-checkout__grid');
  const region = form?.querySelector('[data-checkout-notices]');
  const inner = region?.querySelector('[data-checkout-notices-inner]');
  if (!form || !region || !inner) return;

  const noticeSelector = [
    '.woocommerce-NoticeGroup',
    '.woocommerce-NoticeGroup-checkout',
    '.woocommerce-error',
    '.woocommerce-message',
    '.woocommerce-info',
  ].join(',');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let scheduled = false;

  const topLevelNotices = () => Array.from(inner.children).filter((node) => node.matches?.(noticeSelector));

  const ensureAnnouncement = (notice) => {
    if (notice.matches('[role="alert"], [aria-live]') || notice.querySelector('[role="alert"], [aria-live]')) return;
    notice.setAttribute('role', 'alert');
  };

  const enhanceLinks = () => {
    inner.querySelectorAll('.woocommerce-error li[data-id]').forEach((item) => {
      const id = item.dataset.id;
      if (!id || !form.querySelector(`#${CSS.escape(id)}`)) return;
      let link = item.querySelector('a');
      if (!link) {
        link = document.createElement('a');
        link.append(...item.childNodes);
        item.append(link);
      }
      link.setAttribute('href', `#${id}`);
    });
  };

  const firstInvalidField = () => {
    const item = inner.querySelector('.woocommerce-error li[data-id]');
    return item ? form.querySelector(`#${CSS.escape(item.dataset.id)}`) : null;
  };

  const bringRegionIntoView = () => {
    const bounds = region.getBoundingClientRect();
    if (bounds.top >= 0 && bounds.bottom <= window.innerHeight) return;
    region.scrollIntoView({behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'start'});
  };

  const reveal = ({focus = false, scroll = false} = {}) => {
    topLevelNotices().forEach(ensureAnnouncement);
    enhanceLinks();
    const visible = topLevelNotices().length > 0;
    region.classList.toggle('is-visible', visible);
    if (!visible) return;
    if (scroll) bringRegionIntoView();
    if (focus) firstInvalidField()?.focus({preventScroll: true});
  };

  const synchronize = (options = {}) => {
    form.querySelectorAll(noticeSelector).forEach((notice) => {
      if (notice.closest('[data-checkout-notices-inner]') === inner) return;
      inner.append(notice);
    });
    reveal(options);
  };

  const scheduleSynchronization = () => {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      synchronize({focus: true, scroll: true});
    });
  };

  form.classList.add('kuka-checkout-enhanced');
  synchronize({focus: true, scroll: true});

  new MutationObserver(scheduleSynchronization).observe(form, {childList: true, subtree: true});

  form.addEventListener('submit', () => {
    region.classList.remove('is-visible');
  }, {capture: true});

  inner.addEventListener('click', (event) => {
    const link = event.target.closest('.woocommerce-error li[data-id] a');
    if (!link) return;
    const item = link.closest('li[data-id]');
    const field = item ? form.querySelector(`#${CSS.escape(item.dataset.id)}`) : null;
    if (!field) return;
    event.preventDefault();
    field.focus({preventScroll: true});
    field.scrollIntoView({behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'center'});
  });

  if (window.jQuery) {
    window.jQuery(document.body).on('checkout_error', () => {
      window.jQuery('html, body').stop(true);
      synchronize({focus: true, scroll: true});
    });
  }
})();
