// Renewal Tracking company switch (PhilBritish / Alpha Insurance ...): a gold pill sits behind the active
// company and, on a click, slides to the chosen one with a spring before that company's list opens.
// Uses Motion (motion.dev UMD build, window.Motion, loaded by the page before this file).
// Nothing changes if Motion did not load (CDN down): the options keep their own CSS look and the links
// open at once. Windows "Animation effects" OFF (prefers-reduced-motion; the shop PC has it off and the
// owner still wants motion, as on the dashboard): a GENTLE slide, short and without the spring's bounce.
(function () {
  var nav = document.querySelector(".rnl-company-switch");
  var M = window.Motion;
  if (!nav || !M || typeof M.animate !== "function") return;
  var gentle = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  var slide = gentle ? { duration: 0.22, ease: "easeOut" }
                     : { type: "spring", stiffness: 420, damping: 32, mass: 0.8 };

  var opts = Array.prototype.slice.call(nav.querySelectorAll(".rnl-company-opt"));
  var home = nav.querySelector(".rnl-company-opt.is-active");   // the company this page shows
  if (opts.length < 2 || !home) return;

  var pill = document.createElement("span");
  pill.className = "rnl-company-pill";
  pill.setAttribute("aria-hidden", "true");
  nav.insertBefore(pill, nav.firstChild);
  nav.classList.add("has-pill");

  var current = home, going = false;

  function box(el) { return { x: el.offsetLeft, y: el.offsetTop, w: el.offsetWidth, h: el.offsetHeight }; }
  function place(el) {
    var b = box(el);
    pill.style.transform = "translateX(" + b.x + "px)";
    pill.style.width = b.w + "px";
    pill.style.height = b.h + "px";
    pill.style.top = b.y + "px";
  }
  function mark(el) {
    opts.forEach(function (o) { o.classList.toggle("is-active", o === el); });
    current = el;
  }
  place(home);

  opts.forEach(function (opt) {
    opt.addEventListener("click", function (e) {
      // Let new-tab / new-window clicks behave normally
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      if (going || opt === current) return;
      going = true;

      var from = box(current), to = box(opt), href = opt.href;
      mark(opt);   // the text turns gold right away, the pill follows
      var opened = false;
      function open() { if (!opened) { opened = true; window.location.href = href; } }

      var anim = M.animate(pill, { x: [from.x, to.x], width: [from.w, to.w] }, slide);
      if (anim && anim.finished && anim.finished.then) anim.finished.then(open, open);
      else if (anim && anim.then) anim.then(open, open);
      setTimeout(open, 450);   // never wait longer than this for the page to open
    });
  });

  // Back button (page restored from the browser cache): put the pill back on this page's company
  window.addEventListener("pageshow", function (e) {
    if (!e.persisted) return;
    going = false; mark(home); place(home);
  });
  // Window resized or fonts finished loading: widths changed, keep the pill under the active company
  window.addEventListener("resize", function () { if (!going) place(current); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { if (!going) place(current); });
})();
