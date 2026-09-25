/* Back buttons, mobile bottom nav / sheets and the page-loading indicator, for every signed-in page. Was inline in
   includes/header.php; loaded right where it was (a plain, blocking script), so everything it defines is still global. */

// ── Back buttons ──
// Each browser tab keeps a short trail of the pages visited (sessionStorage), and "Back" goes to the
// previous DIFFERENT page in it. It used to follow document.referrer, which broke in three ways: after a
// save / filter / undo the page redirects to itself, so the referrer was the same page ("it just
// refreshes"); a reload keeps that referrer; and going A -> B -> back made A's referrer B, so two pages
// bounced between each other. A page is its file + record ids (id, client_id, …) — filters and
// success messages only update the stored link, so going back also restores a list's last filters.
var TG_TRAIL = 'tg_nav_trail';
function tgPageKey(href) {
  var u = new URL(href, location.href), ids = [];
  u.searchParams.forEach(function (v, k) { if (k === 'id' || /_id$/.test(k) || k === 'renew_from') ids.push(k + '=' + v); });
  return u.pathname.toLowerCase() + (ids.length ? '?' + ids.sort().join('&') : '');
}
function tgTrail() {
  try { var t = JSON.parse(sessionStorage.getItem(TG_TRAIL)); return Array.isArray(t) ? t : []; } catch (e) { return []; }
}
function tgRecordPage() {
  var u = new URL(location.href);
  ['success', 'error', 'msg'].forEach(function (p) { u.searchParams.delete(p); });   // one-time toasts
  var key = tgPageKey(u.href), t = tgTrail();
  for (var i = t.length - 1; i >= 0; i--) {
    if (t[i].k === key) { t = t.slice(0, i); break; }   // back on a page already in the trail: drop what came after it
  }
  t.push({ k: key, u: u.pathname + u.search });
  try { sessionStorage.setItem(TG_TRAIL, JSON.stringify(t.slice(-30))); } catch (e) {}
}
// pageshow also fires when the browser's own Back restores a page from its cache
window.addEventListener('pageshow', tgRecordPage);

function goBack(fallback) {
  var t = tgTrail(), key = tgPageKey(location.href), prev = null;
  for (var i = t.length - 1; i >= 0; i--) {
    if (t[i].k === key) { prev = i > 0 ? t[i - 1] : null; break; }
  }
  location.href = prev ? prev.u : fallback;   // opened fresh in a new tab: the page's usual parent
}

function toggleSidebar() {
  const sidebar   = document.getElementById('tg-sidebar');
  const overlay   = document.getElementById('sidebar-overlay');
  const hamburger = document.getElementById('hamburger-btn');
  if (!sidebar) return;
  const isOpen = sidebar.classList.toggle('open');
  overlay.classList.toggle('active', isOpen);
  if (hamburger) hamburger.classList.toggle('open', isOpen);
}

// ── Mobile More Sheet ──
function mobMoreOpen() {
  document.getElementById('mob-more-overlay').classList.add('open');
  document.getElementById('mob-more-sheet').classList.add('open');
}
function mobMoreClose() {
  document.getElementById('mob-more-overlay').classList.remove('open');
  document.getElementById('mob-more-sheet').classList.remove('open');
}
window.mobMoreOpen  = mobMoreOpen;
window.mobMoreClose = mobMoreClose;

// ── Mobile Policy Sheet ──
function mobPolicyOpen() {
  document.getElementById('mob-policy-overlay')?.classList.add('open');
  document.getElementById('mob-policy-sheet')?.classList.add('open');
}
function mobPolicyClose() {
  document.getElementById('mob-policy-overlay')?.classList.remove('open');
  document.getElementById('mob-policy-sheet')?.classList.remove('open');
}
window.mobPolicyOpen  = mobPolicyOpen;
window.mobPolicyClose = mobPolicyClose;

