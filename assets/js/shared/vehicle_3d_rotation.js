document.querySelectorAll(".car3d-wrap").forEach(function (wrap) {
  var img = wrap.querySelector(".car3d-img"),
    hint = wrap.querySelector(".car3d-hint");
  if (img && "none" !== img.style.display) {
    var make = wrap.dataset.make,
      model = wrap.dataset.model,
      year = wrap.dataset.year,
      paint = wrap.dataset.paint,
      lastAngle = 13,
      loadingAngle = null,
      dragging = !1,
      startX = 0,
      accumDx = 0,
      preloaded = {};
    ((preloaded[lastAngle] = !0),
      preloadAdjacent(lastAngle),
      wrap.addEventListener("mousedown", function (e) {
        ((dragging = !0),
          (startX = e.clientX),
          (accumDx = 0),
          (wrap.style.cursor = "grabbing"),
          hint && (hint.style.display = "none"),
          e.preventDefault());
      }),
      document.addEventListener("mousemove", function (e) {
        if (dragging) {
          ((accumDx += e.clientX - startX), (startX = e.clientX));
          var steps = Math.trunc(accumDx / 60);
          if (0 !== steps) {
            accumDx -= 60 * steps;
            var newAngle = (((lastAngle - steps) % 36) + 36) % 36;
            (setAngle(newAngle), preloadAdjacent(newAngle));
          }
        }
      }),
      document.addEventListener("mouseup", function () {
        dragging &&
          ((dragging = !1), (accumDx = 0), (wrap.style.cursor = "grab"));
      }));
    var touchStartX = 0,
      touchAccum = 0;
    (wrap.addEventListener(
      "touchstart",
      function (e) {
        ((touchStartX = e.touches[0].clientX),
          (touchAccum = 0),
          hint && (hint.style.display = "none"));
      },
      { passive: !0 },
    ),
      wrap.addEventListener(
        "touchmove",
        function (e) {
          ((touchAccum += e.touches[0].clientX - touchStartX),
            (touchStartX = e.touches[0].clientX));
          var steps = Math.trunc(touchAccum / 60);
          if (0 !== steps) {
            touchAccum -= 60 * steps;
            var newAngle = (((lastAngle - steps) % 36) + 36) % 36;
            (setAngle(newAngle), preloadAdjacent(newAngle));
          }
        },
        { passive: !0 },
      ));
  }
  function buildUrl(a) {
    return (
      "https://cdn.imagin.studio/getimage?customer=hrjavascript-mastery&make=" +
      encodeURIComponent(make) +
      "&modelFamily=" +
      encodeURIComponent(model) +
      (year ? "&modelYear=" + year : "") +
      (paint ? "&paintdescription=" + encodeURIComponent(paint) : "") +
      "&zoomType=fullscreen&angle=" +
      a
    );
  }
  function setAngle(a) {
    if ((a = ((a % 36) + 36) % 36) !== lastAngle) {
      ((lastAngle = a), (loadingAngle = a));
      var url = buildUrl(a);
      if (preloaded[a]) img.src = url;
      else {
        img.style.opacity = "0.5";
        var tmp = new Image();
        ((tmp.onload = function () {
          ((preloaded[a] = !0),
            loadingAngle === a && ((img.src = url), (img.style.opacity = "1")));
        }),
          (tmp.onerror = function () {
            img.style.opacity = "1";
          }),
          (tmp.src = url));
      }
    }
  }
  function preloadAdjacent(a) {
    [-2, -1, 1, 2].forEach(function (d) {
      var na = (((a + d) % 36) + 36) % 36;
      if (!preloaded[na]) {
        var t = new Image();
        ((t.onload = function () {
          preloaded[na] = !0;
        }),
          (t.src = buildUrl(na)));
      }
    });
  }
});
