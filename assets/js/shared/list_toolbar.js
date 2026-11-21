// List toolbar (assets/css/shared/list_toolbar.css): filter pills apply as soon as they change, and the ⋯ menu
// opens on click, closes on a click outside or Escape, and is keyboard-usable (arrow keys move between items).
(function () {
  document.querySelectorAll("form.list-toolbar").forEach(function (form) {
    form.querySelectorAll(".lt-pill select").forEach(function (sel) {
      sel.addEventListener("change", function () {
        form.submit();
      });
    });
  });

  document.querySelectorAll(".lt-more").forEach(function (wrap) {
    var btn = wrap.querySelector(".lt-more-btn");
    var menu = wrap.querySelector(".lt-menu");
    if (!btn || !menu) return;
    var items = function () {
      return Array.prototype.slice.call(menu.querySelectorAll("a, button"));
    };
    var setOpen = function (open, focusFirst) {
      wrap.classList.toggle("open", open);
      menu.hidden = !open;
      btn.setAttribute("aria-expanded", open ? "true" : "false");
      if (open && focusFirst && items()[0]) items()[0].focus();
    };
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      setOpen(menu.hidden, e.detail === 0); // keyboard (Enter/Space) puts focus on the first item
    });
    // capture phase: some tables stop clicks from bubbling (Client Records' rows), which must still close it
    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) setOpen(false);
    }, true);
    wrap.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !menu.hidden) {
        setOpen(false);
        btn.focus();
      } else if ((e.key === "ArrowDown" || e.key === "ArrowUp") && !menu.hidden) {
        e.preventDefault();
        var list = items();
        var i = list.indexOf(document.activeElement);
        var next = e.key === "ArrowDown" ? (i + 1) % list.length : (i - 1 + list.length) % list.length;
        list[next].focus();
      }
    });
  });
})();
