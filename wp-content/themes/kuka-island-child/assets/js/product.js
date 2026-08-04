(() => {
  "use strict";

  const config = window.kukaIslandProduct || {};
  const form = document.querySelector("form.variations_form");
  const gallery = document.querySelector("[data-product-gallery]");
  const colorSelect = form?.querySelector('[name="attribute_pa_renk"]');
  const sizeSelect = form?.querySelector('[name="attribute_pa_beden"]');

  const dispatchChange = (select) => {
    select.dispatchEvent(new Event("change", { bubbles: true }));
    window.jQuery?.(select).trigger("change");
  };

  const radioKeys = (event, buttons) => {
    if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
    event.preventDefault();
    const enabled = buttons.filter((button) => !button.disabled);
    const current = enabled.indexOf(event.currentTarget);
    const direction = ["ArrowRight", "ArrowDown"].includes(event.key) ? 1 : -1;
    const next = enabled[(current + direction + enabled.length) % enabled.length];
    next?.focus();
    next?.click();
  };

  const galleryItems = () => [...document.querySelectorAll("[data-gallery-item]")];
  const updateGalleryCounter = () => {
    const track = document.querySelector("[data-gallery-track]");
    const items = galleryItems();
    if (!track || !items.length) return;
    const index = Math.round(track.scrollLeft / Math.max(track.clientWidth, 1));
    const counter = document.querySelector("[data-gallery-counter]");
    if (counter) counter.textContent = `${Math.min(index + 1, items.length)} / ${items.length}`;
  };

  const renderGallery = (items) => {
    const track = document.querySelector("[data-gallery-track]");
    if (!track || !items?.length) return;
    track.replaceChildren(...items.map((item, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "kuka-product-gallery__item";
      button.dataset.galleryItem = "";
      button.dataset.galleryIndex = String(index);
      button.dataset.full = item.full;
	  button.dataset.panelTrigger = "kuka-product-lightbox";
	  button.setAttribute("aria-controls", "kuka-product-lightbox");
	  button.setAttribute("aria-expanded", "false");
      button.setAttribute("aria-label", `${item.alt}; tam ekran aç (${index + 1}/${items.length})`);
      const image = document.createElement("img");
      image.src = item.src;
      image.alt = item.alt;
      image.loading = index ? "lazy" : "eager";
      button.append(image);
      return button;
    }));
    updateGalleryCounter();
  };

  const buildVariationControls = () => {
    if (!form || !colorSelect || !sizeSelect) return;
    form.classList.add("kuka-variation-enhanced");

    const colorGroup = document.createElement("div");
    colorGroup.className = "kuka-product-swatches";
    colorGroup.setAttribute("role", "radiogroup");
    colorGroup.setAttribute("aria-label", "Renk seçimi");
    const productColors = config.colors?.filter((color) => [...colorSelect.options].some((option) => option.value === color.slug)) || [];
    productColors.forEach((color, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.role = "radio";
      button.dataset.color = color.slug;
      button.style.setProperty("--swatch-color", color.hex);
      button.setAttribute("aria-label", `${color.name} rengini seç`);
      button.setAttribute("aria-checked", "false");
      button.tabIndex = index ? -1 : 0;
      button.addEventListener("click", () => {
        colorSelect.value = color.slug;
        dispatchChange(colorSelect);
        syncControls();
        const selectedGallery = config.availability?.find((item) => item.color === color.slug && item.gallery?.length)?.gallery;
        renderGallery(selectedGallery);
      });
      colorGroup.append(button);
    });
    colorGroup.querySelectorAll("button").forEach((button) => button.addEventListener("keydown", (event) => radioKeys(event, [...colorGroup.querySelectorAll("button")])));

    const sizeGroup = document.createElement("div");
    sizeGroup.className = "kuka-product-sizes";
    sizeGroup.setAttribute("role", "radiogroup");
    sizeGroup.setAttribute("aria-label", "Beden seçimi");
    [...sizeSelect.options].filter((option) => option.value).forEach((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.role = "radio";
      button.dataset.size = option.value;
      button.textContent = option.dataset.kukaLabel || option.textContent.replace(/ \([^)]*\)$/, "");
      button.setAttribute("aria-checked", "false");
      button.addEventListener("click", () => {
        sizeSelect.value = option.value;
        dispatchChange(sizeSelect);
        syncControls();
      });
      sizeGroup.append(button);
    });
    sizeGroup.querySelectorAll("button").forEach((button) => button.addEventListener("keydown", (event) => radioKeys(event, [...sizeGroup.querySelectorAll("button")])));

    colorSelect.closest("td")?.append(colorGroup);
    sizeSelect.closest("td")?.append(sizeGroup);

    function syncControls() {
      const color = colorSelect.value;
      colorGroup.querySelectorAll("button").forEach((button) => {
        const selected = button.dataset.color === color;
        button.setAttribute("aria-checked", String(selected));
        button.classList.toggle("is-selected", selected);
        button.tabIndex = selected || (!color && button === colorGroup.querySelector("button")) ? 0 : -1;
      });
      sizeGroup.querySelectorAll("button").forEach((button) => {
        const match = config.availability?.find((item) => item.color === color && item.size === button.dataset.size);
        const unavailable = Boolean(color) && (!match || !match.available);
        button.disabled = unavailable;
        button.setAttribute("aria-disabled", String(unavailable));
        button.setAttribute("aria-label", unavailable ? `${button.textContent} beden tükendi` : `${button.textContent} beden stokta`);
        const selected = sizeSelect.value === button.dataset.size;
        button.setAttribute("aria-checked", String(selected));
        button.classList.toggle("is-selected", selected);
        button.tabIndex = selected ? 0 : -1;
      });
      if (!sizeSelect.value) {
        const firstAvailableSize = sizeGroup.querySelector("button:not(:disabled)");
        if (firstAvailableSize) firstAvailableSize.tabIndex = 0;
      }
    }
    colorSelect.addEventListener("change", syncControls);
    sizeSelect.addEventListener("change", syncControls);
    syncControls();
  };

  buildVariationControls();

  gallery?.addEventListener("scroll", updateGalleryCounter, { passive: true, capture: true });
  document.querySelector("[data-gallery-previous]")?.addEventListener("click", () => document.querySelector("[data-gallery-track]")?.scrollBy({ left: -document.querySelector("[data-gallery-track]").clientWidth, behavior: "smooth" }));
  document.querySelector("[data-gallery-next]")?.addEventListener("click", () => document.querySelector("[data-gallery-track]")?.scrollBy({ left: document.querySelector("[data-gallery-track]").clientWidth, behavior: "smooth" }));

  const lightbox = document.querySelector(".kuka-product-lightbox");
  const viewport = lightbox?.querySelector("[data-lightbox-viewport]");
  const host = lightbox?.querySelector("[data-lightbox-image-host]");
  let lightboxIndex = 0;
  let scale = 1;
  let translateX = 0;
  let translateY = 0;
  const pointers = new Map();
  let pinchDistance = 0;

  const applyTransform = () => {
    const image = host?.querySelector("img");
    if (!image) return;
    image.style.setProperty("--zoom-scale", String(scale));
    image.style.setProperty("--zoom-x", `${translateX}%`);
    image.style.setProperty("--zoom-y", `${translateY}%`);
    viewport?.classList.toggle("is-zoomed", scale > 1);
    lightbox?.querySelector("[data-lightbox-zoom]")?.setAttribute("aria-pressed", String(scale > 1));
  };
  const resetZoom = () => { scale = 1; translateX = 0; translateY = 0; applyTransform(); };
  const showLightboxImage = (index) => {
    const items = galleryItems();
    if (!host || !items.length) return;
    lightboxIndex = (index + items.length) % items.length;
    const item = items[lightboxIndex];
    const image = document.createElement("img");
    image.src = item.dataset.full;
    image.alt = item.querySelector("img")?.alt || "";
    image.draggable = false;
    host.replaceChildren(image);
    const counter = lightbox.querySelector("[data-lightbox-counter]");
    if (counter) counter.textContent = `${lightboxIndex + 1} / ${items.length}`;
    resetZoom();
  };
  document.addEventListener("click", (event) => {
    if (event.target.closest("[data-lightbox-previous]")) showLightboxImage(lightboxIndex - 1);
    if (event.target.closest("[data-lightbox-next]")) showLightboxImage(lightboxIndex + 1);
    if (event.target.closest("[data-lightbox-zoom]")) { scale = scale > 1 ? 1 : 2; applyTransform(); }
  });
	document.addEventListener("kuka:panel-opened", (event) => {
	  if (event.detail?.panel !== lightbox) return;
	  showLightboxImage(Number(event.detail.trigger?.dataset.galleryIndex || 0));
	});
	document.addEventListener("kuka:panel-closed", (event) => {
	  if (event.detail?.panel === lightbox) host?.replaceChildren();
	});
  document.addEventListener("keydown", (event) => {
    if (!lightbox?.classList.contains("is-open")) return;
    if (event.key === "ArrowLeft") showLightboxImage(lightboxIndex - 1);
    if (event.key === "ArrowRight") showLightboxImage(lightboxIndex + 1);
    if (event.key === "+" || event.key === "=") { scale = Math.min(4, scale + 0.5); applyTransform(); }
    if (event.key === "-") { scale = Math.max(1, scale - 0.5); applyTransform(); }
  });
  viewport?.addEventListener("wheel", (event) => {
    event.preventDefault();
    scale = Math.max(1, Math.min(4, scale + (event.deltaY < 0 ? 0.25 : -0.25)));
    applyTransform();
  }, { passive: false });
  viewport?.addEventListener("pointerdown", (event) => { viewport.setPointerCapture(event.pointerId); pointers.set(event.pointerId, event); });
  viewport?.addEventListener("pointermove", (event) => {
    if (!pointers.has(event.pointerId)) return;
    const previous = pointers.get(event.pointerId);
    pointers.set(event.pointerId, event);
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      const distance = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
      if (pinchDistance) scale = Math.max(1, Math.min(4, scale * distance / pinchDistance));
      pinchDistance = distance;
    } else if (scale > 1) {
      translateX += (event.clientX - previous.clientX) / 5;
      translateY += (event.clientY - previous.clientY) / 5;
    }
    applyTransform();
  });
  const releasePointer = (event) => { pointers.delete(event.pointerId); if (pointers.size < 2) pinchDistance = 0; };
  viewport?.addEventListener("pointerup", releasePointer);
  viewport?.addEventListener("pointercancel", releasePointer);
})();
