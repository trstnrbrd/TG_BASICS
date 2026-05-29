// ── PASSWORD VISIBILITY TOGGLE ──
var eyeOpen   = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
var eyeClosed = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

document.querySelectorAll('.field-eye').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var input = document.getElementById(this.dataset.target);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.innerHTML = show ? eyeClosed : eyeOpen;
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});

const pwInput = document.getElementById("new_password");
const pwBar = document.getElementById("pw-bar");
const pwHint = document.getElementById("pw-hint");

if (pwInput) {
  pwInput.addEventListener("input", function () {
    const val = this.value;
    let strength = 0;
    if (val.length >= 8) strength++;
    if (val.length >= 12) strength++;
    if (/[A-Z]/.test(val)) strength++;
    if (/[0-9]/.test(val)) strength++;
    if (/[^A-Za-z0-9]/.test(val)) strength++;

    const levels = [
      { w: "0%", bg: "transparent", label: "At least 8 characters." },
      { w: "25%", bg: "#E74C3C", label: "Weak" },
      { w: "50%", bg: "#E67E22", label: "Fair" },
      { w: "75%", bg: "#F1C40F", label: "Good" },
      { w: "100%", bg: "#2ECC71", label: "Strong" },
    ];

    const level = val.length === 0 ? 0 : Math.min(strength, 4);
    pwBar.style.width = levels[level].w;
    pwBar.style.background = levels[level].bg;
    pwHint.textContent =
      val.length === 0 ? "At least 8 characters." : levels[level].label;
  });
}
