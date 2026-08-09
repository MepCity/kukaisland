(() => {
  "use strict";

  const story = document.querySelector("[data-kuka-story]");
  if (!story) return;

  const media = [...story.querySelectorAll(".kuka-story__media-item")];
  const scenes = [...story.querySelectorAll("[data-story-scene]")];
  const steps = [...story.querySelectorAll("[data-story-step]")];
  const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
  let observer = null;
  let requestedMediaIndex = 0;

  const hydrate = (root) => {
    root.querySelectorAll("[data-story-src]").forEach((image) => {
      image.src = image.dataset.storySrc;
      image.removeAttribute("data-story-src");
    });
    root.querySelectorAll("[data-story-srcset]").forEach((image) => {
      image.srcset = image.dataset.storySrcset;
      image.removeAttribute("data-story-srcset");
    });
  };

  const activateMedia = (index) => {
    const item = media[index];
    if (!item) return;
    requestedMediaIndex = index;
    hydrate(item);
    const image = item.querySelector("img");
    const reveal = () => {
      if (requestedMediaIndex !== index) return;
      media.forEach((mediaItem, mediaIndex) => mediaItem.classList.toggle("is-active", mediaIndex === index));
    };
    if (index === 0 || (image?.complete && image.naturalWidth > 0)) {
      reveal();
      return;
    }
    image?.addEventListener("load", reveal, { once: true });
  };

  const activate = (index) => {
    scenes.forEach((scene, sceneIndex) => scene.classList.toggle("is-active", sceneIndex === index));
    activateMedia(index);
    // Do not add a second image to the opening request set. Once the visitor
    // starts moving through the story, warm the next scene so the original
    // horizon image is ready before the closing copy takes over.
    if (index > 0 && media[index + 1]) hydrate(media[index + 1]);
  };

  const stop = () => {
    if (observer) observer.disconnect();
    observer = null;
    story.removeAttribute("data-enhanced");
	story.removeAttribute("data-story-ready");
    scenes.forEach((scene) => scene.classList.remove("is-active"));
    media.forEach((item) => item.classList.remove("is-active"));
    story.querySelectorAll(".kuka-story__article-image").forEach(hydrate);
  };

  const start = () => {
    if (motion.matches || observer || !steps.length) {
      if (motion.matches) stop();
      return;
    }
    story.dataset.enhanced = "true";
    activate(0);
	window.requestAnimationFrame(() => { story.dataset.storyReady = "true"; });
    observer = new IntersectionObserver((entries) => {
      const centered = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (centered) activate(Number(centered.target.dataset.storyStep));
    }, { rootMargin: "-45% 0% -45% 0%", threshold: 0 });
    steps.forEach((step) => observer.observe(step));
  };

  scenes.forEach((scene, index) => scene.addEventListener("focusin", () => activate(index)));
  motion.addEventListener("change", start);
  window.addEventListener("pagehide", () => observer?.disconnect(), { once: true });
  start();
})();
