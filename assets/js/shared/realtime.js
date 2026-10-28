// Live updates. When someone else saves, every open page that shows that kind of record reloads itself, so what is
// on screen is never stale. The server sends only a signal ("claims changed") through Pusher, never client data
// (includes/realtime.php). Pusher down or not set up: ajax/live.php is asked every 20 seconds instead.
//
// A reload never costs the user their work: every form field they changed (also ones filled in by the OCR scan),
// the scroll position and the focused field are saved just before and put back after. Fields they did not touch
// are NOT put back: they come from the fresh data, so a stale value never overwrites someone else's change.
// It only holds off while the user is typing, a dialog is open, a file is chosen, or the tab is hidden.
(function () {
  var cfg = window.TG_LIVE;
  if (!cfg) return;

  // This tab's id, kept while the tab moves from page to page. Sent with every save (form field / fetch header),
  // so the tab that saved ignores its own signal: it already shows the change, and a reload would drop its
  // "saved" message. Other tabs, even the same user's, still update.
  var tab = "";
  try { tab = sessionStorage.getItem("tg_live_tab") || ""; } catch (e) {}
  if (!/^[a-z0-9]{8,32}$/.test(tab)) {
    tab = (Math.random().toString(36).slice(2) + Date.now().toString(36)).replace(/[^a-z0-9]/g, "").slice(0, 24);
    try { sessionStorage.setItem("tg_live_tab", tab); } catch (e) {}
  }
  window.TG_LIVE_tab = function () { return tab; };
  function stamp(form) {
    if (!form || String(form.method).toLowerCase() !== "post" || form.querySelector('input[name="_live_tab"]')) return;
    var i = document.createElement("input");
    i.type = "hidden"; i.name = "_live_tab"; i.value = tab;
    form.appendChild(i);
  }
  document.addEventListener("submit", function (e) { stamp(e.target); }, true);
  var nativeSubmit = HTMLFormElement.prototype.submit; // form.submit() skips the submit event
  HTMLFormElement.prototype.submit = function () { stamp(this); return nativeSubmit.call(this); };

  // Which signals matter to this page, by module folder (server side: realtime_topic_for_script())
  var WATCH = {
    clients: "*", admin: "*", repair: ["repairs", "quotations", "clients"], quotations: ["quotations", "repairs", "clients"],
    insurance: ["policies", "clients"], renewal: ["policies", "clients"], claims: ["claims", "policies", "billing", "clients"],
    billing: ["billing", "claims", "clients"],
  };
  var folder = (location.pathname.match(/\/modules\/([a-z_]+)\//i) || [])[1] || "";
  var watch = WATCH[folder] || "*";
  var relevant = function (topic) { return watch === "*" || !topic || watch.indexOf(topic) !== -1; };

  // ── save / restore the user's work around a reload ──
  var STORE = "tg_live_restore";
  var SKIP_NAMES = /^(csrf_token|_live_tab)$/;
  var norm = function (v) { return String(v).replace(/[,\s]+/g, ""); };   // "500,000" is the same as "500000"
  var fieldsOf = function () { return Array.prototype.slice.call(document.querySelectorAll("input, select, textarea")); };
  var keyOf = function (el) {
    var name = el.name || "";
    if (!name) return el.id ? { id: el.id } : null;
    var f = el.form ? Array.prototype.indexOf.call(document.forms, el.form) : -1;
    var same = Array.prototype.filter.call(f >= 0 ? el.form.elements : document.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]'), function (x) { return x.name === name; });
    return { f: f, n: name, i: Math.max(0, same.indexOf(el)) };
  };
  var findEl = function (k) {
    if (k.id) return document.getElementById(k.id);
    var scope = k.f >= 0 && document.forms[k.f] ? document.forms[k.f].elements : document.querySelectorAll('[name="' + k.n.replace(/"/g, '\\"') + '"]');
    var same = Array.prototype.filter.call(scope, function (x) { return x.name === k.n; });
    return same[k.i] || null;
  };
  // Fields the user changed: different from what the server sent (tracking, not events, so values set by the
  // OCR scan or other scripts count too)
  function snapshot() {
    var rec = [];
    fieldsOf().forEach(function (el) {
      var t = (el.type || "").toLowerCase();
      if (/^(password|file|submit|button|reset|image)$/.test(t) || SKIP_NAMES.test(el.name || "")) return;
      var k = keyOf(el);
      if (!k) return;
      if (t === "checkbox" || t === "radio") {
        if (el.checked !== el.defaultChecked) rec.push({ k: k, t: "c", v: el.checked, rv: el.value });
      } else if (el.tagName === "SELECT") {
        var def = -1;
        Array.prototype.forEach.call(el.options, function (o, n) { if (o.defaultSelected) def = n; });
        if (def === -1) def = 0;
        if (!el.multiple && el.selectedIndex !== def) rec.push({ k: k, t: "s", v: el.value });
      } else if (norm(el.value) !== norm(el.defaultValue)) {
        rec.push({ k: k, t: t === "hidden" ? "h" : "v", v: el.value });
      }
    });
    var a = document.activeElement, focus = null;
    if (a && /^(INPUT|TEXTAREA)$/.test(a.tagName) && keyOf(a)) {
      focus = { k: keyOf(a), s: a.selectionStart, e: a.selectionEnd };
    }
    return { href: location.href, at: Date.now(), y: window.scrollY, rec: rec, focus: focus };
  }
  function fire(el, type) { el.dispatchEvent(new Event(type, { bubbles: true })); }
  function apply(rec, phase) {
    rec.forEach(function (r) {
      var el = findEl(r.k);
      if (!el) return;
      if (phase === 1 && (r.t === "s" || r.t === "c")) {         // choices first: they can rebuild parts of the form
        if (r.t === "s") { if (el.value !== r.v) { el.value = r.v; fire(el, "change"); } }
        else if (el.type === "radio") {
          if (r.v) { var g = document.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]'); Array.prototype.forEach.call(g, function (x) { if (x.value === r.rv && !x.checked) { x.checked = true; fire(x, "change"); } }); }
        } else if (el.checked !== r.v) { el.checked = r.v; fire(el, "change"); }
      } else if (phase === 2 && (r.t === "v" || r.t === "h")) {  // typed text, once the form is built
        if (el.value !== r.v) { el.value = r.v; if (r.t === "v") fire(el, "input"); }
      } else if (phase === 3 && r.t === "v") {                    // late-built rows only: never overwrite what is there
        if (el.value === "" && r.v !== "" && document.activeElement !== el) { el.value = r.v; fire(el, "input"); }
      }
    });
  }
  function restore() {
    var raw = null, data = null;
    try { raw = sessionStorage.getItem(STORE); sessionStorage.removeItem(STORE); } catch (e) {}
    try { data = raw ? JSON.parse(raw) : null; } catch (e) {}
    if (!data || data.href !== location.href || Date.now() - data.at > 60000) return;
    var scroll = function () { if (data.y) window.scrollTo(0, data.y); };
    var go = function () {
      apply(data.rec, 1);
      setTimeout(function () {
        apply(data.rec, 2); scroll();
        if (data.focus) {
          var f = findEl(data.focus.k);
          if (f) { f.focus(); try { f.setSelectionRange(data.focus.s, data.focus.e); } catch (e) {} }
        }
      }, 500);
      setTimeout(function () { apply(data.rec, 3); scroll(); }, 1800);
    };
    if (document.readyState === "complete") go(); else window.addEventListener("load", go);
  }
  restore();

  // ── is it safe to reload right now? ──
  var lastKeyAt = 0;
  document.addEventListener("keydown", function () { lastKeyAt = Date.now(); }, true);
  document.addEventListener("input", function () { lastKeyAt = Date.now(); }, true);
  function modalOpen() {
    if (document.querySelector(".swal2-container")) return true;
    var els = document.querySelectorAll('[id$="-modal"], [id$="-lightbox"], [role="dialog"]');
    for (var i = 0; i < els.length; i++) {
      if (els[i].offsetParent !== null && getComputedStyle(els[i]).display !== "none") return true;
    }
    return false;
  }
  var fileChosen = function () { return fieldsOf().some(function (el) { return el.type === "file" && el.files && el.files.length; }); };
  var busy = function () { return Date.now() - lastKeyAt < 2000 || modalOpen() || fileChosen(); };

  // ── reload when safe ──
  var pending = false;
  function reload() {
    var url = new URL(location.href);
    ["success", "error", "msg"].forEach(function (k) { url.searchParams.delete(k); }); // one-time toasts
    var snap = snapshot();
    snap.href = url.toString();
    try { sessionStorage.setItem(STORE, JSON.stringify(snap)); sessionStorage.setItem("tg_live_last", String(Date.now())); } catch (e) {}
    // tells the server this load is not the user's activity, so it never holds off the idle sign-out
    document.cookie = "tg_live_reload=1; max-age=30; path=/; samesite=strict";
    location.replace(url.toString());
  }
  function tryReload() {
    if (!pending || document.hidden || busy()) return;
    var last = 0;
    try { last = parseInt(sessionStorage.getItem("tg_live_last") || "0", 10); } catch (e) {}
    if (Date.now() - last < 2500) return;   // a burst of saves: one reload, not a flicker
    reload();
  }
  function changed(topic) {
    if (!relevant(topic)) return;
    pending = true;
    tryReload();
  }
  setInterval(tryReload, 1000);
  document.addEventListener("visibilitychange", tryReload);
  window.TG_LIVE_changed = changed; // for tests

  // ── backup: ask the server every 20 s while Pusher is not connected ──
  var lastSeen = null, pollTimer = null, ownSaveUntil = 0;
  // the poll can't tell whose change it sees: right after this tab saves through fetch(), take it as ours
  window.TG_LIVE_ownSave = function () { ownSaveUntil = Date.now() + (cfg.pollMs || 20000) + 5000; };
  function poll() {
    if (document.hidden) return;
    fetch(cfg.base + "ajax/live.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        if (lastSeen !== null && j.latest > lastSeen && Date.now() > ownSaveUntil) changed("");
        lastSeen = j.latest;
      })
      .catch(function () {});
  }
  function polling(on) {
    if (on && !pollTimer) { poll(); pollTimer = setInterval(poll, cfg.pollMs || 20000); }
    if (!on && pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ── Pusher ──
  if (!cfg.key || !window.Pusher) return polling(true);
  var pusher = new window.Pusher(cfg.key, { cluster: cfg.cluster });
  pusher.subscribe("tg-live").bind("changed", function (d) {
    if (d && d.tab && d.tab === tab) return; // our own save
    changed(d && d.topic);
  });
  window.TG_LIVE_connected = function () { return pusher.connection.state === "connected"; };
  var downTimer = null;
  pusher.connection.bind("state_change", function (s) {
    if (s.current === "connected") { clearTimeout(downTimer); downTimer = null; polling(false); }
    else if (!downTimer && !pollTimer) downTimer = setTimeout(function () { downTimer = null; polling(true); }, 5000);
  });
  pusher.connection.bind("unavailable", function () { polling(true); });
  pusher.connection.bind("failed", function () { polling(true); });
})();
