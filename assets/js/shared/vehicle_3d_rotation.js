// 3D Car Image Rotation — drag/swipe to spin via Imagin Studio API
(function() {
  var BASE_URL = 'https://cdn.imagin.studio/getimage';
  var TOTAL_ANGLES = 36;
  var PX_PER_ANGLE = 60; // pixels of drag needed to advance one angle step

  document.querySelectorAll('.car3d-wrap').forEach(function(wrap) {
    var img      = wrap.querySelector('.car3d-img');
    var hint     = wrap.querySelector('.car3d-hint');
    if (!img || img.style.display === 'none') return;

    var make  = wrap.dataset.make;
    var model = wrap.dataset.model;
    var year  = wrap.dataset.year;
    var paint = wrap.dataset.paint;
    var lastAngle    = 13;
    var loadingAngle = null;
    var dragging     = false;
    var startX       = 0;
    var accumDx      = 0; // accumulated drag delta

    function buildUrl(a) {
      return BASE_URL
        + '?customer=hrjavascript-mastery'
        + '&make='        + encodeURIComponent(make)
        + '&modelFamily=' + encodeURIComponent(model)
        + (year  ? '&modelYear='         + year                      : '')
        + (paint ? '&paintdescription='  + encodeURIComponent(paint) : '')
        + '&zoomType=fullscreen'
        + '&angle=' + a;
    }

    function setAngle(a) {
      a = ((a % TOTAL_ANGLES) + TOTAL_ANGLES) % TOTAL_ANGLES;
      if (a === lastAngle) return;
      lastAngle    = a;
      loadingAngle = a;
      var url = buildUrl(a);
      if (preloaded[a]) { img.src = url; return; }
      img.style.opacity = '0.5';
      var tmp = new Image();
      tmp.onload = function() {
        preloaded[a] = true;
        if (loadingAngle === a) { img.src = url; img.style.opacity = '1'; }
      };
      tmp.onerror = function() { img.style.opacity = '1'; };
      tmp.src = url;
    }

    var preloaded = {};

    function preloadAdjacent(a) {
      [-2, -1, 1, 2].forEach(function(d) {
        var na = ((a + d) % TOTAL_ANGLES + TOTAL_ANGLES) % TOTAL_ANGLES;
        if (!preloaded[na]) {
          var t = new Image();
          t.onload = function() { preloaded[na] = true; };
          t.src = buildUrl(na);
        }
      });
    }

    preloaded[lastAngle] = true;
    preloadAdjacent(lastAngle);

    // ── Mouse ──
    wrap.addEventListener('mousedown', function(e) {
      dragging = true;
      startX   = e.clientX;
      accumDx  = 0;
      wrap.style.cursor = 'grabbing';
      if (hint) hint.style.display = 'none';
      e.preventDefault();
    });
    document.addEventListener('mousemove', function(e) {
      if (!dragging) return;
      accumDx += e.clientX - startX;
      startX   = e.clientX;
      var steps = Math.trunc(accumDx / PX_PER_ANGLE);
      if (steps !== 0) {
        accumDx -= steps * PX_PER_ANGLE;
        var newAngle = ((lastAngle - steps) % TOTAL_ANGLES + TOTAL_ANGLES) % TOTAL_ANGLES;
        setAngle(newAngle);
        preloadAdjacent(newAngle);
      }
    });
    document.addEventListener('mouseup', function() {
      if (dragging) { dragging = false; accumDx = 0; wrap.style.cursor = 'grab'; }
    });

    // ── Touch ──
    var touchStartX = 0;
    var touchAccum  = 0;
    wrap.addEventListener('touchstart', function(e) {
      touchStartX = e.touches[0].clientX;
      touchAccum  = 0;
      if (hint) hint.style.display = 'none';
    }, { passive: true });
    wrap.addEventListener('touchmove', function(e) {
      touchAccum  += e.touches[0].clientX - touchStartX;
      touchStartX  = e.touches[0].clientX;
      var steps = Math.trunc(touchAccum / PX_PER_ANGLE);
      if (steps !== 0) {
        touchAccum -= steps * PX_PER_ANGLE;
        var newAngle = ((lastAngle - steps) % TOTAL_ANGLES + TOTAL_ANGLES) % TOTAL_ANGLES;
        setAngle(newAngle);
        preloadAdjacent(newAngle);
      }
    }, { passive: true });
  });
})();
