// Sidebar: the gold highlight of the active section (Client Records, Policy, Claims ...) slides to the
// section that was clicked, then the page opens. A click on a submenu link (Policy > Renewal ...) slides
// to its section. Uses Motion (window.Motion), read at click time: includes/footer.php loads it after
// the page's own scripts when the page did not already (dashboard, monthly report, renewal list do).
// Motion not loaded (CDN down) or no active section: the link simply opens, as before.
// Windows "Animation effects" OFF (the shop PC): a short, gentle slide, as on the dashboard.
(function () {
  var nav = document.querySelector(".sidebar-nav");
  if (!nav) return;

  function topItem(el) {
    var wrap = el.closest(".sidebar-nav > .nav-item-wrap");
    return wrap ? wrap.querySelector(":scope > .nav-item") : null;
  }
  function box(el) {
    var n = nav.getBoundingClientRect(), r = el.getBoundingClientRect();
    return { y: r.top - n.top + nav.scrollTop, h: r.height };
  }

  var home = nav.querySelector(".sidebar-nav > .nav-item-wrap > .nav-item.active");   // this page's section
  var pill = null, going = false;

  // Listens on the nav, so the mobile accordion handler on the item itself (layout.js) runs first:
  // when it keeps the tap to open a submenu, e.defaultPrevented is set and nothing slides.
  nav.addEventListener("click", function (e) {
    var link = e.target.closest("a.nav-item, a.nav-flyout-item");
    if (!link || e.defaultPrevented || going) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.target) return;
    var href = link.getAttribute("href") || "";
    if (href === "" || href.charAt(0) === "#" || href.indexOf("javascript") === 0) return;

    var M = window.Motion;
    var to = topItem(link);
    if (!M || typeof M.animate !== "function" || !home || !to || to === home) return;   // open as usual

    e.preventDefault();
    going = true;
    var gentle = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
    var a = box(home), b = box(to);

    pill = document.createElement("span");
    pill.className = "nav-slide-pill";
    pill.setAttribute("aria-hidden", "true");
    pill.style.height = a.h + "px";
    pill.style.transform = "translateY(" + a.y + "px)";
    nav.insertBefore(pill, nav.firstChild);
    nav.classList.add("is-sliding");
    home.classList.remove("active");
    to.classList.add("active");   // the text turns gold right away, the highlight follows

    var opened = false;
    function open() {
      if (opened) return;
      opened = true;
      document.documentElement.classList.add("tg-loading");   // the usual page-loading bar
      window.location.href = link.href;
    }
    var anim = M.animate(pill, { y: [a.y, b.y], height: [a.h, b.h] },
      gentle ? { duration: 0.22, ease: "easeOut" } : { type: "spring", stiffness: 380, damping: 32, mass: 0.8 });
    if (anim && anim.finished && anim.finished.then) anim.finished.then(open, open);
    else if (anim && anim.then) anim.then(open, open);
    setTimeout(open, 500);   // never wait longer than this for the page to open
  });

  // Back button (page restored from the browser cache): this page's section is active again
  window.addEventListener("pageshow", function (e) {
    if (!e.persisted || !pill) return;
    pill.remove(); pill = null; going = false;
    nav.classList.remove("is-sliding");
    nav.querySelectorAll(".sidebar-nav > .nav-item-wrap > .nav-item.active").forEach(function (el) { el.classList.remove("active"); });
    if (home) home.classList.add("active");
    document.documentElement.classList.remove("tg-loading");
  });
})();
