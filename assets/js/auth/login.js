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

// ── Left panel: floating rings + mouse parallax ──
(function () {
  var panel = document.querySelector(".auth-left");
  var rings = document.querySelectorAll(".auth-deco");
  if (!panel || !rings.length) return;

  var mx = 0, my = 0, lx = 0, ly = 0, hovering = false;
  var t0 = performance.now();

  // Per-ring config: [float amplitude, float speed, x-ratio, phase offset]
  var cfg = [
    [14, 0.00080, 0.35, 0.0],
    [10, 0.00055, 0.45, 2.1],
    [ 8, 0.00095, 0.30, 4.2],
    [ 6, 0.00065, 0.50, 1.0],
  ];
  // Parallax depth (px shift at full mouse deflection)
  var depth = [28, 18, 12, 36];

  panel.addEventListener("mousemove", function (e) {
    var r = panel.getBoundingClientRect();
    mx = (e.clientX - r.left  - r.width  / 2) / (r.width  / 2);
    my = (e.clientY - r.top   - r.height / 2) / (r.height / 2);
    hovering = true;
  });
  panel.addEventListener("mouseleave", function () { hovering = false; });

  function tick(now) {
    var t  = now - t0;
    var tx = hovering ? mx : 0;
    var ty = hovering ? my : 0;
    // Smooth lerp toward target
    lx += (tx - lx) * 0.07;
    ly += (ty - ly) * 0.07;

    rings.forEach(function (ring, i) {
      var c  = cfg[i];
      var fy = Math.sin(t * c[1] + c[3]) * c[0];
      var fx = Math.cos(t * c[1] * c[2] + c[3]) * (c[0] * 0.4);
      var px = lx * depth[i];
      var py = ly * depth[i];
      ring.style.transform =
        "translate(" + (fx + px).toFixed(2) + "px," + (fy + py).toFixed(2) + "px)";
    });

    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
})();