document.addEventListener('DOMContentLoaded', function () {
  const overlay = document.getElementById('sidebar-overlay');
  if (overlay) overlay.addEventListener('click', toggleSidebar);

  // More button
  const moreBtn = document.getElementById('mob-nav-more-btn');
  if (moreBtn) moreBtn.addEventListener('click', mobMoreOpen);

  // Policy button (opens Eligibility/Renewal-by-company/Claims/Billing sheet)
  const policyBtn = document.getElementById('mob-nav-policy-btn');
  if (policyBtn) policyBtn.addEventListener('click', mobPolicyOpen);

  // Profile button (mechanic)
  const profileBtn = document.getElementById('mob-nav-profile-btn');
  if (profileBtn) profileBtn.addEventListener('click', function() {
    window.openEditProfileModal && window.openEditProfileModal();
  });

  // Overlay click closes sheet
  const moreOverlay = document.getElementById('mob-more-overlay');
  if (moreOverlay) moreOverlay.addEventListener('click', mobMoreClose);

  const policyOverlay = document.getElementById('mob-policy-overlay');
  if (policyOverlay) policyOverlay.addEventListener('click', mobPolicyClose);

  // Mobile: tap nav-item with chevron to toggle accordion flyout
  document.querySelectorAll('.nav-item-wrap').forEach(function(wrap) {
    const item = wrap.querySelector('.nav-item');
    const flyout = wrap.querySelector('.nav-flyout');
    if (!item || !flyout) return;
    item.addEventListener('click', function(e) {
      if (window.innerWidth > 768) return; // desktop uses hover
      e.preventDefault();
      wrap.classList.toggle('mob-open');
    });
  });
});

// ── Global Transaction PIN verifier ──
// Usage: const ok = await requirePin(); if (!ok) return;
window.requirePin = async function() {
  const base = document.querySelector('meta[name="base_path"]')?.content || '/TG-BASICS/';
  const endpoint = base + 'ajax/verify_pin.php';

  // Check if user has a PIN (send empty pin = just checking existence)
  let chk;
  try { chk = await fetch(endpoint, { method:'POST', body: new FormData() }).then(r=>r.json()); } catch(e) { chk = null; }
  // Fail closed: if we can't tell whether a PIN is required (network error, expired session,
  // server error), block the action — never treat "unknown" as "no PIN set".
  if (!chk || chk.ok !== true) {
    Swal.fire({ icon:'error', title:'Could not verify PIN', text: chk?.error || 'Please check your connection and try again.', confirmButtonColor:'#B8860B' });
    return false;
  }
  if (chk.no_pin) return true; // account has no PIN set — nothing to ask for

  // Show PIN prompt
  const result = await Swal.fire({
    title: 'Enter Transaction PIN',
    html: '<input id="swal-pin-input" type="password" inputmode="numeric" maxlength="6" class="swal2-input" placeholder="Enter your PIN" autocomplete="off" style="letter-spacing:0.4rem;font-size:1.2rem;text-align:center;"/>',
    confirmButtonText: 'Confirm',
    confirmButtonColor: '#1C1A17',
    cancelButtonText: 'Cancel',
    showCancelButton: true,
    cancelButtonColor: '#6B7280',
    didOpen: () => document.getElementById('swal-pin-input').focus(),
    preConfirm: () => {
      const pin = document.getElementById('swal-pin-input').value.trim();
      if (!pin) { Swal.showValidationMessage('PIN is required.'); return false; }
      return pin;
    }
  });
  if (!result.isConfirmed) return false;

  const vfd = new FormData();
  vfd.append('pin', result.value);
  let vres;
  try { vres = await fetch(endpoint, { method:'POST', body:vfd }).then(r=>r.json()); } catch(e) { return false; }
  if (vres?.ok) return true;
  Swal.fire({ icon:'error', title:'Incorrect PIN', text: vres?.error || 'Wrong PIN.', confirmButtonColor:'#B8860B' });
  return false;
};

// Loading cursor — show on navigation, hide when page is fully ready
(function () {
  document.documentElement.classList.add('tg-loading');
  window.addEventListener('load', function () {
    document.documentElement.classList.remove('tg-loading');
  });
  // Also trigger on link/form navigation
  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href]');
    if (a && !a.target && !a.href.startsWith('#') && !a.href.startsWith('javascript')) {
      if (a.closest('.user-dropdown')) return;
      if (e.defaultPrevented) return;
      document.documentElement.classList.add('tg-loading');
    }
  });
  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented) document.documentElement.classList.add('tg-loading');
  });
})();
