// ── Field error helpers ──
function showFieldError(inputEl, message) {
  inputEl.classList.add("is-error");
  var wrap = inputEl.closest(".field");
  var existing = wrap.querySelector(".field-error-msg");
  if (existing) existing.remove();
  var msg = document.createElement("div");
  msg.className = "field-error-msg";
  msg.innerHTML =
    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
    "<span>" +
    message +
    "</span>";
  wrap.appendChild(msg);
}

function clearFieldError(inputEl) {
  inputEl.classList.remove("is-error");
  var wrap = inputEl.closest(".field");
  var msg = wrap.querySelector(".field-error-msg");
  if (msg) msg.remove();
}

// ── Autofocus username on page load ──
window.addEventListener("load", function () {
  var u = document.getElementById("username");
  if (u) u.focus();
});

// ── Password toggle ──
document.getElementById("pw-toggle").addEventListener("click", function () {
  var pw = document.getElementById("password");
  var iconShow = document.getElementById("pw-icon-show");
  var iconHide = document.getElementById("pw-icon-hide");
  var visible = pw.type === "password";
  pw.type = visible ? "text" : "password";
  iconShow.style.display = visible ? "none" : "";
  iconHide.style.display = visible ? "" : "none";
});

// ── Clear error as user types ──
["username", "password"].forEach(function (id) {
  var el = document.getElementById(id);
  if (el)
    el.addEventListener("input", function () {
      clearFieldError(this);
    });
});
