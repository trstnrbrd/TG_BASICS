// Landing page (index.php). Plain JS, no library. Every part checks that its element exists, so a section the
// page leaves out (no testimonials, no hero photos) never stops the rest.
(function () {
  "use strict";
  var doc = document.documentElement;
  var reduce = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ── scroll lock (intro, menu, contact) ──
  var locks = 0;
  function lockScroll() { if (locks++ === 0) { doc.style.overflow = "hidden"; } }
  function unlockScroll() { if (locks > 0 && --locks === 0) { doc.style.overflow = ""; } }

  // ── text splitting: lines slide up out of a clip, words rise one after another ──
  $$("[data-lines]").forEach(function (h) {
    var delay = +(h.dataset.delay || 0), stagger = +(h.dataset.stagger || 0);
    $$(":scope > span", h).forEach(function (line, i) {
      var inner = document.createElement("span");
      inner.className = "lp-line-in";
      inner.style.transitionDelay = (delay + i * stagger) + "ms";
      while (line.firstChild) inner.appendChild(line.firstChild);
      line.appendChild(inner);
    });
  });
  $$("[data-words]").forEach(function (h) {
    var n = 0;
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (c) {
        if (c.nodeType === 3) {
          var frag = document.createDocumentFragment();
          c.textContent.split(/(\s+)/).forEach(function (part) {
            if (!part) return;
            if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
            var w = document.createElement("span");
            w.className = "lp-word";
            w.style.transitionDelay = (n++ * 35) + "ms";
            w.textContent = part;
            frag.appendChild(w);
          });
          node.replaceChild(frag, c);
        } else if (c.nodeType === 1) walk(c);
      });
    })(h);
  });

  // ── scroll reveals ──
  var io = "IntersectionObserver" in window ? new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); } });
  }, { threshold: 0.15, rootMargin: "0px 0px -5% 0px" }) : null;
  function watch(el) { if (io) io.observe(el); else el.classList.add("is-in"); }

  // ── intro, then the above-the-fold reveals ──
  var loader = $("#lp-loader");
  function ready() {
    doc.classList.add("lp-ready");
    $$(".lp-reveal, [data-lines], [data-words]").forEach(watch);
    try { sessionStorage.setItem("tg-intro", "1"); } catch (e) {}
    setTimeout(maybePrivacy, 600);
    startTypewriter();
  }
  if (!loader || doc.classList.contains("lp-no-intro")) {
    if (loader) loader.remove();
    requestAnimationFrame(function () { requestAnimationFrame(ready); });
  } else {
    lockScroll();
    var fill = $("#lp-loader-fill"), num = $("#lp-loader-num"), start = null, FILL_MS = 1300;
    var ease = function (t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
    (function step(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / FILL_MS), p = Math.round(ease(t) * 100);
      fill.style.width = p + "%";
      num.textContent = ("00" + p).slice(-3);
      if (t < 1) return requestAnimationFrame(step);
      loader.classList.add("is-leaving");
      setTimeout(function () { unlockScroll(); loader.remove(); ready(); }, 650);
    })(performance.now());
  }

  // ── typewriter: "Always ready." → "Always accurate." → "Always organized." (starts once the headline is in) ──
  var typeEl = $("#lp-type");
  function startTypewriter() {
    if (!typeEl) return;   // runs with "reduce motion" too, like the old page (only the intro and the brush skip it)
    var words = ["ready.", "accurate.", "organized."], w = 0, c = words[0].length, deleting = true;
    setTimeout(function step() {
      var word = words[w];
      if (deleting) {
        typeEl.textContent = word.slice(0, --c);
        if (c === 0) { deleting = false; w = (w + 1) % words.length; }
        return setTimeout(step, 40);
      }
      typeEl.textContent = words[w].slice(0, ++c);
      if (c === words[w].length) { deleting = true; return setTimeout(step, 1800); }
      setTimeout(step, 80);
    }, 1800);
  }

  // ── clock: Pandi time, whatever the visitor's own time zone ──
  var timeFmt, dateFmt;
  try {
    timeFmt = new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", hour12: true });
    dateFmt = new Intl.DateTimeFormat("en-GB", { timeZone: "Asia/Manila", day: "numeric", month: "long", year: "numeric" });
  } catch (e) {}
  function tick() {
    if (!timeFmt) return;
    var now = new Date();
    var t = timeFmt.format(now).replace(/\s?([AP]M)$/i, function (_, m) { return m.toLowerCase(); });
    var d = dateFmt.formatToParts(now), get = function (k) { var p = d.filter(function (x) { return x.type === k; })[0]; return p ? p.value : ""; };
    $$('[data-clock="time"]').forEach(function (el) { el.textContent = t; });
    $$('[data-clock="date"]').forEach(function (el) { el.textContent = get("day") + " " + get("month") + ", " + get("year"); });
  }
  tick(); setInterval(tick, 1000);

  // ── theme ──
  var themeBtn = $("#theme-toggle");
  if (themeBtn) themeBtn.addEventListener("click", function () {
    var next = doc.getAttribute("data-theme") === "dark" ? "light" : "dark";
    doc.setAttribute("data-theme", next);
    try { localStorage.setItem("tg-theme", next); } catch (e) {}
  });

  // ── overlays (menu, contact): open/close with a fade, Escape, focus back where it was ──
  function overlay(el) {
    if (!el) return { open: function () {}, close: function () {} };
    var back = null, isOpen = false;
    function close() {
      if (!isOpen) return;
      isOpen = false;
      el.classList.remove("is-open");
      unlockScroll();
      setTimeout(function () { if (!isOpen) el.hidden = true; }, 350);
      if (back) back.focus({ preventScroll: true });
    }
    function open() {
      if (isOpen) return;
      isOpen = true;
      back = document.activeElement;
      el.hidden = false;
      lockScroll();
      requestAnimationFrame(function () { requestAnimationFrame(function () { el.classList.add("is-open"); }); });
      var f = $("[data-close]", el); if (f) f.focus({ preventScroll: true });
    }
    $$("[data-close]", el).forEach(function (b) { b.addEventListener("click", close); });
    el.addEventListener("click", function (e) { if (e.target === el) close(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
    return { open: open, close: close, isOpen: function () { return isOpen; } };
  }
  var menu = overlay($("#lp-menu")), contact = overlay($("#lp-contact"));
  var menuBtn = $("#lp-menu-open");
  if (menuBtn) menuBtn.addEventListener("click", menu.open);
  $$("[data-contact]").forEach(function (a) {
    a.addEventListener("click", function (e) { e.preventDefault(); menu.close(); setTimeout(contact.open, menu.isOpen && menu.isOpen() ? 200 : 0); });
  });
  $$("[data-scroll]").forEach(function (a) {
    a.addEventListener("click", function (e) {
      var id = (a.getAttribute("href") || "").slice(1), target = id && document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      menu.close();
      setTimeout(function () {
        window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - 10, behavior: reduce ? "auto" : "smooth" });
        if (history.replaceState) history.replaceState(null, "", "#" + id);
      }, 60);
    });
  });

  // ── active section in the header nav ──
  var navLinks = $$('.lp-nav a[href^="#"]');
  var sections = navLinks.map(function (l) { return document.getElementById(l.getAttribute("href").slice(1)); });
  function setActive() {
    var y = window.pageYOffset + window.innerHeight * 0.35, current = -1;
    sections.forEach(function (s, i) { if (s && s.getBoundingClientRect().top + window.pageYOffset <= y) current = i; });
    navLinks.forEach(function (l, i) { l.classList.toggle("is-active", i === current); });
  }

  // ── hero card: click / next / previous ──
  var card = $("#lp-hero-card");
  if (card) {
    var items = $$(".lp-hero-card-item", card), dots = $$(".lp-hero-dots i", card), logos = $$(".lp-tile-logo", card), at = 0;
    var go = function (step) {
      var prev = items[at];
      at = (at + step + items.length) % items.length;
      prev.classList.remove("is-active"); prev.classList.add("is-out");
      setTimeout(function () { prev.classList.remove("is-out"); }, 450);
      items[at].classList.add("is-active");
      dots.forEach(function (d, i) { d.classList.toggle("is-active", i === at); });
      logos.forEach(function (l) { l.classList.toggle("is-active", l.dataset.logo === items[at].dataset.logo); });
    };
    card.addEventListener("click", function (e) {
      var b = e.target.closest("[data-step]");
      go(b ? +b.dataset.step : 1);
    });
  }

  // ── stats: count up as the panel scrolls in (top of the number at the bottom of the screen → its middle at the middle) ──
  var counters = $$("[data-count]");
  counters.forEach(function (el) { el.textContent = "0"; });
  function counts() {
    var vh = window.innerHeight;
    counters.forEach(function (el) {
      var r = el.getBoundingClientRect(), startY = vh, endY = vh / 2 - r.height / 2;
      var p = Math.max(0, Math.min(1, (startY - r.top) / (startY - endY)));
      el.textContent = Math.round(p * +el.dataset.count);
    });
  }

  // the floating nav gets a more solid glass once the page has scrolled
  var topnav = $("#lp-topnav");
  function navGlass() { if (topnav) topnav.classList.toggle("is-scrolled", window.pageYOffset > 40); }
  navGlass();

  var ticking = false;
  window.addEventListener("scroll", function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; setActive(); counts(); navGlass(); });
  }, { passive: true });
  window.addEventListener("resize", counts);
  setActive(); counts();

  // ── testimonials carousel ──
  var track = $("#testimonial-carousel"), prevBtn = $("#testimonial-prev"), nextBtn = $("#testimonial-next");
  if (track && prevBtn && nextBtn) {
    var stepW = function () {
      var c = $(".testimonial-card", track);
      if (!c) return track.clientWidth;
      var cs = getComputedStyle(track);
      return c.getBoundingClientRect().width + (parseFloat(cs.columnGap || cs.gap) || 0);
    };
    var upd = function () {
      var max = track.scrollWidth - track.clientWidth - 2;
      prevBtn.disabled = track.scrollLeft <= 2;
      nextBtn.disabled = max <= 2 || track.scrollLeft >= max;
    };
    prevBtn.addEventListener("click", function () { track.scrollBy({ left: -stepW(), behavior: "smooth" }); });
    nextBtn.addEventListener("click", function () { track.scrollBy({ left: stepW(), behavior: "smooth" }); });
    track.addEventListener("scroll", upd, { passive: true });
    window.addEventListener("resize", upd);
    upd();
  }

  // ── hero photos: the pointer paints the second photo in a soft brush that fades ──
  var canvas = $("#lp-reveal");
  if (canvas && !reduce && window.matchMedia && matchMedia("(hover: hover)").matches) {
    var ctx = canvas.getContext("2d"), box = canvas.parentElement;
    var img = new Image(), cover = document.createElement("canvas"), brush = document.createElement("canvas");
    var cctx = cover.getContext("2d"), bctx = brush.getContext("2d");
    var BRUSH = 143, DECAY = 0.016, FADE_FRAMES = 120;
    var dpr = Math.min(window.devicePixelRatio || 1, 2), radius = 0, diam = 0, points = [], last = null, idle = FADE_FRAMES + 1, running = false;
    var size = function () {
      var r = box.getBoundingClientRect();
      canvas.width = Math.max(1, Math.round(r.width * dpr)); canvas.height = Math.max(1, Math.round(r.height * dpr));
      cover.width = canvas.width; cover.height = canvas.height;
      radius = BRUSH * dpr; diam = Math.ceil(radius * 2); brush.width = brush.height = diam;
      if (img.complete && img.naturalWidth) {
        var s = Math.max(cover.width / img.naturalWidth, cover.height / img.naturalHeight);
        var w = img.naturalWidth * s, h = img.naturalHeight * s;
        cctx.drawImage(img, (cover.width - w) / 2, (cover.height - h) / 2, w, h);
      }
    };
    var stamp = function (x, y) {
      var c = diam / 2;
      bctx.globalCompositeOperation = "source-over";
      bctx.clearRect(0, 0, diam, diam);
      var g = bctx.createRadialGradient(c, c, 0, c, c, c);
      g.addColorStop(0, "rgba(255,255,255,1)"); g.addColorStop(0.55, "rgba(255,255,255,0.82)"); g.addColorStop(1, "rgba(255,255,255,0)");
      bctx.fillStyle = g; bctx.fillRect(0, 0, diam, diam);
      bctx.globalCompositeOperation = "source-in";
      bctx.drawImage(cover, x - c, y - c, diam, diam, 0, 0, diam, diam);
      ctx.globalCompositeOperation = "source-over";
      ctx.drawImage(brush, x - c, y - c);
    };
    var frame = function () {
      var drawing = points.length > 0;
      idle = drawing ? 0 : idle + 1;
      if (idle > FADE_FRAMES) { ctx.clearRect(0, 0, canvas.width, canvas.height); running = false; return; }
      var fade = drawing ? DECAY : Math.min(DECAY + idle * 0.004, 0.5);
      ctx.globalCompositeOperation = "destination-out";
      ctx.fillStyle = "rgba(0,0,0," + fade + ")";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      if (drawing) { points.forEach(function (p) { stamp(p[0], p[1]); }); points = []; }
      requestAnimationFrame(frame);
    };
    window.addEventListener("pointermove", function (e) {
      if (!img.naturalWidth) return;
      var r = canvas.getBoundingClientRect(), x = (e.clientX - r.left) * dpr, y = (e.clientY - r.top) * dpr;
      if (x < -radius || y < -radius || x > canvas.width + radius || y > canvas.height + radius) { last = null; return; }
      if (last) {
        var dx = x - last[0], dy = y - last[1], dist = Math.sqrt(dx * dx + dy * dy);
        var n = Math.min(Math.ceil(dist / Math.max(radius * 0.3, 1)), 60);
        for (var i = 1; i <= n; i++) points.push([last[0] + dx * i / n, last[1] + dy * i / n]);
      } else points.push([x, y]);
      last = [x, y];
      if (!running) { running = true; idle = 0; requestAnimationFrame(frame); }
    }, { passive: true });
    img.onload = size;
    img.src = canvas.dataset.src;
    if ("ResizeObserver" in window) new ResizeObserver(size).observe(box); else window.addEventListener("resize", size);
  }

  // ── Privacy Notice / Terms / Disclaimer (includes/landing_legal.php): shown once on a first visit ──
  var pm = $("#privacy-modal");
  function maybePrivacy() {
    var seen = false;
    try { seen = !!localStorage.getItem("tg-privacy-seen"); } catch (e) {}
    if (pm && !seen) openPrivacy("privacy");
  }
  function switchTab(tab) {
    ["privacy", "terms", "disclaimer"].forEach(function (t) { var el = document.getElementById("tab-" + t); if (el) el.style.display = t === tab ? "" : "none"; });
    $$(".legal-tab-btn").forEach(function (b) { b.classList.toggle("active", b.dataset.tab === tab); });
    var body = $("#privacy-modal-body"); if (body) body.scrollTop = 0;
  }
  function openPrivacy(tab) {
    if (!pm) return;
    switchTab(tab || "privacy");
    pm.style.display = "flex";
    lockScroll();
    requestAnimationFrame(function () { requestAnimationFrame(function () { pm.classList.add("show"); }); });
  }
  function closePrivacy() {
    if (!pm || !pm.classList.contains("show")) return;
    pm.classList.remove("show");
    unlockScroll();
    try { localStorage.setItem("tg-privacy-seen", "1"); } catch (e) {}
    setTimeout(function () { pm.style.display = "none"; }, 320);
  }
  if (pm) {
    $$(".legal-tab-btn").forEach(function (b) { b.addEventListener("click", function () { switchTab(b.dataset.tab); }); });
    $$(".footer-legal-link").forEach(function (l) { l.addEventListener("click", function (e) { e.preventDefault(); openPrivacy(l.dataset.tab); }); });
    ["close-privacy-modal", "close-privacy-modal-btn"].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener("click", closePrivacy); });
    pm.addEventListener("click", function (e) { if (e.target === pm) closePrivacy(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closePrivacy(); });
  }
})();
