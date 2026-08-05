(() => {
  "use strict";
  // Progressive enhancement: CSS keeps corporate fields visible until this
  // script has successfully started, so checkout remains usable without JS.
  document.body.classList.add("kuka-checkout-enhanced");
  const refreshFragments = () => {
    if (window.jQuery) window.jQuery(document.body).trigger("wc_fragment_refresh");
  };
  let cartUpdateController = null;

  const submitCartUpdate = async (form) => {
    cartUpdateController?.abort();
    cartUpdateController = new AbortController();
    const { signal } = cartUpdateController;
    const content = document.querySelector("#kuka-cart-panel-content");
    content?.setAttribute("aria-busy", "true");
    try {
      const response = await window.fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        signal,
      });
      if (!response.ok) throw new Error("cart-update-failed");
      refreshFragments();
    } catch (error) {
      if (error.name === "AbortError") return;
      window.location.assign(form.action);
    }
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-kuka-quantity-step],[data-kuka-cart-remove]");
    if (!button) return;
    const form = button.closest("[data-kuka-cart-update]");
    const input = form?.querySelector('input[type="number"]');
    if (!form || !input) return;
    event.preventDefault();
    const current = Number.parseInt(input.value, 10) || 0;
    const maximum = input.max ? Number.parseInt(input.max, 10) : Number.POSITIVE_INFINITY;
    input.value = button.matches("[data-kuka-cart-remove]")
      ? "0"
      : String(Math.max(0, Math.min(maximum, current + Number(button.dataset.kukaQuantityStep))));
    submitCartUpdate(form);
  });

  document.addEventListener("change", (event) => {
    const input = event.target.closest("[data-kuka-cart-update] input[type='number']");
    if (input) submitCartUpdate(input.form);
  });

  const syncCheckout = () => {
    const business = document.querySelector("#billing_customer_type");
    document.body.classList.toggle("kuka-corporate", business?.value === "corporate");
    const checks = [...document.querySelectorAll(".kuka-legal-consents input[required]")];
    const button = document.querySelector("#place_order");
    if (button && checks.length) button.disabled = !checks.every((check) => check.checked);
  };
  document.addEventListener("change", (event) => {
    if (event.target.matches("#billing_customer_type,.kuka-legal-consents input")) syncCheckout();
  });
  document.addEventListener("DOMContentLoaded", syncCheckout);
  if (window.jQuery) window.jQuery(document.body).on("updated_checkout", syncCheckout);
  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart", () => {
      document.dispatchEvent(new CustomEvent("kuka:panel-open", {
        detail: { panelId: "kuka-cart-panel", trigger: document.querySelector('[aria-controls="kuka-cart-panel"]') },
      }));
    });
  }
})();
