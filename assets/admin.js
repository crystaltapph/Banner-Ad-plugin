/* global wp */
(() => {
  'use strict';
  document.querySelectorAll('.bap-delete').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm('Permanently delete this banner? Uploaded images will be kept.')) event.preventDefault();
  }));
  const search = document.querySelector('#bap-search');
  const status = document.querySelector('#bap-status');
  const filter = () => {
    let visible = 0;
    document.querySelectorAll('[data-bap-row]').forEach(row => {
      row.hidden = !row.dataset.name.includes(search.value.toLowerCase()) || (status.value && row.dataset.status !== status.value);
      if (!row.hidden) visible++;
    });
    const empty = document.querySelector('#bap-no-results');
    if (empty) empty.hidden = visible > 0;
  };
  if (search) { search.addEventListener('input', filter); status.addEventListener('change', filter); }
  const form = document.querySelector('#bap-editor');
  if (!form) return;
  const rows = [...form.querySelectorAll('[data-creative-index]')];
  const previewSelect = document.querySelector('#bap-preview-creative');
  const addButton = document.querySelector('#bap-add-creative');
  const urls = {};
  form.querySelectorAll('.bap-media input[type="hidden"]').forEach(input => {
    urls[input.id] = input.closest('.bap-media').querySelector('img')?.src || '';
  });
  let device = 'desktop';
  const image = document.querySelector('#bap-preview-image');
  const feedback = document.querySelector('#bap-form-feedback');
  const value = key => form.querySelector(`#bap-${key}`)?.value;
  const checked = key => !!form.querySelector(`[name="banner[${key}]"]`)?.checked;
  const creativeField = (row, key) => row.querySelector(`[data-creative-field="${key}"]`);
  const artwork = (row, key) => urls[creativeField(row, key).id] || '';
  const syncRows = () => {
    const visible = rows.filter(row => !row.hidden);
    addButton.disabled = visible.length >= 4;
    document.querySelector('#bap-creative-count').textContent = `${visible.length} / 4 creatives`;
    rows.forEach(row => {
      row.querySelector('.bap-remove-creative').disabled = visible.length === 1;
      previewSelect.options[Number(row.dataset.creativeIndex)].disabled = row.hidden;
    });
    if (rows[Number(previewSelect.value)].hidden) previewSelect.value = visible[0].dataset.creativeIndex;
  };
  const preview = () => {
    const row = rows[Number(previewSelect.value)];
    const desktop = artwork(row, 'desktop_image');
    const mobile = artwork(row, 'mobile_image');
    const src = device === 'mobile' ? (mobile || desktop) : (desktop || mobile);
    image.hidden = !src;
    if (src) image.src = src; else image.removeAttribute('src');
    image.alt = creativeField(row, 'alt').value || 'Banner preview';
    document.querySelector('#bap-preview-empty').hidden = !!src;
    document.querySelector('#bap-preview-label').textContent = value('label');
    const frame = document.querySelector('#bap-preview-device');
    frame.classList.toggle('is-mobile', device === 'mobile');
    const sticky = value('placement') === 'sticky' && device === 'mobile';
    frame.classList.toggle('is-sticky', sticky);
    const banner = document.querySelector('#bap-preview-banner');
    banner.style.maxWidth = checked('stretch') ? '100%' : `${value(device === 'mobile' ? 'mobile_width' : 'width')}px`;
    banner.style.padding = `${value('padding')}px`;
    banner.style.marginBlock = sticky ? '0' : `${value('margin')}px`;
    banner.style.marginInline = value('align') === 'left' ? '0 auto' : value('align') === 'right' ? 'auto 0' : 'auto';
    const hidden = (device === 'desktop' && (!checked('desktop') || value('placement') === 'sticky')) || (device === 'mobile' && !checked('mobile'));
    banner.style.opacity = hidden ? '0.25' : '1';
    document.querySelector('#bap-preview-description').textContent = hidden ? 'This banner is hidden on this device.' : sticky ? 'Bottom sticky preview. A dismiss button is added on the live site.' : 'Creative preview only. Test placement in your theme before publishing.';
  };
  addButton.addEventListener('click', () => {
    const row = rows.find(row => row.hidden);
    if (!row) return;
    row.hidden = false;
    previewSelect.value = row.dataset.creativeIndex;
    syncRows(); preview();
    row.querySelector('.bap-select-image').focus();
  });
  rows.forEach(row => {
    row.querySelector('.bap-remove-creative').addEventListener('click', () => {
      if (rows.filter(item => !item.hidden).length <= 1) return;
      row.querySelectorAll('input').forEach(input => { input.value = input.type === 'hidden' ? '0' : ''; });
      row.querySelectorAll('.bap-media input').forEach(input => { urls[input.id] = ''; });
      row.querySelectorAll('.bap-media-preview').forEach(frame => { frame.textContent = 'No image selected'; });
      row.hidden = true;
      syncRows(); preview(); addButton.focus();
    });
    row.addEventListener('focusin', () => { previewSelect.value = row.dataset.creativeIndex; preview(); });
  });
  previewSelect.addEventListener('change', preview);
  document.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => {
    device = button.dataset.preview;
    document.querySelectorAll('[data-preview]').forEach(other => {
      other.classList.toggle('is-current', other === button);
      other.setAttribute('aria-pressed', String(other === button));
    });
    preview();
  }));
  document.querySelectorAll('.bap-select-image').forEach(button => button.addEventListener('click', () => {
    const picker = wp.media({ title: 'Choose banner artwork', button: { text: 'Use this image' }, library: { type: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'] }, multiple: false });
    picker.on('select', () => {
      const attachment = picker.state().get('selection').first().toJSON();
      if (!['image/jpeg', 'image/png', 'image/webp', 'image/avif'].includes(attachment.mime)) {
        feedback.textContent = 'Choose a JPEG, PNG, WebP, or AVIF image.'; return;
      }
      const key = button.dataset.field;
      document.querySelector(`#bap-${key}`).value = attachment.id;
      urls[`bap-${key}`] = attachment.url;
      const row = button.closest('[data-creative-index]');
      previewSelect.value = row.dataset.creativeIndex;
      const container = button.closest('.bap-media').querySelector('.bap-media-preview');
      container.replaceChildren();
      const thumb = document.createElement('img'); thumb.src = attachment.url; thumb.alt = ''; container.append(thumb);
      if (!creativeField(row, 'alt').value && attachment.alt) creativeField(row, 'alt').value = attachment.alt;
      feedback.textContent = attachment.filesizeInBytes > 204800 ? 'This image is over 200 KB. Consider optimizing it before publishing.' : '';
      preview();
    });
    picker.open();
  }));
  document.querySelectorAll('.bap-remove-image').forEach(button => button.addEventListener('click', () => {
    const key = button.dataset.field;
    document.querySelector(`#bap-${key}`).value = 0;
    urls[`bap-${key}`] = '';
    previewSelect.value = button.closest('[data-creative-index]').dataset.creativeIndex;
    button.closest('.bap-media').querySelector('.bap-media-preview').textContent = 'No image selected';
    preview();
  }));
  form.addEventListener('input', preview);
  form.addEventListener('change', preview);
  form.addEventListener('submit', event => {
    let error = '';
    let used = 0;
    for (const row of rows.filter(item => !item.hidden)) {
      const hasImage = artwork(row, 'desktop_image') || artwork(row, 'mobile_image');
      const url = creativeField(row, 'url').value;
      const alt = creativeField(row, 'alt').value.trim();
      if (hasImage) used++;
      if (!hasImage && (url || alt)) error = 'Each creative with a link or alternative text needs an image.';
      else if (url && !alt) error = 'Add alternative text for each linked creative.';
      else if (url && !/^https?:\/\//i.test(url)) error = 'Use a full http:// or https:// destination URL.';
      if (error) { previewSelect.value = row.dataset.creativeIndex; preview(); break; }
    }
    if (!error && !used) error = 'Choose at least one banner image.';
    if (!error && !checked('desktop') && !checked('mobile')) error = 'Choose at least one device.';
    else if (value('placement') === 'sticky' && !checked('mobile')) error = 'Enable mobile visibility for a sticky banner.';
    else if (value('start') && value('end') && value('start') >= value('end')) error = 'The end date must be later than the start date.';
    else if (!form.querySelector('[name="banner[post_types][]"]:checked')) error = 'Choose at least one content type.';
    if (error) { event.preventDefault(); feedback.textContent = error; feedback.scrollIntoView({ block: 'center' }); }
  });
  syncRows();
  preview();
})();
