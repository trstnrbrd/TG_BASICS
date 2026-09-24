function csrfToken() {
  return window._csrf || "";
}
(document.querySelectorAll(".settings-tab-btn").forEach((tab) => {
  tab.addEventListener("click", () => {
    (document
      .querySelectorAll(".settings-tab-btn")
      .forEach((t) => t.classList.remove("active")),
      document
        .querySelectorAll(".settings-panel")
        .forEach((p) => p.classList.remove("active")),
      tab.classList.add("active"));
    const panel = document.getElementById("panel-" + tab.dataset.tab);
    panel && panel.classList.add("active");
  });
}),
  document.querySelectorAll(".settings-form").forEach((form) => {
    form.addEventListener("submit", (e) => e.preventDefault());
    const btn = form.querySelector(".js-settings-save");
    btn &&
      btn.addEventListener("click", async () => {
        const originalHTML = btn.innerHTML;
        ((btn.disabled = !0),
          (btn.style.opacity = "0.6"),
          (btn.textContent = "Saving..."));
        let data = null;
        try {
          const res = await fetch("settings.php", {
            method: "POST",
            body: new FormData(form),
          });
          data = await res.json();
        } catch (err) {
          data = null;
        }
        ((btn.disabled = !1),
          (btn.style.opacity = ""),
          (btn.innerHTML = originalHTML),
          (document.body.style.cursor = ""),
          data
            ? data.ok
              ? (Swal.fire({
                  icon: "success",
                  title: "Saved!",
                  text: data.message,
                  confirmButtonColor: "#B8860B",
                  timer: 2e3,
                  timerProgressBar: !0,
                }),
                "account" === form.querySelector('[name="section"]').value &&
                  form
                    .querySelectorAll('input[type="password"]')
                    .forEach((p) => (p.value = "")))
              : Swal.fire({
                  icon: "error",
                  title: "Error",
                  text: data.error,
                  confirmButtonColor: "#B8860B",
                })
            : Swal.fire({
                icon: "error",
                title: "Error",
                text: "Something went wrong. Please try again.",
                confirmButtonColor: "#B8860B",
              }));
      });
  }),
  (function () {
    const list = document.getElementById("claim-notify-list"),
      addBtn = document.getElementById("claim-notify-add");
    if (!list || !addBtn) return;
    const users = window._claimNotifyUsers || [];
    function escapeHtml(s) {
      return String(s).replace(
        /[&<>"]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c],
      );
    }
    function renderDropdown(row, query) {
      const dd = row.querySelector(".claim-notify-dropdown"),
        q = (query || "").trim().toLowerCase().replace(/^@/, ""),
        matches = users
          .filter(
            (u) =>
              u.username.toLowerCase().includes(q) ||
              u.label.toLowerCase().includes(q),
          )
          .slice(0, 8);
      (matches.length
        ? (dd.innerHTML = matches
            .map(
              (u) =>
                '<div class="claim-notify-option" data-id="' +
                u.id +
                '" data-username="' +
                escapeHtml(u.username) +
                '" style="padding:0.6rem 0.9rem;font-size:0.82rem;cursor:pointer;color:var(--text-primary);">' +
                escapeHtml(u.label) +
                "</div>",
            )
            .join(""))
        : (dd.innerHTML =
            '<div style="padding:0.65rem 0.9rem;font-size:0.78rem;color:var(--text-muted);">No matching users — this will be saved as a custom email.</div>'),
        (dd.style.display = "block"));
    }
    function hideDropdown(row) {
      row.querySelector(".claim-notify-dropdown").style.display = "none";
    }
    function refreshAddBtn() {
      addBtn.style.display = list.children.length >= 5 ? "none" : "";
    }
    (addBtn.addEventListener("click", function () {
      list.children.length >= 5 ||
        (list.appendChild(
          (function () {
            const row = document.createElement("div");
            return (
              (row.className = "claim-notify-row"),
              (row.style.cssText =
                "position:relative;display:flex;gap:0.5rem;align-items:center;"),
              (row.innerHTML =
                '<div style="position:relative;flex:1;"><input type="text" class="field-input claim-notify-input" autocomplete="off" placeholder="Type an email or search for a user..."><div class="claim-notify-dropdown" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:50;background:var(--bg-3);border:1px solid var(--border);border-radius:9px;box-shadow:var(--shadow-lg);max-height:220px;overflow-y:auto;"></div></div><input type="hidden" name="claim_notify_user_ids[]" class="claim-notify-uid" value=""><input type="hidden" name="claim_notify_emails[]" class="claim-notify-email" value=""><button type="button" class="btn-ghost claim-notify-remove" style="padding:0.55rem;flex-shrink:0;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'),
              row
            );
          })(),
        ),
        refreshAddBtn());
    }),
      list.addEventListener("input", function (e) {
        if (!e.target.classList.contains("claim-notify-input")) return;
        const row = e.target.closest(".claim-notify-row");
        (!(function (row, value) {
          ((row.querySelector(".claim-notify-uid").value = ""),
            (row.querySelector(".claim-notify-email").value = value.trim()));
        })(row, e.target.value),
          renderDropdown(row, e.target.value));
      }),
      list.addEventListener("focusin", function (e) {
        e.target.classList.contains("claim-notify-input") &&
          renderDropdown(e.target.closest(".claim-notify-row"), e.target.value);
      }),
      list.addEventListener("focusout", function (e) {
        if (!e.target.classList.contains("claim-notify-input")) return;
        const row = e.target.closest(".claim-notify-row");
        setTimeout(() => hideDropdown(row), 150);
      }),
      list.addEventListener("mousedown", function (e) {
        const opt = e.target.closest(".claim-notify-option");
        var row, id, username;
        opt &&
          (e.preventDefault(),
          (row = opt.closest(".claim-notify-row")),
          (id = opt.dataset.id),
          (username = opt.dataset.username),
          (row.querySelector(".claim-notify-input").value = "@" + username),
          (row.querySelector(".claim-notify-uid").value = id),
          (row.querySelector(".claim-notify-email").value = ""),
          hideDropdown(row));
      }),
      list.addEventListener("click", async function (e) {
        const btn = e.target.closest(".claim-notify-remove");
        if (!btn) return;
        const row = btn.closest(".claim-notify-row");
        (
          await Swal.fire({
            title: "Remove recipient?",
            text: "They will no longer receive claim requirement emails.",
            icon: "warning",
            showCancelButton: !0,
            confirmButtonColor: "#C0392B",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, remove",
            cancelButtonText: "Cancel",
          })
        ).isConfirmed &&
          (list.children.length <= 1
            ? (function (row) {
                ((row.querySelector(".claim-notify-input").value = ""),
                  (row.querySelector(".claim-notify-uid").value = ""),
                  (row.querySelector(".claim-notify-email").value = ""));
              })(row)
            : (row.remove(), refreshAddBtn()));
      }),
      refreshAddBtn());
  })());
const avatarInput = document.getElementById("avatar-file-input");
avatarInput &&
  avatarInput.addEventListener("change", async function () {
    if (!this.files.length) return;
    const fd = new FormData();
    (fd.append("section", "avatar_upload"),
      fd.append("csrf_token", csrfToken()),
      fd.append("avatar", this.files[0]));
    try {
      const res = await fetch("settings.php", { method: "POST", body: fd }),
        data = await res.json();
      data.ok
        ? (Swal.fire({
            icon: "success",
            title: "Saved!",
            text: data.message,
            confirmButtonColor: "#B8860B",
            timer: 2e3,
            timerProgressBar: !0,
          }),
          setTimeout(() => location.reload(), 600))
        : Swal.fire({
            icon: "error",
            title: "Error",
            text: data.error,
            confirmButtonColor: "#B8860B",
          });
    } catch (err) {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Upload failed. Please try again.",
        confirmButtonColor: "#B8860B",
      });
    }
    this.value = "";
  });
