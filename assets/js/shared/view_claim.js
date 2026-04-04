// REQ_DOCS, CLAIM_URL, checkIcon, docIcon, xIcon must be set inline by PHP before this file loads

function updateDocUI(data) {
  const subEl = document.getElementById('doc-count-sub');
  if (subEl) subEl.textContent = data.done + '/' + data.req + ' documents received';
  const badgeEl = document.getElementById('doc-badge');
  if (badgeEl) {
    if (data.all_done) {
      badgeEl.className = 'badge badge-success';
      badgeEl.innerHTML = checkIcon + ' Complete';
    } else {
      badgeEl.className = 'badge badge-warning';
      badgeEl.textContent = (data.req - data.done) + ' remaining';
    }
  }
  const btnWrap     = document.getElementById('submit-btn-wrap');
  const btn         = document.getElementById('submit-btn');
  const disabledBtn = document.getElementById('submit-btn-disabled');
  if (btnWrap) {
    if (data.done >= data.req) {
      if (btn)         btn.style.display = '';
      if (disabledBtn) disabledBtn.style.display = 'none';
    } else {
      if (btn)         btn.style.display = 'none';
      if (disabledBtn) disabledBtn.style.display = '';
    }
  }
}

// ── File upload ──
document.querySelectorAll('.doc-file-input').forEach(function(input) {
  input.addEventListener('change', function() {
    const field = this.dataset.field;
    const file  = this.files[0];
    if (!file) return;

    const item = document.getElementById('doc-item-' + field);
    item.classList.add('doc-uploading');

    const fd = new FormData();
    fd.append('ajax_upload', '1');
    fd.append('doc_field', field);
    fd.append('doc_file', file);

    fetch(CLAIM_URL, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        item.classList.remove('doc-uploading');
        if (!data.ok) {
          Swal.fire({ icon: 'error', title: 'Upload Failed', text: data.msg || 'Could not upload file.', confirmButtonColor: '#B8860B' });
          return;
        }
        item.classList.add('received');
        const cb = document.getElementById('doc-cb-' + field);
        if (cb) cb.innerHTML = checkIcon;

        const preview = document.getElementById('doc-preview-' + field);
        if (preview) {
          preview.style.display = '';
          if (data.is_pdf) {
            preview.innerHTML = `<a href="${data.url}" target="_blank" class="doc-file-link">${docIcon} View PDF</a>
              <button type="button" class="doc-remove-btn" data-field="${field}">${xIcon} Remove</button>`;
          } else {
            preview.innerHTML = `<a href="${data.url}" target="_blank"><img src="${data.url}" class="doc-thumb" alt=""/></a>
              <button type="button" class="doc-remove-btn" data-field="${field}">${xIcon} Remove</button>`;
          }
          attachRemoveBtn(preview.querySelector('.doc-remove-btn'));
        }
        updateDocUI(data);
      })
      .catch(function() {
        item.classList.remove('doc-uploading');
        Swal.fire({ icon: 'error', title: 'Upload Failed', text: 'Network error. Please try again.', confirmButtonColor: '#B8860B' });
      });

    this.value = '';
  });
});

// ── Remove document ──
function attachRemoveBtn(btn) {
  if (!btn) return;
  btn.addEventListener('click', function() {
    const field = this.dataset.field;
    const fd = new FormData();
    fd.append('ajax_remove_doc', '1');
    fd.append('doc_field', field);

    fetch(CLAIM_URL, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        const item = document.getElementById('doc-item-' + field);
        item.classList.remove('received');
        const cb = document.getElementById('doc-cb-' + field);
        if (cb) cb.innerHTML = '';
        const preview = document.getElementById('doc-preview-' + field);
        if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
        updateDocUI(data);
      });
  });
}
document.querySelectorAll('.doc-remove-btn').forEach(attachRemoveBtn);

// ── Damage photos multi-upload ──
function attachDmgRemoveBtn(btn) {
  if (!btn) return;
  btn.addEventListener('click', function() {
    const photoId = this.dataset.id;
    const wrap    = document.getElementById('dmg-wrap-' + photoId);
    const fd = new FormData();
    fd.append('ajax_damage_remove', '1');
    fd.append('photo_id', photoId);
    fetch(CLAIM_URL, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        if (wrap) wrap.remove();
        const countEl = document.getElementById('damage-photo-count');
        if (countEl) countEl.textContent = data.remaining + ' photo' + (data.remaining !== 1 ? 's' : '') + ' uploaded';
        if (data.remaining === 0) {
          const item = document.getElementById('doc-item-doc_damage_photos');
          const cb   = document.getElementById('doc-cb-doc_damage_photos');
          if (item) item.classList.remove('received');
          if (cb)   cb.innerHTML = '';
        }
        updateDocUI(data);
      });
  });
}

document.querySelectorAll('.dmg-file-input').forEach(function(input) {
  input.addEventListener('change', function() {
    const files = Array.from(this.files);
    if (!files.length) return;

    files.forEach(function(file) {
      const fd = new FormData();
      fd.append('ajax_damage_upload', '1');
      fd.append('damage_file', file);

      fetch(CLAIM_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (!data.ok) {
            Swal.fire({ icon: 'error', title: 'Upload Failed', text: data.msg || 'Could not upload.', confirmButtonColor: '#B8860B' });
            return;
          }
          const grid = document.getElementById('damage-photo-grid');
          if (grid) {
            const wrap = document.createElement('div');
            wrap.className = 'dmg-photo-wrap';
            wrap.id = 'dmg-wrap-' + data.photo_id;
            wrap.style.position = 'relative';
            wrap.innerHTML = `<a href="${data.url}" target="_blank">
              <img src="${data.url}" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"/>
            </a>
            <button type="button" class="dmg-remove-btn" data-id="${data.photo_id}"
              style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;line-height:1;">
              ${xIcon}
            </button>`;
            grid.appendChild(wrap);
            attachDmgRemoveBtn(wrap.querySelector('.dmg-remove-btn'));
          }
          const countEl = document.getElementById('damage-photo-count');
          if (countEl) {
            const current  = parseInt(countEl.textContent) || 0;
            const newCount = current + 1;
            countEl.textContent = newCount + ' photo' + (newCount !== 1 ? 's' : '') + ' uploaded';
          }
          const item = document.getElementById('doc-item-doc_damage_photos');
          const cb   = document.getElementById('doc-cb-doc_damage_photos');
          if (item) item.classList.add('received');
          if (cb)   cb.innerHTML = checkIcon;
          updateDocUI(data);
        })
        .catch(function() {
          Swal.fire({ icon: 'error', title: 'Upload Failed', text: 'Network error. Try again.', confirmButtonColor: '#B8860B' });
        });
    });
    this.value = '';
  });
});
document.querySelectorAll('.dmg-remove-btn').forEach(attachDmgRemoveBtn);

// ── Show denial reason when status = denied ──
const newStatusSel = document.getElementById('new_status');
const denialWrap   = document.getElementById('denial-reason-wrap');
if (newStatusSel) {
  newStatusSel.addEventListener('change', function() {
    denialWrap.style.display = this.value === 'denied' ? '' : 'none';
  });
}

// ── Delete claim confirmation ──
document.querySelectorAll('.js-delete-claim').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const form = this.closest('form');
    Swal.fire({
      icon: 'warning',
      title: 'Delete Claim?',
      text: 'This will permanently delete this claim record. This action cannot be undone.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#c0392b',
      cancelButtonColor: '#6c757d',
    }).then(function(result) {
      if (result.isConfirmed) form.submit();
    });
  });
});
