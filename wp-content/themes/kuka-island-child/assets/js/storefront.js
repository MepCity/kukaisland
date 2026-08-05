(() => {
  "use strict";

  const focusableSelector = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  let activePanel = null;
  let activeTrigger = null;
  let activeOverlay = null;

  const closePanel = (restoreFocus = true) => {
    if (!activePanel) return;
	const closedPanel = activePanel;
    activePanel.classList.remove("is-open");
    activePanel.setAttribute("aria-hidden", "true");
    activePanel.inert = true;
    activeTrigger?.setAttribute("aria-expanded", "false");
    if (activeOverlay) activeOverlay.hidden = true;
    document.body.classList.remove("kuka-panel-open");
    const restore = activeTrigger;
    activePanel = null;
    activeTrigger = null;
    activeOverlay = null;
	document.dispatchEvent(new CustomEvent("kuka:panel-closed", { detail: { panel: closedPanel } }));
    if (restoreFocus) restore?.focus();
  };

  const openPanel = (trigger) => {
    const panel = document.getElementById(trigger.getAttribute("aria-controls"));
    if (!panel) return;
    closePanel(false);
    activePanel = panel;
    activeTrigger = trigger;
    activeOverlay = panel.previousElementSibling?.matches("[data-panel-overlay]") ? panel.previousElementSibling : null;
    panel.inert = false;
    panel.classList.add("is-open");
    panel.setAttribute("aria-hidden", "false");
    trigger.setAttribute("aria-expanded", "true");
    if (activeOverlay) activeOverlay.hidden = false;
    document.body.classList.add("kuka-panel-open");
	document.dispatchEvent(new CustomEvent("kuka:panel-opened", { detail: { panel, trigger } }));
    window.requestAnimationFrame(() => [...panel.querySelectorAll(focusableSelector)].find((node) => node.getClientRects().length && node.getAttribute("aria-hidden") !== "true")?.focus());
  };

  document.addEventListener("click", (event) => {
    const trigger = event.target.closest("[data-panel-trigger]");
    if (trigger) {
      if (trigger.matches("a") && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0)) return;
      event.preventDefault();
      openPanel(trigger);
    }
    if (event.target.closest("[data-panel-close]") || event.target.matches("[data-panel-overlay]")) closePanel();
  });

  document.addEventListener("kuka:panel-open", (event) => {
    const panelId = event.detail?.panelId;
    const trigger = event.detail?.trigger || document.querySelector(`[aria-controls="${panelId}"]`);
    if (trigger) openPanel(trigger);
  });

  const initialPanel = document.querySelector("[data-panel-open-on-load]");
  const initialTrigger = initialPanel && document.querySelector(`[aria-controls="${initialPanel.id}"]`);
  if (initialTrigger) window.requestAnimationFrame(() => openPanel(initialTrigger));

  document.addEventListener("keydown", (event) => {
    if (!activePanel) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closePanel();
      return;
    }
    if (event.key !== "Tab") return;
    const nodes = [...activePanel.querySelectorAll(focusableSelector)].filter((node) => node.getClientRects().length && node.getAttribute("aria-hidden") !== "true");
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const header = document.querySelector("[data-site-header]");
  const syncHeader = () => header?.classList.toggle("is-scrolled", window.scrollY > 16);
  window.addEventListener("scroll", syncHeader, { passive: true });
  syncHeader();

  document.documentElement.dataset.kukaIslandTheme = "ready";
})();
