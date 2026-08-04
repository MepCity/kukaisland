(() => {
  "use strict";
	const $ = window.jQuery;
	const syncNativeSizes = (form) => {
	  const color = form.querySelector('[name="attribute_pa_renk"]')?.value;
	  const size = form.querySelector('[name="attribute_pa_beden"]');
	  const variations = window.kukaIslandProduct?.availability;
	  if (!color || !size || !Array.isArray(variations)) return;
	  [...size.options].forEach((option) => {
	    if (!option.value) return;
	    option.dataset.kukaLabel ||= option.textContent.replace(/ \([^)]*\)$/, "");
	    const match = variations.find((item) => item.color === color && item.size === option.value);
	    const unavailable = !match || !match.available;
	    option.disabled = unavailable;
	    option.textContent = unavailable ? `${option.dataset.kukaLabel} (${window.kukaIslandProduct?.soldOut || "—"})` : option.dataset.kukaLabel;
	  });
	};
	const syncStockNote = (form) => {
	  const color = form.querySelector('[name="attribute_pa_renk"]')?.value;
	  const size = form.querySelector('[name="attribute_pa_beden"]')?.value;
	  const match = window.kukaIslandProduct?.availability?.find(item => item.color === color && item.size === size);
	  const container = form.querySelector(".woocommerce-variation-availability");
	  if (!container) return;
	  container.querySelector(".kuka-low-stock")?.remove();
	  if (match?.available && Number.isFinite(match.stock) && match.stock > 0 && match.stock <= 2) {
	    const note = document.createElement("p"); note.className = "kuka-low-stock";
	    note.textContent = (window.kukaIslandProduct?.lowStock || "%d").replace("%d", match.stock); container.append(note);
	  }
	};
	document.addEventListener("change", (event) => {
	  const form = event.target.closest?.("form.variations_form");
	  if (!form || !event.target.matches("select")) return;
	  syncNativeSizes(form); window.setTimeout(() => syncStockNote(form), 300);
	});
	if (!$) return;
  $(document).on("found_variation", "form.variations_form", (_event, variation) => {
    const container = document.querySelector(".woocommerce-variation-availability");
    if (!container) return;
    container.querySelector(".kuka-low-stock")?.remove();
    if (variation.is_in_stock && Number.isFinite(variation.max_qty) && variation.max_qty > 0 && variation.max_qty <= 2) {
      const template = window.kukaIslandProduct?.lowStock || "%d";
      const note = document.createElement("p"); note.className = "kuka-low-stock"; note.textContent = template.replace("%d", variation.max_qty);
      container.append(note);
    }
  });
})();
