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

  // Sepet sayfasında satır toplamı adet değişince eskimiş kalıyordu: klasik
  // WooCommerce sepeti yalnız "Sepeti güncelle" gönderiminde yeniden hesaplar.
  // Adet değişince o gönderim kısa bir gecikmeyle kendiliğinden yapılır; JS
  // kapalıyken düğme yerinde durduğu için davranış değişmez.
  const cartForm = document.querySelector("form.woocommerce-cart-form");
  if (cartForm) {
    let cartUpdateTimer = null;
    const scheduleCartUpdate = () => {
      const button = cartForm.querySelector("[name='update_cart']");
      if (!button) return;
      button.disabled = false;
      window.clearTimeout(cartUpdateTimer);
      cartUpdateTimer = window.setTimeout(() => button.click(), 600);
    };
    // Yakalama evresinde dinlenir: Blocksy'nin adet düğmeleri olayı kendi
    // işleyicisinde durdurduğu için kabarma evresi forma hiç ulaşmıyor.
    document.addEventListener("change", (event) => {
      if (event.target.matches(".woocommerce-cart-form input.qty")) scheduleCartUpdate();
    }, true);
    document.addEventListener("click", (event) => {
      if (event.target.closest(".woocommerce-cart-form .quantity .ct-increase,.woocommerce-cart-form .quantity .ct-decrease,.woocommerce-cart-form .quantity .plus,.woocommerce-cart-form .quantity .minus")) {
        scheduleCartUpdate();
      }
    }, true);
  }

  // Sözleşme onayları `required` ile korunur: onaysız gönderimde tarayıcı
  // odaklanılabilir ve okunur bir hata gösterir. Düğmeyi pasifleştirmek bu
  // mesajı bastırdığı için tercih edilmez; JS kapalıyken sunucu doğrulaması
  // aynı kuralı uygular.
  const syncCheckout = () => {
    const business = document.querySelector("#billing_customer_type");
    document.body.classList.toggle("kuka-corporate", business?.value === "corporate");
  };
  document.addEventListener("change", (event) => {
    if (event.target.matches("#billing_customer_type")) syncCheckout();
  });
  document.addEventListener("DOMContentLoaded", syncCheckout);
  if (window.jQuery) window.jQuery(document.body).on("updated_checkout", syncCheckout);

  // Sipariş özeti mobilde kapalı başlar, masaüstünde açık kalır. JS kapalıysa
  // <details open> ile zaten açıktır, yani içerik hiçbir koşulda gizlenmez.
  const summary = document.querySelector("[data-checkout-summary]");
  if (summary) {
    const compact = window.matchMedia("(max-width: 56.25em)");
    const syncSummary = () => { summary.open = !compact.matches; };
    syncSummary();
    compact.addEventListener("change", syncSummary);
  }

  // Kupon alanı checkout formunun içinde yaşadığı için gönderimi burada
  // yakalarız; yakalanmazsa form gönderimi WooCommerce'in sipariş verme
  // akışını tetiklerdi. JS kapalıyken `apply_coupon` düğmesi formu gönderir ve
  // WC_Form_Handler::update_cart_action kuponu uygular.
  const applyCoupon = async () => {
    const input = document.querySelector("#kuka_coupon_code");
    const errorBox = document.querySelector("[data-kuka-coupon-error]");
    const endpoint = window.wc_checkout_params?.wc_ajax_url?.toString().replace("%%endpoint%%", "apply_coupon");
    if (!input || !endpoint) return;
    const code = input.value.trim();
    if (errorBox) { errorBox.hidden = true; errorBox.textContent = ""; }
    if (!code) return;
    try {
      const response = await window.fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          security: window.wc_checkout_params.apply_coupon_nonce,
          coupon_code: code,
          billing_email: document.querySelector('input[name="billing_email"]')?.value ?? "",
        }),
      });
      const holder = document.createElement("div");
      holder.innerHTML = await response.text();
      const error = holder.querySelector(".woocommerce-error");
      if (error) {
        if (errorBox) {
          errorBox.textContent = error.textContent.trim();
          errorBox.hidden = false;
        }
        return;
      }
      input.value = "";
      if (window.jQuery) window.jQuery(document.body).trigger("update_checkout");
    } catch (requestError) {
      if (errorBox) {
        errorBox.textContent = errorBox.dataset.kukaCouponFailed || "Kupon uygulanamadı, lütfen tekrar deneyin.";
        errorBox.hidden = false;
      }
    }
  };

  document.addEventListener("click", (event) => {
    if (!event.target.closest("[data-kuka-apply-coupon]")) return;
    event.preventDefault();
    applyCoupon();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || !event.target.matches("#kuka_coupon_code")) return;
    event.preventDefault();
    applyCoupon();
  });
  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart", () => {
      document.dispatchEvent(new CustomEvent("kuka:panel-open", {
        detail: { panelId: "kuka-cart-panel", trigger: document.querySelector('[aria-controls="kuka-cart-panel"]') },
      }));
    });
  }
})();
