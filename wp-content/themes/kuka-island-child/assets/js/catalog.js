(() => {
  "use strict";

  document.querySelectorAll("[data-product-card]").forEach((card) => {
    const images = [...card.querySelectorAll("[data-card-image]")];
    const status = card.querySelector(".kuka-product-card__nav span");
    let index = 0;
    const show = (next) => {
      if (!images.length) return;
      index = (next + images.length) % images.length;
      images.forEach((image, imageIndex) => image.classList.toggle("is-active", imageIndex === index));
      if (status) status.textContent = `${index + 1} / ${images.length}`;
    };
    card.querySelector("[data-card-previous]")?.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      show(index - 1);
    });
    card.querySelector("[data-card-next]")?.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      show(index + 1);
    });
	card.querySelectorAll("[data-card-swatch]").forEach((swatch) => {
	  swatch.addEventListener("click", (event) => {
		event.preventDefault();
		event.stopPropagation();
		const nextIndex = Number.parseInt(swatch.dataset.imageIndex, 10);
		if (Number.isFinite(nextIndex)) show(nextIndex);
		card.querySelectorAll("[data-card-swatch]").forEach((item) => {
		  const selected = item === swatch;
		  item.classList.toggle("is-selected", selected);
		  item.setAttribute("aria-pressed", String(selected));
		});
		const colorName = card.querySelector("[data-card-color-name]");
		if (colorName) colorName.textContent = swatch.dataset.colorName || "";
		const availability = JSON.parse(swatch.dataset.sizes || "{}");
		card.querySelectorAll("[data-card-size]").forEach((size) => {
		  size.classList.toggle("is-sold-out", !availability[size.dataset.cardSize]);
		});
	  });
	});
    card.addEventListener("mouseenter", () => images.length > 1 && show(1));
    card.addEventListener("mouseleave", () => show(0));
  });

  document.querySelector(".woocommerce-ordering select")?.addEventListener("change", (event) => event.target.form?.submit());
})();
