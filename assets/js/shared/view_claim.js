function updateDocUI(data) {
  const subEl = document.getElementById("doc-count-sub");
  subEl &&
    (subEl.textContent = data.done + "/" + data.req + " documents received");
  const badgeEl = document.getElementById("doc-badge");
  badgeEl &&
    (data.all_done
      ? ((badgeEl.className = "badge badge-green"),
        (badgeEl.innerHTML = checkIcon + " Complete"))
      : ((badgeEl.className = "badge badge-yellow"),
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
  sendBtn && (sendBtn.disabled = !hasDocs);
  sendHint &&
    (sendHint.textContent = hasDocs
      ? "Only sends the email. The status stays the same."
      : "Upload at least one requirement before sending.");
  // Next Step: a step that only waited for a first requirement opens (or closes again) with the uploads
  document.querySelectorAll('#status-form input[data-needs-docs="1"]').forEach((r) => {
    r.disabled = !hasDocs;
    if (!hasDocs) r.checked = false;
    const opt = r.closest(".next-option"),
      hint = opt && opt.querySelector(".next-option-hint");
    opt && opt.classList.toggle("is-blocked", !hasDocs);
    hint && (hint.textContent = hasDocs ? hint.dataset.hint : "Upload at least one requirement before updating the status.");
  });
  syncNextStep();
}
function attachRemoveBtn(btn) {
  btn &&
    btn.addEventListener("click", function () {
      const field = this.dataset.field,
        fd = new FormData();
      (fd.append("ajax_remove_doc", "1"),
        fd.append("csrf_token", CSRF_TOKEN),
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
        fd.append("csrf_token", CSRF_TOKEN),
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
      fd.append("csrf_token", CSRF_TOKEN),
      fd.append("doc_field", field),
      window
        .tgCompressImage(file, "document")
        .then((small) => {
          fd.append("doc_file", small);
          return fetch(CLAIM_URL, { method: "POST", body: fd });
        })
        .then((r) => r.json())
        .then((data) => {
          if ((item.classList.remove("doc-uploading"), !data.ok))
            return void Swal.fire({
              icon: "error",
              title: "Upload Failed",
              text: data.message || "Could not upload file.",
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
            fd.append("csrf_token", CSRF_TOKEN),
            window
              .tgCompressImage(file, "photo")
              .then((small) => {
                fd.append("damage_file", small);
                return fetch(CLAIM_URL, { method: "POST", body: fd });
              })
              .then((r) => r.json())
              .then((data) => {
                if (!data.ok)
                  return void Swal.fire({
                    icon: "error",
                    title: "Upload Failed",
                    text: data.message || "Could not upload.",
                  });
                const grid = document.getElementById("damage-photo-grid");
                if (grid) {
                  const wrap = document.createElement("div");
                  ((wrap.className = "dmg-photo-wrap"),
                    (wrap.id = "dmg-wrap-" + data.photo_id),
                    (wrap.innerHTML = `<a href="${data.url}" target="_blank"><img src="${data.url}" alt="Damage evidence photo" loading="lazy" class="dmg-thumb"/></a><button type="button" class="dmg-remove-btn" data-id="${data.photo_id}" aria-label="Remove photo">${xIcon}</button>`),
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
                });
              }));
        }),
        (this.value = ""));
    });
  }),
  document.querySelectorAll(".dmg-remove-btn").forEach(attachDmgRemoveBtn));
(
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
            confirmButtonColor: "var(--danger)",
          })
        ).isConfirmed
      )
        return;
      (await requirePin()) &&
        (form.requestSubmit ? form.requestSubmit() : form.submit());
    });
  }));

// ── Next Step: pick where the claim goes, then one button ──
const statusForm = document.getElementById("status-form");
function syncNextStep() {
  if (!statusForm) return;
  const picked = statusForm.querySelector('input[name="new_status"]:checked'),
    btn = document.getElementById("btn-update-status"),
    label = btn && btn.querySelector("span"),
    denial = document.getElementById("denial-reason-wrap"),
    emails = picked && picked.value === "sent_admin" && statusForm.dataset.sendsEmail === "1";
  statusForm.querySelectorAll(".next-option").forEach((o) =>
    o.classList.toggle("is-picked", !!picked && o.contains(picked)),
  );
  denial && (denial.hidden = !picked || picked.value !== "denied");
  if (!btn) return;
  btn.disabled = !picked;
  label &&
    (label.textContent = !picked
      ? "Choose a step first"
      : emails
        ? "Send to Admin by email"
        : "Move to " + picked.closest(".next-option").querySelector(".next-option-name").textContent.trim());
}
if (statusForm) {
  statusForm.addEventListener("change", syncNextStep);
  statusForm.addEventListener("submit", async function (e) {
    const picked = statusForm.querySelector('input[name="new_status"]:checked');
    if (!picked) return void e.preventDefault();
    // Compiling -> Sent to Admin is the email to the admin: the server sends it and moves the claim (owner's choice)
    if (picked.value !== "sent_admin" || statusForm.dataset.sendsEmail !== "1") return;
    e.preventDefault();
    const ok = await sendRequirements(true, document.getElementById("claim-notes")?.value);
    ok && (location.href = CLAIM_URL + "&success=" + encodeURIComponent(ok));
  });
  syncNextStep();
}

// Emails the requirements checklist (with the files) to the admin. A compiling claim also moves to Sent to Admin.
// Returns the server's message, or null when cancelled / failed (the error is shown here).
async function sendRequirements(moves, notes) {
  const res = await Swal.fire({
    title: moves ? "Send to Admin?" : "Email the requirements again?",
    html: moves
      ? "This emails the requirements checklist and files to the admin, and moves the claim to <b>Sent to Admin</b>."
      : "This emails the current requirements checklist and files to the admin. The status stays the same.",
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Yes, send",
    cancelButtonText: "Cancel",
  });
  if (!res.isConfirmed) return null;
  window.TGLoader && window.TGLoader.start();
  const fd = new FormData();
  fd.append("ajax_send_admin_email", "1");
  fd.append("csrf_token", CSRF_TOKEN);
  notes != null && fd.append("notes", notes);
  try {
    const data = await (await fetch(CLAIM_URL, { method: "POST", body: fd })).json();
    window.TGLoader && window.TGLoader.done();
    if (!data.ok) {
      Swal.fire({ icon: "error", title: "Failed to Send", text: data.message || "Could not send email. Check SMTP settings." });
      return null;
    }
    return data.message || "Email sent.";
  } catch (err) {
    window.TGLoader && window.TGLoader.done();
    Swal.fire({ icon: "error", title: "Error", text: "An unexpected error occurred." });
    return null;
  }
}
const resendBtn = document.getElementById("btn-send-admin-email");
resendBtn &&
  resendBtn.addEventListener("click", async () => {
    const msg = await sendRequirements(false);
    msg && Swal.fire({ toast: true, position: "top-end", icon: "success", title: msg, showConfirmButton: false, timer: 3000, timerProgressBar: true });
  });
