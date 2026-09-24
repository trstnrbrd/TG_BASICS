// Insurance-agent filter menu (includes/agent_filter.php) — Client Records and Renewal Tracking.
// Picking an agent shows that agent's list right away (the page's other filters stay as they are).
// Keyboard: arrows move, Enter picks, Esc closes.
(function () {
  var wrap = document.getElementById("agent-filter-wrap");
  if (!wrap) return;
  var btn = document.getElementById("agent-filter-btn");
  var menu = document.getElementById("agent-filter-menu");
  var sel = document.getElementById("agent-filter");
  var opts = [].slice.call(menu.querySelectorAll(".cl-agent-opt"));

  function openMenu() {
    menu.hidden = false;
    wrap.classList.add("open");
    btn.setAttribute("aria-expanded", "true");
    (menu.querySelector(".is-selected") || opts[0]).focus();
  }
  function closeMenu(focusBtn) {
    if (menu.hidden) return;
    menu.hidden = true;
    wrap.classList.remove("open");
    btn.setAttribute("aria-expanded", "false");
    if (focusBtn) btn.focus();
  }
  btn.addEventListener("click", function () {
    menu.hidden ? openMenu() : closeMenu(true);
  });
  btn.addEventListener("keydown", function (e) {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      openMenu();
    }
  });
  opts.forEach(function (o) {
    o.addEventListener("click", function () {
      if (o.classList.contains("is-selected")) {
        closeMenu(true);
        return;
      }
      sel.value = o.dataset.value;
      closeMenu();
      sel.form.submit();
    });
  });
  menu.addEventListener("keydown", function (e) {
    var i = opts.indexOf(document.activeElement);
    if (e.key === "ArrowDown") {
      e.preventDefault();
      opts[Math.min(i + 1, opts.length - 1)].focus();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      opts[Math.max(i - 1, 0)].focus();
    } else if (e.key === "Escape") {
      closeMenu(true);
    } else if (e.key === "Tab") {
      closeMenu();
    }
  });
  document.addEventListener("click", function (e) {
    if (!wrap.contains(e.target)) closeMenu();
  });
})();
