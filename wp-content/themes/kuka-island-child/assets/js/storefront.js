(() => {
  "use strict";
  const toggle = document.querySelector(".kuka-menu-toggle");
  const panel = document.querySelector(".kuka-mobile-menu");
  const closeButton = document.querySelector(".kuka-menu-close");
  const backdrop = document.querySelector(".kuka-menu-backdrop");
  if (!toggle || !panel || !closeButton || !backdrop) return;
  let previousFocus = null;
  const focusable = () => [...panel.querySelectorAll('a[href],button:not([disabled]),input:not([disabled])')];
  const close = () => {
    panel.classList.remove("is-open"); panel.setAttribute("aria-hidden", "true"); panel.inert = true;
    toggle.setAttribute("aria-expanded", "false"); backdrop.hidden = true; document.body.classList.remove("kuka-menu-open");
    if (previousFocus) previousFocus.focus();
  };
  const open = () => {
    previousFocus = document.activeElement; panel.inert = false; panel.classList.add("is-open"); panel.setAttribute("aria-hidden", "false");
    toggle.setAttribute("aria-expanded", "true"); backdrop.hidden = false; document.body.classList.add("kuka-menu-open"); closeButton.focus();
  };
  toggle.addEventListener("click", open); closeButton.addEventListener("click", close); backdrop.addEventListener("click", close);
  document.addEventListener("keydown", (event) => {
    if (!panel.classList.contains("is-open")) return;
    if (event.key === "Escape") { close(); return; }
    if (event.key !== "Tab") return;
    const nodes = focusable(); const first = nodes[0]; const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  document.documentElement.dataset.kukaIslandTheme = "ready";
})();
