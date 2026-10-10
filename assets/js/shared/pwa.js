/* Registers the service worker and offers an "Install app" button when the browser allows installing.
 * Loaded by the shared header, the login page and the landing page.
 *
 * The app's base path comes from this script's own URL (…/assets/js/shared/pwa.js), so it works wherever the
 * app is deployed — /TG-BASICS/ locally and on the current host, or a domain root later.
 */
(function () {
  if (!("serviceWorker" in navigator)) return;
  const me = document.currentScript && document.currentScript.src;
  if (!me) return;
  const base = new URL("../../../", me); // assets/js/shared/ → app root

  window.addEventListener("load", function () {
    navigator.serviceWorker.register(new URL("sw.js", base).href, { scope: base.pathname }).catch(function () {});
  });

  const DISMISS_KEY = "tg-install-dismissed";
  let deferred = null;

  function dismissed() {
    try {
      return localStorage.getItem(DISMISS_KEY) === "1";
    } catch (e) {
      return false;
    }
  }

  function isStandalone() {
    return window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  }

  // The browser only hands the page its install permission (beforeinstallprompt) a few seconds after load,
  // and never at all on iOS Safari or Firefox. A click that lands before it arrives waits briefly for it —
  // staying inside the click's few seconds of user activation, which prompt() needs.
  function waitForPrompt(ms) {
    return new Promise(function (resolve) {
      if (deferred) return resolve(true);
      const timer = setTimeout(function () {
        window.removeEventListener("beforeinstallprompt", arrived);
        resolve(!!deferred);
      }, ms);
      function arrived() {
        clearTimeout(timer);
        window.removeEventListener("beforeinstallprompt", arrived);
        setTimeout(function () { resolve(!!deferred); }, 0); // after the capture listener below has stored it
      }
      window.addEventListener("beforeinstallprompt", arrived);
    });
  }

  // When no permission comes, the exact menu steps for this browser, so nobody has to go looking.
  function instructionsHtml() {
    const ua = navigator.userAgent;
    const list = function (steps) {
      return "<ol style='text-align:left;margin:0;padding-left:1.2em;line-height:1.75'>" +
        steps.map(function (s) { return "<li>" + s + "</li>"; }).join("") + "</ol>";
    };
    const already = "<p style='text-align:left;margin:0.9em 0 0;font-size:0.9em;opacity:0.75'>Already installed it? " +
      "Open <strong>TG-BASICS</strong> from your Start menu, dock or home screen instead.</p>";

    if (/iPad|iPhone|iPod/.test(ua) || (ua.includes("Macintosh") && navigator.maxTouchPoints > 1)) {
      return list([
        "Tap the <strong>Share</strong> button (the square with an arrow pointing up)",
        "Scroll down and tap <strong>Add to Home Screen</strong>",
        "Tap <strong>Add</strong>",
      ]) + already;
    }
    if (/Android/.test(ua)) {
      if (/SamsungBrowser/.test(ua)) {
        return list(["Tap the <strong>☰</strong> menu at the bottom right", "Tap <strong>Add page to</strong> → <strong>Home screen</strong>"]) + already;
      }
      return list(["Tap the <strong>⋮</strong> menu at the top right", "Tap <strong>Install app</strong> (or <strong>Add to Home screen</strong>)"]) + already;
    }
    if (/Firefox\//.test(ua)) {
      return "<p style='text-align:left;margin:0;line-height:1.6'>Firefox can't install web apps on a computer. " +
        "Open TG-BASICS in <strong>Chrome</strong>, <strong>Edge</strong> or <strong>Brave</strong> and use the " +
        "<strong>Install App</strong> option there.</p>";
    }
    if (/Edg\//.test(ua)) {
      return list(["Click the <strong>⋯</strong> menu at the top right", "Choose <strong>Apps</strong> → <strong>Install this site as an app</strong>", "Click <strong>Install</strong>"]) + already;
    }
    if (navigator.brave) {
      return list(["Click the <strong>☰</strong> menu at the top right", "Choose <strong>Save and share</strong> → <strong>Install page as app…</strong>", "Click <strong>Install</strong>"]) + already;
    }
    return list(["Click the <strong>⋮</strong> menu at the top right", "Choose <strong>Cast, save and share</strong> → <strong>Install page as app…</strong>", "Click <strong>Install</strong>"]) + already;
  }

  function showInstructions() {
    if (window.Swal) {
      Swal.fire({
        title: "Install TG-BASICS",
        html: instructionsHtml(),
        confirmButtonText: "Got it",
        confirmButtonColor: "#D4A017",
      });
    } else {
      alert("To install: open your browser menu and choose \"Install app\" or \"Add to Home screen\".");
    }
  }

  // Shared entry point — the floating chip and the permanent "Install App" item in the user menu both call this.
  window.TG_PWA = {
    isInstalled: isStandalone,
    canPrompt: () => !!deferred,
    install: async function () {
      if (isStandalone()) {
        if (window.showToast) showToast("TG-BASICS is already installed on this device.", "info");
        else alert("TG-BASICS is already installed on this device.");
        return;
      }
      if (!deferred) await waitForPrompt(2500);
      if (deferred) {
        // One click, then the browser's own "Install TG-BASICS?" confirmation — the most direct any site is allowed.
        const ev = deferred;
        deferred = null; // a captured prompt can only be shown once
        ev.prompt();
        await ev.userChoice;
        const chip = document.getElementById("tg-install-chip");
        if (chip) chip.remove();
        return;
      }
      showInstructions();
    },
  };

  function showChip() {
    if (document.getElementById("tg-install-chip") || dismissed()) return;
    const chip = document.createElement("div");
    chip.id = "tg-install-chip";
    chip.setAttribute("role", "dialog");
    chip.setAttribute("aria-label", "Install TG-BASICS");
    chip.innerHTML =
      '<img src="' + new URL("assets/img/pwa/icon-192.png", base).href + '" alt=""/>' +
      '<div class="tg-ic-text"><strong>Install TG-BASICS</strong><span>Open it like an app, right from your home screen</span></div>' +
      '<button type="button" class="tg-ic-go">Install</button>' +
      '<button type="button" class="tg-ic-x" aria-label="Not now">&times;</button>';
    document.body.appendChild(chip);

    chip.querySelector(".tg-ic-go").addEventListener("click", function () {
      window.TG_PWA.install();
    });
    chip.querySelector(".tg-ic-x").addEventListener("click", function () {
      try {
        localStorage.setItem(DISMISS_KEY, "1");
      } catch (e) {}
      chip.remove();
    });
  }

  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault(); // keep the browser's mini-bar from popping up on its own; the chip offers it instead
    deferred = e;
    showChip();
  });

  window.addEventListener("appinstalled", function () {
    deferred = null;
    const chip = document.getElementById("tg-install-chip");
    if (chip) chip.remove();
  });

  const css = document.createElement("style");
  css.textContent =
    "#tg-install-chip{position:fixed;left:16px;bottom:16px;z-index:2000;display:flex;align-items:center;gap:12px;" +
    "max-width:calc(100vw - 32px);padding:10px 10px 10px 12px;border-radius:14px;background:#1A1814;color:#F4F1EC;" +
    "border:1px solid rgba(212,160,23,.45);box-shadow:0 14px 34px rgba(0,0,0,.35);font-family:'Plus Jakarta Sans','Segoe UI',sans-serif;" +
    "animation:tgIcIn .35s ease-out}" +
    "#tg-install-chip img{width:38px;height:38px;border-radius:10px;flex-shrink:0}" +
    "#tg-install-chip .tg-ic-text{display:flex;flex-direction:column;min-width:0}" +
    "#tg-install-chip strong{font-size:.82rem}" +
    "#tg-install-chip span{font-size:.7rem;color:rgba(244,241,236,.6)}" +
    "#tg-install-chip .tg-ic-go{border:none;border-radius:9px;padding:8px 14px;background:#D4A017;color:#1A1814;font:inherit;font-weight:700;font-size:.78rem;cursor:pointer}" +
    "#tg-install-chip .tg-ic-x{border:none;background:none;color:rgba(244,241,236,.55);font-size:1.3rem;line-height:1;cursor:pointer;padding:0 4px}" +
    "@media (max-width:768px){#tg-install-chip{bottom:84px}}" + // clear the mobile bottom nav
    "@keyframes tgIcIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}";
  document.head.appendChild(css);
})();
