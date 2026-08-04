(() => {
  "use strict";
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
})();
