(() => {
  'use strict';

  const videos = [...document.querySelectorAll('[data-responsive-video]')];
  if (!videos.length || !window.matchMedia) return;

  const mobile = window.matchMedia('(max-width: 47.5em)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

  // 2G/3G sınıfı bağlantıda video hiç indirilmez, poster kalır. effectiveType
  // bilinmiyorsa (Safari, Firefox) bağlantı hızlı sayılır ve video oynar.
  const slowConnection = () => {
    const type = connection?.effectiveType;
    return typeof type === 'string' && type !== '4g';
  };
  const posterOnly = () => reducedMotion.matches || Boolean(connection?.saveData) || slowConnection();
  const mode = () => (mobile.matches ? 'mobile' : 'desktop');

  const unload = (video) => {
    video.pause();
    if (!video.hasAttribute('src')) return;
    video.removeAttribute('src');
    video.load();
  };

  // Poster her zaman anında viewport'a uyar: ilk ekranın en büyük öğesi
  // fotoğraftır, video değil.
  const synchronizePosters = () => {
    videos.forEach((video) => {
      const poster = video.dataset[`${mode()}Poster`];
      if (poster && video.poster !== poster) video.poster = poster;
    });
  };

  const synchronize = () => {
    synchronizePosters();
    videos.forEach((video) => {
      if (posterOnly()) {
        unload(video);
        return;
      }
      const source = video.dataset[`${mode()}Src`];
      if (!source) return;
      if (video.getAttribute('src') !== source) {
        video.src = source;
        video.load();
      }
      video.play().catch(() => {});
    });
  };

  // Video, sayfanın kalanı yüklendikten sonra ve tarayıcı boştayken başlar;
  // hero fotoğrafı ve yazılarla bant genişliği paylaşmaz. Hızlı bağlantıda
  // fark 1–2 saniyedir; poster videonun ilk karesi olduğu için geçiş görünmez.
  let started = false;
  const start = () => {
    if (started) return;
    started = true;
    if (window.requestIdleCallback) window.requestIdleCallback(synchronize, { timeout: 2500 });
    else window.setTimeout(synchronize, 250);
  };

  synchronizePosters();
  if (document.readyState === 'complete') start();
  else window.addEventListener('load', start, { once: true });

  const resynchronize = () => {
    if (started) synchronize();
    else synchronizePosters();
  };
  mobile.addEventListener?.('change', resynchronize);
  reducedMotion.addEventListener?.('change', resynchronize);
  connection?.addEventListener?.('change', resynchronize);
})();
