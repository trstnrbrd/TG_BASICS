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

    chip.querySelector(".tg-ic-go").addEventListener("click", async function () {
      if (!deferred) return;
      deferred.prompt();
      await deferred.userChoice;
      deferred = null;
      chip.remove();
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