const avatarRemoveBtn = document.getElementById("avatar-remove-btn");
(avatarRemoveBtn &&
  avatarRemoveBtn.addEventListener("click", async function () {
    const fd = new FormData();
    (fd.append("section", "avatar_remove"),
      fd.append("csrf_token", csrfToken()));
    try {
      const res = await fetch("settings.php", { method: "POST", body: fd }),
        data = await res.json();
      data.ok
        ? (Swal.fire({
            icon: "success",
            title: "Saved!",
            text: data.message,
            confirmButtonColor: "#B8860B",
            timer: 2e3,
            timerProgressBar: !0,
          }),
          setTimeout(() => location.reload(), 600))
        : Swal.fire({
            icon: "error",
            title: "Error",
            text: data.error,
            confirmButtonColor: "#B8860B",
          });
    } catch (err) {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Something went wrong.",
        confirmButtonColor: "#B8860B",
      });
    }
  }),
  document.querySelectorAll(".theme-option").forEach((opt) => {
    opt.addEventListener("click", () => {
      (document
        .querySelectorAll(".theme-option")
        .forEach((o) => o.classList.remove("active")),
        opt.classList.add("active"),
        (opt.querySelector('input[type="radio"]').checked = !0));
    });
  }));
const saveDesignBtn = document.getElementById("save-design-btn");
saveDesignBtn &&
  saveDesignBtn.addEventListener("click", async function () {
    const theme =
        document.querySelector('input[name="theme"]:checked')?.value || "light",
      originalHTML = this.innerHTML;
    ((this.disabled = !0),
      (this.style.opacity = "0.6"),
      (this.textContent = "Saving..."));
    const fd = new FormData();
    (fd.append("section", "design_prefs"),
      fd.append("csrf_token", csrfToken()),
      fd.append("theme", theme));
    let data = null;
    try {
      const res = await fetch("settings.php", { method: "POST", body: fd });
      data = await res.json();
    } catch (err) {
      data = null;
    }
    ((this.disabled = !1),
      (this.style.opacity = ""),
      (this.innerHTML = originalHTML),
      (document.body.style.cursor = ""),
      data
        ? data.ok
          ? (Swal.fire({
              icon: "success",
              title: "Saved!",
              text: data.message,
              confirmButtonColor: "#B8860B",
              timer: 2e3,
              timerProgressBar: !0,
            }),
            document.documentElement.setAttribute("data-theme", theme))
          : Swal.fire({
              icon: "error",
              title: "Error",
              text: data.error,
              confirmButtonColor: "#B8860B",
            })
        : Swal.fire({
            icon: "error",
            title: "Error",
            text: "Something went wrong.",
            confirmButtonColor: "#B8860B",
          }));
  });
