// Motion for the Admin Dashboard and the Monthly Report (motion.dev — the page loads its UMD build, which
// defines window.Motion, before this file). Owner's request, 2026-09-24.
//
//   tgChartInView(canvasId, make)  build a Chart.js chart only when its card scrolls into view, so its own
//                                  grow / sweep animation is actually seen. make(animate) creates the chart;
//                                  animate is false only when printing.
//   tgRiseInView(selector)         cards fade + slide up with a soft spring as they come into view
//   tgCountUp(selector)            numbers count up from 0 when they come into view
//   tgWhenInView(el, fn)           run fn once el is on screen (e.g. the payment-status bars)
//   tgHoverLift(selector)          cards lift slightly on hover (spring)
//
// "Reduce motion" (Windows: Settings > Accessibility > Visual effects > Animation effects OFF — the shop PC
// had it off, and the owner still wants the dashboard animated): a GENTLE version instead of none — cards
// only fade in (no sliding), no hover lift; charts, bars and counters still animate as they always did.
// If Motion failed to load (CDN down), everything is simply shown, with Chart.js's own animation.
(function () {
  var M = window.Motion;
  var gentle = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  var on = !!(M && M.animate && M.inView);
  var soft = { type: "spring", stiffness: 260, damping: 28 }; // settles without a wobble

  var pendingCharts = [];
  var hiddenCards = [];

  window.tgChartInView = function (canvasId, make) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var job = { done: false, run: function (animate) { if (!job.done) { job.done = true; make(animate); } } };
    if (!on) { job.run(true); return; }
    pendingCharts.push(job);
    M.inView(canvas.closest(".card") || canvas, function () { job.run(true); }, { amount: 0.3 });
  };

  window.tgRiseInView = function (selector) {
    if (!on) return;
    [].slice.call(document.querySelectorAll(selector)).forEach(function (el) {
      el.style.opacity = "0";
      hiddenCards.push(el);
      M.inView(el, function () {
        // cards side by side in one grid row come in one after another
        var col = Array.prototype.indexOf.call(el.parentNode.children, el) % 4;
        var anim = gentle
          ? M.animate(el, { opacity: [0, 1] }, { duration: 0.45, ease: "easeOut", delay: col * 0.07 })
          : M.animate(el, { opacity: [0, 1], y: [18, 0] }, Object.assign({ delay: col * 0.07 }, soft));
        // hand the card's style back to the CSS — a frame later, after Motion writes its final values
        anim.then(function () { requestAnimationFrame(function () { el.style.opacity = ""; el.style.transform = ""; }); });
      }, { amount: 0.15 });
    });
  };

  window.tgCountUp = function (selector) {
    [].slice.call(document.querySelectorAll(selector)).forEach(function (el) {
      var target = parseInt(el.textContent.replace(/[^\d-]/g, ""), 10);
      if (!on || isNaN(target) || target === 0) return;
      var text = el.textContent;
      el.textContent = "0";
      M.inView(el, function () {
        M.animate(0, target, { duration: 0.9, ease: "easeOut", onUpdate: function (v) { el.textContent = Math.round(v); } })
          .then(function () { el.textContent = text; });
      }, { amount: 0.5 });
    });
  };

  window.tgWhenInView = function (el, fn) {
    if (!el) return;
    if (!on) { fn(); return; }
    M.inView(el, function () { fn(); }, { amount: 0.3 });
  };

  window.tgHoverLift = function (selector) {
    if (!on || gentle || !M.hover) return;
    [].slice.call(document.querySelectorAll(selector)).forEach(function (el) {
      M.hover(el, function () {
        M.animate(el, { y: -3 }, soft);
        return function () { M.animate(el, { y: 0 }, soft); };
      });
    });
  };

  // Printing (Monthly Report) before scrolling down: show every card and draw every chart still waiting
  window.addEventListener("beforeprint", function () {
    hiddenCards.forEach(function (el) { el.style.opacity = ""; el.style.transform = ""; });
    pendingCharts.forEach(function (job) { job.run(false); });
  });
})();
