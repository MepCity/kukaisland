/* global jQuery, wp */
(() => {
  const root = document.getElementById('kuka-community-admin');
  if (!root) return;
  const rows = root.querySelector('#kuka-community-rows');
  const status = root.querySelector('[data-gallery-status]');
  let dirty = false;
  const changed = () => { dirty = true; status.textContent = 'Kaydedilmemiş değişiklikler var.'; };
  const renumber = () => {
    [...rows.children].forEach((row, i) => {
      row.querySelector('[data-row-title]').textContent = `Fotoğraf ${i + 1}`;
      row.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/\[items\]\[[^\]]+\]/, `[items][${i}]`); });
      row.querySelector('[data-move="up"]').disabled = i === 0;
      row.querySelector('[data-move="down"]').disabled = i === rows.children.length - 1;
    });
    root.querySelector('#kuka-community-count').textContent = `${rows.children.length} / 40 fotoğraf`;
    root.querySelector('#kuka-community-add').disabled = rows.children.length >= 40;
  };
  const setImage = (row, item) => {
    row.querySelector('[data-image-id]').value = item.id;
    const img = document.createElement('img');
    img.src = item.sizes?.medium?.url || item.url;
    img.alt = item.alt || '';
    row.querySelector('.kuka-community-preview').replaceChildren(img);
    changed();
  };
  root.querySelector('#kuka-community-add').addEventListener('click', () => {
    const frame = wp.media({ title: 'Galeri fotoğraflarını seçin', library: { type: 'image' }, multiple: true, button: { text: 'Galeriye ekle' } });
    frame.on('select', () => {
      frame.state().get('selection').toJSON().forEach(item => {
        if (rows.children.length >= 40) return;
        const row = root.querySelector('template').content.firstElementChild.cloneNode(true);
        rows.append(row); setImage(row, item);
      });
      renumber();
    });
    frame.open();
  });
  rows.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button) return;
    const row = button.closest('article');
    if (button.hasAttribute('data-replace')) {
      const frame = wp.media({ title: 'Fotoğrafı değiştir', library: { type: 'image' }, multiple: false });
      frame.on('select', () => setImage(row, frame.state().get('selection').first().toJSON())); frame.open();
    }
    if (button.hasAttribute('data-remove')) row.remove();
    if (button.dataset.move === 'up' && row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling);
    if (button.dataset.move === 'down' && row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row);
    renumber(); changed();
  });
  root.addEventListener('change', changed);
  root.addEventListener('input', changed);
  jQuery(rows).sortable({ items: '> article', handle: '.kuka-community-row__tools', cancel: 'button', update: () => { renumber(); changed(); } });
  root.querySelector('[data-community-editor]').addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  renumber();
})();
