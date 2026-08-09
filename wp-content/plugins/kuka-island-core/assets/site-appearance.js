(() => {
  "use strict";

  const search = document.querySelector("[data-kuka-field-search]");
  const panels = [...document.querySelectorAll("[data-kuka-panel]")];
  const tabs = [...document.querySelectorAll("[data-kuka-tab]")];
  const activeInput = document.querySelector("[data-kuka-active-tab]");
  const searchStatus = document.querySelector("[data-kuka-search-status]");

  const activateTab = (key, updateUrl = false) => {
	panels.forEach((panel) => panel.classList.toggle("is-active", panel.dataset.kukaPanel === key));
	tabs.forEach((tab) => tab.classList.toggle("nav-tab-active", tab.dataset.kukaTab === key));
	if (activeInput) activeInput.value = key;
	if (updateUrl) {
	  const url = new URL(window.location.href);
	  url.searchParams.set("tab", key);
	  window.history.replaceState({}, "", url);
	}
  };

  tabs.forEach((tab) => tab.addEventListener("click", (event) => {
	event.preventDefault();
	if (search) search.value = "";
	panels.forEach((panel) => panel.classList.remove("is-search-result"));
	activateTab(tab.dataset.kukaTab, true);
  }));

  search?.addEventListener("input", () => {
	const query = search.value.trim().toLocaleLowerCase("tr");
	let matches = 0;
	panels.forEach((panel) => {
	  let panelMatches = 0;
	  panel.querySelectorAll(".form-table > tbody > tr").forEach((row) => {
		const visible = !query || row.textContent.toLocaleLowerCase("tr").includes(query);
		row.hidden = !visible;
		if (visible && query) panelMatches += 1;
	  });
	  panel.classList.toggle("is-search-result", Boolean(query && panelMatches));
	  panel.classList.toggle("is-active", !query && panel.dataset.kukaPanel === activeInput?.value);
	  matches += panelMatches;
	});
	if (searchStatus) searchStatus.textContent = query ? `${matches} alan bulundu.` : "";
  });

  document.querySelectorAll("input[type='text'], input[type='email'], textarea").forEach((control) => {
	const counter = control.closest("td")?.querySelector("[data-kuka-character-count]");
	if (!counter) return;
	const update = () => { counter.textContent = String(control.value.length); };
	control.addEventListener("input", update);
	update();
  });

  document.addEventListener("click", (event) => {
	const addScene = event.target.closest("[data-kuka-story-add]");
	const removeScene = event.target.closest("[data-kuka-story-remove]");
	const moveUp = event.target.closest("[data-kuka-story-up]");
	const moveDown = event.target.closest("[data-kuka-story-down]");
	if (addScene || removeScene || moveUp || moveDown) {
	  const root = event.target.closest("[data-kuka-story-scenes]");
	  const list = root?.querySelector("[data-kuka-story-list]");
	  const scene = event.target.closest("[data-kuka-story-scene]");
	  if (!root || !list) return;
	  if (addScene) {
		const template = root.querySelector("[data-kuka-story-template]");
		if (!template || list.children.length >= 20) return;
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name]").forEach((control) => {
		  control.name = control.name.replace("__INDEX__", String(list.children.length));
		});
		list.append(fragment);
	  } else if (removeScene && scene) {
		if (list.children.length > 1) scene.remove();
	  } else if (moveUp && scene?.previousElementSibling) {
		list.insertBefore(scene, scene.previousElementSibling);
	  } else if (moveDown && scene?.nextElementSibling) {
		list.insertBefore(scene.nextElementSibling, scene);
	  }
	  [...list.children].forEach((row, index) => {
		row.querySelector("[data-kuka-story-number]").textContent = `Sahne ${String(index + 1).padStart(2, "0")}`;
		row.querySelectorAll("[name]").forEach((control) => {
		  control.name = control.name.replace(/\[scenes\]\[\d+\]/, `[scenes][${index}]`);
		});
	  });
	  return;
	}

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
		const warning = field.parentElement?.querySelector("[data-kuka-alt-warning]");
		if (warning) {
		  warning.textContent = attachment.alt ? `Alternatif metin: ${attachment.alt}` : "Uyarı: Bu görselin alternatif metni boş.";
		  warning.classList.toggle("kuka-alt-warning", !attachment.alt);
		}
      } else {
        preview.textContent = attachment.filename;
      }
    });
    frame.open();
  });
})();
