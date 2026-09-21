!(function () {
  document.querySelectorAll(".dash-stat-value").forEach(function (el) {
    var target = parseInt(el.textContent, 10);
    if (!isNaN(target) && 0 !== target) {
      var start = null;
      ((el.textContent = "0"),
        requestAnimationFrame(function step(ts) {
          start || (start = ts);
          var progress = Math.min((ts - start) / 900, 1),
            ease = 1 - Math.pow(1 - progress, 3);
          ((el.textContent = Math.floor(ease * target)),
            progress < 1
              ? requestAnimationFrame(step)
              : (el.textContent = target));
        }));
    }
  });
})();
