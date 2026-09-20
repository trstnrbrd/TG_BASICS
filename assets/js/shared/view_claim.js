function updateDocUI(data) {
  const subEl = document.getElementById("doc-count-sub");
  subEl &&
    (subEl.textContent = data.done + "/" + data.req + " documents received");
  const badgeEl = document.getElementById("doc-badge");
  badgeEl &&
    (data.all_done
      ? ((badgeEl.className = "badge badge-success"),
        (badgeEl.innerHTML = checkIcon + " Complete"))
      : ((badgeEl.className = "badge badge-warning"),
        (badgeEl.textContent = data.req - data.done + " remaining")));
  const btnWrap = document.getElementById("submit-btn-wrap"),
    btn = document.getElementById("submit-btn"),
    disabledBtn = document.getElementById("submit-btn-disabled");
  btnWrap &&
    (data.done >= data.req
      ? (btn && (btn.style.display = ""),
        disabledBtn && (disabledBtn.style.display = "none"))
      : (btn && (btn.style.display = "none"),
        disabledBtn && (disabledBtn.style.display = "")));
  const hasDocs = data.done > 0,
    sendBtn = document.getElementById("btn-send-admin-email"),
    sendHint = document.getElementById("send-btn-hint");
  (sendBtn &&
    ((sendBtn.disabled = !hasDocs),
    (sendBtn.style.opacity = hasDocs ? "1" : "0.45"),
    (sendBtn.style.cursor = hasDocs ? "" : "not-allowed")),
    sendHint &&
      (sendHint.textContent = hasDocs
        ? "Sends the current requirements checklist to the admin email for review and follow-up."
        : "Upload at least one requirement before sending."));
  const statusBtn = document.getElementById("btn-update-status"),
    statusHint = document.getElementById("status-btn-hint");
  (statusBtn &&
    ((statusBtn.disabled = !hasDocs),
    (statusBtn.style.opacity = hasDocs ? "1" : "0.45"),
    (statusBtn.style.cursor = hasDocs ? "" : "not-allowed")),
    statusHint && (statusHint.style.display = hasDocs ? "none" : ""));
}
function attachRemoveBtn(btn) {
  btn &&
    btn.addEventListener("click", function () {
      const field = this.dataset.field,
        fd = new FormData();
      (fd.append("ajax_remove_doc", "1"),
        fd.append("doc_field", field),
        fetch(CLAIM_URL, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((data) => {
            if (!data.ok) return;
            document
              .getElementById("doc-item-" + field)
              .classList.remove("received");
            const cb = document.getElementById("doc-cb-" + field);
            cb && (cb.innerHTML = "");
            const preview = document.getElementById("doc-preview-" + field);
            (preview &&
              ((preview.innerHTML = ""), (preview.style.display = "none")),
              updateDocUI(data));
          }));
    });
}
function attachDmgRemoveBtn(btn) {
  btn &&
    btn.addEventListener("click", function () {
      const photoId = this.dataset.id,
        wrap = document.getElementById("dmg-wrap-" + photoId),
        fd = new FormData();
      (fd.append("ajax_damage_remove", "1"),
        fd.append("photo_id", photoId),
        fetch(CLAIM_URL, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((data) => {
            if (!data.ok) return;
            wrap && wrap.remove();
            const countEl = document.getElementById("damage-photo-count");
            if (
              (countEl &&
                (countEl.textContent =
                  data.remaining +
                  " photo" +
                  (1 !== data.remaining ? "s" : "") +
                  " uploaded"),
              0 === data.remaining)
            ) {
              const item = document.getElementById(
                  "doc-item-doc_damage_photos",
                ),
                cb = document.getElementById("doc-cb-doc_damage_photos");
              (item && item.classList.remove("received"),
                cb && (cb.innerHTML = ""));
            }
            updateDocUI(data);
          }));
    });
}
(document.querySelectorAll(".doc-file-input").forEach(function (input) {
  input.addEventListener("change", function () {
    const field = this.dataset.field,
      file = this.files[0];
    if (!file) return;
    const item = document.getElementById("doc-item-" + field);
    item.classList.add("doc-uploading");
    const fd = new FormData();
    (fd.append("ajax_upload", "1"),
      fd.append("doc_field", field),
      fd.append("doc_file", file),
      fetch(CLAIM_URL, { method: "POST", body: fd })
        .then((r) => r.json())
        .then((data) => {
          if ((item.classList.remove("doc-uploading"), !data.ok))
            return void Swal.fire({
              icon: "error",
              title: "Upload Failed",
              text: data.msg || "Could not upload file.",
              confirmButtonColor: "#B8860B",
            });
          item.classList.add("received");
          const cb = document.getElementById("doc-cb-" + field);
          cb && (cb.innerHTML = checkIcon);
          const preview = document.getElementById("doc-preview-" + field);
          (preview &&
            ((preview.style.display = ""),
            data.is_pdf
              ? (preview.innerHTML = `<a href="${data.url}" target="_blank" class="doc-file-link">${docIcon} View PDF</a>\n              <button type="button" class="doc-remove-btn" data-field="${field}">${xIcon} Remove</button>`)
              : (preview.innerHTML = `<a href="${data.url}" target="_blank"><img src="${data.url}" class="doc-thumb" alt="" loading="lazy"/></a>\n              <button type="button" class="doc-remove-btn" data-field="${field}">${xIcon} Remove</button>`),
            attachRemoveBtn(preview.querySelector(".doc-remove-btn"))),
            updateDocUI(data));
        })
        .catch(function () {
          (item.classList.remove("doc-uploading"),
            Swal.fire({
              icon: "error",
              title: "Upload Failed",
              text: "Network error. Please try again.",
              confirmButtonColor: "#B8860B",
            }));
        }),
      (this.value = ""));
  });
}),
  document.querySelectorAll(".doc-remove-btn").forEach(attachRemoveBtn),
  document.querySelectorAll(".dmg-file-input").forEach(function (input) {
    input.addEventListener("change", function () {
      const files = Array.from(this.files);
      files.length &&
        (files.forEach(function (file) {
          const fd = new FormData();
          (fd.append("ajax_damage_upload", "1"),
            fd.append("damage_file", file),
            fetch(CLAIM_URL, { method: "POST", body: fd })
              .then((r) => r.json())
              .then((data) => {
                if (!data.ok)
                  return void Swal.fire({
                    icon: "error",
                    title: "Upload Failed",
                    text: data.msg || "Could not upload.",
                    confirmButtonColor: "#B8860B",
                  });
                const grid = document.getElementById("damage-photo-grid");
                if (grid) {
                  const wrap = document.createElement("div");
                  ((wrap.className = "dmg-photo-wrap"),
                    (wrap.id = "dmg-wrap-" + data.photo_id),
                    (wrap.style.position = "relative"),
                    (wrap.innerHTML = `<a href="${data.url}" target="_blank">\n              <img src="${data.url}" alt="Damage evidence photo" loading="lazy" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"/>\n            </a>\n            <button type="button" class="dmg-remove-btn" data-id="${data.photo_id}" aria-label="Remove photo"\n              style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;line-height:1;">\n              ${xIcon}\n            </button>`),
                    grid.appendChild(wrap),
                    attachDmgRemoveBtn(wrap.querySelector(".dmg-remove-btn")));
                }
                const countEl = document.getElementById("damage-photo-count");
                if (countEl) {
                  const newCount = (parseInt(countEl.textContent) || 0) + 1;
                  countEl.textContent =
                    newCount +
                    " photo" +
                    (1 !== newCount ? "s" : "") +
                    " uploaded";
                }
                const item = document.getElementById(
                    "doc-item-doc_damage_photos",
                  ),
                  cb = document.getElementById("doc-cb-doc_damage_photos");
                (item && item.classList.add("received"),
                  cb && (cb.innerHTML = checkIcon),
                  updateDocUI(data));
              })
              .catch(function () {
                Swal.fire({
                  icon: "error",
                  title: "Upload Failed",
                  text: "Network error. Try again.",
                  confirmButtonColor: "#B8860B",
                });
              }));
        }),
        (this.value = ""));
    });
  }),
  document.querySelectorAll(".dmg-remove-btn").forEach(attachDmgRemoveBtn));
const newStatusSel = document.getElementById("new_status"),
  denialWrap = document.getElementById("denial-reason-wrap");
(newStatusSel &&
  newStatusSel.addEventListener("change", function () {
    denialWrap.style.display = "denied" === this.value ? "" : "none";
  }),
  document.querySelectorAll(".js-delete-claim").forEach(function (btn) {
    btn.addEventListener("click", async function () {
      const form = this.closest("form");
      if (
        !(
          await Swal.fire({
            icon: "warning",
            title: "Delete Claim?",
            text: "This will permanently delete this claim record. This action cannot be undone.",
            showCancelButton: !0,
            confirmButtonText: "Yes, delete it",
            cancelButtonText: "Cancel",
            confirmButtonColor: "#c0392b",
            cancelButtonColor: "#6c757d",
          })
        ).isConfirmed
      )
        return;
      (await requirePin()) &&
        (form.requestSubmit ? form.requestSubmit() : form.submit());
    });
  }));