const tfaToggle = document.getElementById("tfa-toggle");
tfaToggle &&
  tfaToggle.addEventListener("change", async function () {
    const enabled = this.checked ? 1 : 0,
      fd = new FormData();
    (fd.append("section", "2fa_toggle"),
      fd.append("csrf_token", csrfToken()),
      fd.append("enabled", enabled));
    try {
      const res = await fetch("settings.php", { method: "POST", body: fd }),
        data = await res.json();
      if (data.ok) {
        Swal.fire({
          icon: "success",
          title: "Saved!",
          text: data.message,
          confirmButtonColor: "#B8860B",
          timer: 2e3,
          timerProgressBar: !0,
        });
        const status = document.getElementById("tfa-status");
        status &&
          ((status.className = "toggle-status " + (enabled ? "on" : "off")),
          (status.innerHTML = enabled
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Enabled</span>'
            : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Disabled</span>'));
      } else
        ((this.checked = !this.checked),
          Swal.fire({
            icon: "error",
            title: "Error",
            text: data.error,
            confirmButtonColor: "#B8860B",
          }));
    } catch (err) {
      ((this.checked = !this.checked),
        Swal.fire({
          icon: "error",
          title: "Error",
          text: "Something went wrong.",
          confirmButtonColor: "#B8860B",
        }));
    }
  });
const saveUsernameBtn = document.getElementById("save-username-btn");
saveUsernameBtn &&
  saveUsernameBtn.addEventListener("click", async function () {
    const newUsername = document.getElementById("new_username")?.value.trim(),
      curPw = document.getElementById("username_cur_pw")?.value;
    if (!newUsername)
      return void Swal.fire({
        icon: "warning",
        title: "Required",
        text: "Please enter a new username.",
        confirmButtonColor: "#B8860B",
      });
    if (!curPw)
      return void Swal.fire({
        icon: "warning",
        title: "Required",
        text: "Please enter your current password to confirm.",
        confirmButtonColor: "#B8860B",
      });
    const originalHTML = this.innerHTML;
    ((this.disabled = !0),
      (this.style.opacity = "0.6"),
      (this.textContent = "Saving..."));
    const fd = new FormData();
    (fd.append("section", "username"),
      fd.append("csrf_token", csrfToken()),
      fd.append("new_username", newUsername),
      fd.append("current_password", curPw));
    let data = null;
    try {
      const res = await fetch("settings.php", { method: "POST", body: fd });
      data = await res.json();
    } catch (e) {
      data = null;
    }
    ((this.disabled = !1),
      (this.style.opacity = ""),
      (this.innerHTML = originalHTML),
      data
        ? data.ok
          ? Swal.fire({
              icon: "success",
              title: "Username Updated!",
              text: data.message,
              confirmButtonColor: "#B8860B",
              timer: 2500,
              timerProgressBar: !0,
            }).then(() => location.reload())
          : Swal.fire({
              icon: "error",
              title: "Error",
              text: data.error,
              confirmButtonColor: "#B8860B",
            })
        : Swal.fire({
            icon: "error",
            title: "Error",
            text: "Something went wrong.",
            confirmButtonColor: "#B8860B",
          }));
  });
const savePasswordBtn = document.getElementById("save-password-btn");
savePasswordBtn &&
  savePasswordBtn.addEventListener("click", async function () {
    const curPw = document.querySelector('[name="current_password"]')?.value,
      newPw = document.querySelector('[name="new_password"]')?.value,
      cfmPw = document.querySelector('[name="confirm_password"]')?.value;
    if (!curPw)
      return void Swal.fire({
        icon: "warning",
        title: "Required",
        text: "Please enter your current password.",
        confirmButtonColor: "#B8860B",
      });
    if (!newPw)
      return void Swal.fire({
        icon: "warning",
        title: "Required",
        text: "Please enter a new password.",
        confirmButtonColor: "#B8860B",
      });
    if (!cfmPw)
      return void Swal.fire({
        icon: "warning",
        title: "Required",
        text: "Please confirm your new password.",
        confirmButtonColor: "#B8860B",
      });
    const form = savePasswordBtn.closest(".settings-form"),
      originalHTML = this.innerHTML;
    ((this.disabled = !0),
      (this.style.opacity = "0.6"),
      (this.textContent = "Saving..."));
    let data = null;
    try {
      const res = await fetch("settings.php", {
        method: "POST",
        body: new FormData(form),
      });
      data = await res.json();
    } catch (e) {
      data = null;
    }
    ((this.disabled = !1),
      (this.style.opacity = ""),
      (this.innerHTML = originalHTML),
      data
        ? data.ok
          ? (Swal.fire({
              icon: "success",
              title: "Saved!",
              text: data.message,
              confirmButtonColor: "#B8860B",
              timer: 2e3,
              timerProgressBar: !0,
            }),
            form
              .querySelectorAll('input[type="password"]')
              .forEach((p) => (p.value = "")))
          : Swal.fire({
              icon: "error",
              title: "Error",
              text: data.error,
              confirmButtonColor: "#B8860B",
            })
        : Swal.fire({
            icon: "error",
            title: "Error",
            text: "Something went wrong. Please try again.",
            confirmButtonColor: "#B8860B",
          }));
  });
const EYE_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
  EYE_SLASH_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>';
document.querySelectorAll(".field-eye-toggle").forEach((btn) => {
  btn.addEventListener("click", () => {
    const input = document.getElementById(btn.dataset.target);
    if (!input) return;
    const showing = "text" === input.type;
    ((input.type = showing ? "password" : "text"),
      (btn.innerHTML = showing ? EYE_ICON : EYE_SLASH_ICON));
  });
});
