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
