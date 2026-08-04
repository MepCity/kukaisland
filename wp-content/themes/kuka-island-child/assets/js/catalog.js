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
    card.addEventListener("mouseenter", () => images.length > 1 && show(1));
    card.addEventListener("mouseleave", () => show(0));
  });

  document.querySelector(".woocommerce-ordering select")?.addEventListener("change", (event) => event.target.form?.submit());
})();
