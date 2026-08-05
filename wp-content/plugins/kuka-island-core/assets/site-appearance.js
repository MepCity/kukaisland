(() => {
  "use strict";

  document.addEventListener("click", (event) => {
    const select = event.target.closest("[data-kuka-media-select]");
    const clear = event.target.closest("[data-kuka-media-clear]");
    if (!select && !clear) return;

    const field = event.target.closest("[data-kuka-media-field]");
    const input = field?.querySelector("input");
    const preview = field?.querySelector("[data-kuka-media-preview]");
    if (!field || !input || !preview) return;

    if (clear) {
      input.value = "0";
      preview.replaceChildren();
      return;
    }

    const frame = window.wp.media({
      title: field.dataset.mediaType === "video" ? "Video seç" : "Görsel seç",
      library: { type: field.dataset.mediaType },
      multiple: false,
      button: { text: "Kullan" },
    });
    frame.on("select", () => {
      const attachment = frame.state().get("selection").first().toJSON();
      input.value = String(attachment.id);
      preview.replaceChildren();
      if (attachment.type === "image") {
        const image = document.createElement("img");
        image.src = attachment.sizes?.thumbnail?.url || attachment.url;
        image.alt = "";
        image.width = 80;
        preview.append(image);
      } else {
        preview.textContent = attachment.filename;
      }
    });
    frame.open();
  });
})();
