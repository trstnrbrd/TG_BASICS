function showFieldError(el, message) {
  el.classList.add("is-error");
  var wrap = el.closest(".field"),
    old = wrap.querySelector(".field-error-msg");
  old && old.remove();
  var d = document.createElement("div");
  ((d.className = "field-error-msg"),
    (d.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span>' +
      message +
      "</span>"),
    wrap.appendChild(d));
}
function clearFieldError(el) {
  el.classList.remove("is-error");
  var m = el.closest(".field").querySelector(".field-error-msg");
  m && m.remove();
}
var eyeOpen =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
  eyeClosed =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
document.querySelectorAll(".field-eye").forEach(function (btn) {
  btn.addEventListener("click", function () {
    var input = document.getElementById(this.dataset.target);
    if (input) {
      var show = "password" === input.type;
      ((input.type = show ? "text" : "password"),
        (this.innerHTML = show ? eyeClosed : eyeOpen),
        this.setAttribute(
          "aria-label",
          show ? "Hide password" : "Show password",
        ));
    }
  });
});
const pwInput = document.getElementById("new_password"),
  pwBar = document.getElementById("pw-bar"),
  pwHint = document.getElementById("pw-hint");
(pwInput &&
  pwInput.addEventListener("input", function () {
    const val = this.value;
    let strength = 0;
    (val.length >= 8 && strength++,
      val.length >= 12 && strength++,
      /[A-Z]/.test(val) && strength++,
      /[0-9]/.test(val) && strength++,
      /[^A-Za-z0-9]/.test(val) && strength++);
    const levels = [
        { w: "0%", bg: "transparent", label: "At least 8 characters." },
        { w: "25%", bg: "#E74C3C", label: "Weak" },
        { w: "50%", bg: "#E67E22", label: "Fair" },
        { w: "75%", bg: "#F1C40F", label: "Good" },
        { w: "100%", bg: "#2ECC71", label: "Strong" },
      ],
      level = 0 === val.length ? 0 : Math.min(strength, 4);
    ((pwBar.style.width = levels[level].w),
      (pwBar.style.background = levels[level].bg),
      (pwHint.textContent =
        0 === val.length ? "At least 8 characters." : levels[level].label));
  }),
  ["new_password", "confirm_password"].forEach(function (id) {
    var el =
      document.getElementById(id) ||
      document.querySelector('[name="' + id + '"]');
    el &&
      el.addEventListener("input", function () {
        clearFieldError(this);
      });
  }));
var resetForm = document.querySelector("form");
resetForm &&
  resetForm.addEventListener("submit", function (e) {
    var pwEl = document.getElementById("new_password"),
      cfEl = document.querySelector('[name="confirm_password"]');
    if (pwEl && cfEl) {
      (clearFieldError(pwEl), clearFieldError(cfEl));
      var pw = pwEl.value,
        cf = cfEl.value,
        valid = !0;
      (pw
        ? pw.length < 8
          ? (showFieldError(pwEl, "Password must be at least 8 characters."),
            (valid = !1))
          : /[A-Z]/.test(pw)
            ? /[0-9]/.test(pw)
              ? /[^a-zA-Z0-9]/.test(pw) ||
                (showFieldError(
                  pwEl,
                  "Must include at least one special character.",
                ),
                (valid = !1))
              : (showFieldError(pwEl, "Must include at least one number."),
                (valid = !1))
            : (showFieldError(
                pwEl,
                "Must include at least one uppercase letter.",
              ),
              (valid = !1))
        : (showFieldError(pwEl, "Please enter a new password."), (valid = !1)),
        cf
          ? cf !== pw &&
            (showFieldError(cfEl, "Passwords do not match."), (valid = !1))
          : (showFieldError(cfEl, "Please confirm your password."),
            (valid = !1)),
        valid || (e.preventDefault(), e.stopImmediatePropagation()));
    }
  });
