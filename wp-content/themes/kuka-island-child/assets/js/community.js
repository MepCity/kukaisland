(() => {
  const root = document.querySelector('[data-community]');
  if (!root) return;
  const rail = root.querySelector('.kuka-community__rail');
  const cards = [...rail.children];
  const controls = root.querySelector('[data-rail-controls]');
  const prev = root.querySelector('[data-rail-prev]');
  const next = root.querySelector('[data-rail-next]');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const sync = () => {
    const end = rail.scrollWidth - rail.clientWidth;
    controls.hidden = end < 2;
    prev.disabled = rail.scrollLeft < 2;
    next.disabled = rail.scrollLeft >= end - 2;
  };
  const step = direction => rail.scrollBy({ left: direction * (cards[1] ? cards[1].offsetLeft - cards[0].offsetLeft : rail.clientWidth), behavior: reduced.matches ? 'instant' : 'smooth' });
  prev.addEventListener('click', () => step(-1)); next.addEventListener('click', () => step(1));
  rail.addEventListener('scroll', sync, { passive: true });
  window.addEventListener('resize', sync, { passive: true });
  sync();
  if ('IntersectionObserver' in window && !reduced.matches) {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-entering'); observer.unobserve(entry.target);
    }), { threshold: 0.15 });
    cards.forEach(card => observer.observe(card));
  }
})();
