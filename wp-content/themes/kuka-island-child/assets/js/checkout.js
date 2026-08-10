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
  const requiredMessage = window.kukaIslandCheckout?.required || 'This field is required.';
  const phone = form.querySelector('#billing_phone');
  let scheduled = false;

  const phoneDigits = (value) => {
    let digits = value.replace(/\D/g, '');
    if (digits.length >= 12 && digits.startsWith('90')) digits = digits.slice(2);
    else if (digits.length >= 11 && digits.startsWith('0')) digits = digits.slice(1);
    if (digits && !digits.startsWith('5')) return '5';
    return digits.slice(0, 10);
  };

  const formatPhone = (value) => {
    const digits = phoneDigits(value);
    return [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 8), digits.slice(8, 10)].filter(Boolean).join(' ');
  };

  if (phone) {
    phone.value = formatPhone(phone.value);
    phone.addEventListener('focus', () => {
      if (!phone.value) phone.value = '5';
    });
    phone.addEventListener('input', () => {
      phone.value = formatPhone(phone.value) || (document.activeElement === phone ? '5' : '');
    });
  }

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

  const fieldWrapper = (field) => field.closest('.form-row, .kuka-legal-consent');

  const describedBy = (field) => (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);

  const fieldIsComplete = (field) => {
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked;
    return field.value.trim() !== '' && (!field.validity || field.validity.valid);
  };

  const fieldIsMissing = (field) => {
    if (field.type === 'checkbox' || field.type === 'radio') return !field.checked;
    return field.value.trim() === '';
  };

  const fieldIsRequired = (field) => field.required || fieldWrapper(field)?.classList.contains('validate-required');

  const clearFieldError = (field) => {
    const wrapper = fieldWrapper(field);
    const errors = wrapper ? Array.from(wrapper.querySelectorAll('.kuka-field-error, .checkout-inline-error-message')) : [];
    const errorIds = errors.map((error) => error.id).filter(Boolean);
    wrapper?.classList.remove('kuka-field-invalid', 'woocommerce-invalid', 'woocommerce-invalid-required-field');
    errors.forEach((error) => error.remove());
    field.removeAttribute('aria-invalid');
    const descriptions = describedBy(field).filter((id) => !errorIds.includes(id));
    if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
    else field.removeAttribute('aria-describedby');
  };

  const markFieldError = (field, message = requiredMessage) => {
    const wrapper = fieldWrapper(field);
    if (!wrapper || !field.id) return;
    const fallbackId = `${field.id}_error`;
    const nativeError = wrapper.querySelector('.checkout-inline-error-message');
    const errorId = nativeError?.id || fallbackId;
    wrapper.classList.add('kuka-field-invalid');
    field.setAttribute('aria-invalid', 'true');
    const descriptions = new Set([...describedBy(field).filter((id) => id !== fallbackId || errorId === fallbackId), errorId]);
    field.setAttribute('aria-describedby', Array.from(descriptions).join(' '));
    let error = nativeError || wrapper.querySelector(`#${CSS.escape(errorId)}`);
    if (nativeError && errorId !== fallbackId) wrapper.querySelector(`#${CSS.escape(fallbackId)}`)?.remove();
    if (!error) {
      error = document.createElement('span');
      error.className = 'kuka-field-error';
      error.id = errorId;
      wrapper.append(error);
    }
    error.classList.add('kuka-field-error');
    if (error.textContent !== message) error.textContent = message;
  };

  const markSummaryFields = () => {
    inner.querySelectorAll('.woocommerce-error li[data-id]').forEach((item) => {
      const field = form.querySelector(`#${CSS.escape(item.dataset.id || '')}`);
      if (!field) return;
      if (fieldIsRequired(field) && fieldIsComplete(field)) return;
      const missingRequired = fieldIsRequired(field) && fieldIsMissing(field);
      markFieldError(field, missingRequired ? requiredMessage : item.textContent.trim());
    });
  };

  const normalizeNativeFieldErrors = () => {
    form.querySelectorAll('.checkout-inline-error-message').forEach((error) => {
      const wrapper = error.closest('.form-row');
      const field = wrapper?.querySelector('input, select, textarea');
      if (!field || !field.matches('[aria-invalid="true"]')) return;
      markFieldError(field, fieldIsRequired(field) && fieldIsMissing(field) ? requiredMessage : error.textContent.trim());
    });
  };

  const removeMappedSummaryErrors = () => {
    inner.querySelectorAll('.woocommerce-error li[data-id]').forEach((item) => {
      if (form.querySelector(`#${CSS.escape(item.dataset.id || '')}`)) item.remove();
    });
    inner.querySelectorAll('.woocommerce-error').forEach((notice) => {
      if (!notice.querySelector('li')) notice.remove();
    });
    inner.querySelectorAll('.woocommerce-NoticeGroup, .woocommerce-NoticeGroup-checkout').forEach((group) => {
      if (!group.querySelector(noticeSelector)) group.remove();
    });
  };

  const firstInvalidField = () => {
    return form.querySelector('[aria-invalid="true"]');
  };

  const bringRegionIntoView = () => {
    const bounds = region.getBoundingClientRect();
    if (bounds.top >= 0 && bounds.bottom <= window.innerHeight) return;
    region.scrollIntoView({behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'start'});
  };

  const reveal = ({focus = false, scroll = false} = {}) => {
    topLevelNotices().forEach(ensureAnnouncement);
    enhanceLinks();
    markSummaryFields();
    normalizeNativeFieldErrors();
    removeMappedSummaryErrors();
    const visible = topLevelNotices().length > 0;
    region.classList.toggle('is-visible', visible);
    if (!visible) {
      const invalid = firstInvalidField();
      if (focus) invalid?.focus({preventScroll: true});
      if (scroll) invalid?.scrollIntoView({behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'center'});
      return;
    }
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
    form.querySelectorAll('[required]').forEach((field) => {
      if (!field.disabled && fieldWrapper(field)?.offsetParent !== null && !fieldIsComplete(field)) markFieldError(field);
    });
  }, {capture: true});

  const clearCompletedField = (event) => {
    const field = event.target.closest('input, select, textarea');
    if (field?.matches('[aria-invalid="true"]') && fieldIsComplete(field)) clearFieldError(field);
  };
  form.addEventListener('input', clearCompletedField);
  form.addEventListener('change', clearCompletedField);

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
