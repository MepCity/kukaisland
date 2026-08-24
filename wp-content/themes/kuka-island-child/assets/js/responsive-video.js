(() => {
  'use strict';

  const videos = [...document.querySelectorAll('[data-responsive-video]')];
  if (!videos.length || !window.matchMedia) return;

  const mobile = window.matchMedia('(max-width: 47.5em)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

  const unload = (video) => {
    video.pause();
    if (!video.hasAttribute('src')) return;
    video.removeAttribute('src');
    video.load();
  };

  const synchronize = () => {
    videos.forEach((video) => {
      const mode = mobile.matches ? 'mobile' : 'desktop';
      const poster = video.dataset[`${mode}Poster`];
      if (poster && video.poster !== poster) video.poster = poster;

      if (reducedMotion.matches || connection?.saveData) {
        unload(video);
        return;
      }

      const source = video.dataset[`${mode}Src`];
      if (!source) return;
      if (video.getAttribute('src') !== source) {
        video.src = source;
        video.load();
      }
      video.play().catch(() => {});
    });
  };

  synchronize();
  mobile.addEventListener?.('change', synchronize);
  reducedMotion.addEventListener?.('change', synchronize);
  connection?.addEventListener?.('change', synchronize);
})();
