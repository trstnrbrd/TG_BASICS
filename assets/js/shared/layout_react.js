/* Toast notifications (window.showToast), the renewal-expiry badge, expandable table rows, the topbar clock,
 * and the topbar user dropdown — for every signed-in page.
 *
 * This was inline JSX in includes/header.php, run through babel-standalone in the browser on every single page
 * load (2026-09-25: dropped — babel-standalone alone was several times heavier than React + ReactDOM combined,
 * and it was compiling this same unchanging code on every page view for no reason). Precompiled once with
 * @babel/standalone (classic runtime, so it needs no separate JSX-runtime import) into the React.createElement()
 * calls below; behavior is unchanged, this is a mechanical transform. React and ReactDOM (UMD) still load — this
 * file calls their hooks and createRoot() same as before.
 *
 * To edit the actual components: change the JSX source kept in git history at includes/header.php (pre-2026-09-25),
 * or write new plain React.createElement() calls directly here — do not reintroduce a browser Babel dependency
 * for a one-off change.
 */
// Deferred to DOMContentLoaded (2026-09-25 note): several of the elements this mounts into (#toast-root and
// others in includes/footer.php) sit further down in the HTML than this <script> tag, so at parse time they
// do not exist yet. The old inline <script type="text/babel"> version worked anyway because babel-standalone
// itself defers compiling and running text/babel blocks until DOMContentLoaded — this listener reproduces that
// exact timing for the precompiled version instead of relying on this file's position in the page.
document.addEventListener("DOMContentLoaded", function () {
const { useState, useEffect, useRef } = React;

// ── TOAST SYSTEM ──
// Usage from any page: window.showToast('message', 'success' | 'danger' | 'warning' | 'info')
function ToastContainer() {
  const [toasts, setToasts] = useState([]);

  useEffect(() => {
    window.showToast = (message, type = 'success') => {
      const id = Date.now();
      setToasts((prev) => [...prev, { id, message, type }]);
      setTimeout(() => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
      }, 3500);
    };
  }, []);

  const icons = {
    success: '✓',
    danger: '✕',
    warning: '⚠',
    info: 'ℹ'
  };

  const colors = {
    success: { bg: 'var(--success-bg)', border: 'var(--success-border)', color: 'var(--success)' },
    danger: { bg: 'var(--danger-bg)', border: 'var(--danger-border)', color: 'var(--danger)' },
    warning: { bg: 'var(--warning-bg)', border: 'var(--warning-border)', color: 'var(--warning)' },
    info: { bg: 'var(--info-bg)', border: 'var(--info-border)', color: 'var(--info)' }
  };

  return (/*#__PURE__*/
    React.createElement("div", { style: {
        position: 'fixed', top: '1.5rem', right: '1.5rem',
        zIndex: 9999, display: 'flex', flexDirection: 'column', gap: '0.5rem',
        pointerEvents: 'none'
      } },
    toasts.map((t) => {
      const c = colors[t.type] || colors.info;
      return (/*#__PURE__*/
        React.createElement("div", { key: t.id, style: {
            display: 'flex', alignItems: 'center', gap: '0.6rem',
            background: c.bg, border: `1px solid ${c.border}`, color: c.color,
            padding: '0.75rem 1.25rem', borderRadius: '10px',
            fontSize: '0.8rem', fontWeight: '600',
            fontFamily: "'Plus Jakarta Sans', sans-serif",
            boxShadow: '0 8px 24px rgba(0,0,0,0.1)',
            animation: 'toastSlideIn 0.3s ease forwards',
            pointerEvents: 'auto', maxWidth: '340px', lineHeight: '1.4'
          } }, /*#__PURE__*/
        React.createElement("span", { style: { fontSize: '0.9rem', flexShrink: 0 } }, icons[t.type]), /*#__PURE__*/
        React.createElement("span", null, t.message)
        ));

    })
    ));

}

// Mount toast container on every page
const toastRoot = document.getElementById('toast-root');
if (toastRoot) {
  ReactDOM.createRoot(toastRoot).render(/*#__PURE__*/React.createElement(ToastContainer, null));
}

// ── EXPIRY BADGE - fetch urgent policies count ──
// Shows red badge on Renewal Tracking nav item if any urgent policies exist
async function loadExpiryBadge() {
  try {
    // Now a static file (was PHP-interpolated inline JSX) — same base_path source assets/js/shared/layout.js uses.
    const base = document.querySelector('meta[name="base_path"]')?.content || '/TG-BASICS/';
    const res = await fetch(base + 'modules/renewal/get_urgent_count.php');
    const data = await res.json();
    if (data.count > 0) {
      const badge = document.getElementById('expiry-badge');
      if (badge) {badge.textContent = data.count;badge.style.display = 'inline-flex';}
      const mobBadge = document.getElementById('mob-expiry-badge');
      if (mobBadge) {mobBadge.textContent = data.count;mobBadge.style.display = 'inline-flex';}
    }
  } catch (e) {

    // Fail silently if renewal module not yet built
  }}

loadExpiryBadge();

// ── ROW EXPAND toggle ──
document.addEventListener('click', function (e) {
  const row = e.target.closest('.tg-expandable-row');
  if (!row) return;
  const expandId = row.dataset.expand;
  const expandRow = expandId ? document.getElementById(expandId) : null;
  if (!expandRow) return;
  const isOpen = expandRow.style.display !== 'none';
  expandRow.style.display = isOpen ? 'none' : 'table-row';
  row.classList.toggle('expanded', !isOpen);
});

// Keyboard equivalent — Enter/Space on a focused expandable row triggers the same toggle.
// Checks e.target directly (not .closest) so Enter/Space on a link or button *inside*
// the row still does its own native action instead of also re-toggling the row.
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  if (!e.target.classList || !e.target.classList.contains('tg-expandable-row')) return;
  e.preventDefault();
  e.target.click();
});

// ── TOPBAR CLOCK ──
(function () {
  function updateClock() {
    const now = new Date();
    const timeEl = document.getElementById('topbar-time');
    const dateEl = document.getElementById('topbar-date');
    if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: false });
    if (dateEl) dateEl.textContent = now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }
  updateClock();
  setInterval(updateClock, 60000);
})();

// ── USER DROPDOWN ──
function UserDropdown({ fullName, initials, role, username, basePath, photo }) {
  const [open, setOpen] = useState(false);
  const [closing, setClosing] = useState(false);
  const wrapRef = useRef(null);

  const handleClose = () => {
    setClosing(true);
    setTimeout(() => {setOpen(false);setClosing(false);}, 150);
  };

  const handleToggle = () => {
    if (open) handleClose();else
    setOpen(true);
  };

  useEffect(() => {
    const handler = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) handleClose();
    };
    if (open) document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  return (/*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-wrap", ref: wrapRef }, /*#__PURE__*/
    React.createElement("div", { className: "user-chip", onClick: handleToggle }, /*#__PURE__*/
    React.createElement("div", { className: "user-avatar" },
    photo ? /*#__PURE__*/React.createElement("img", { src: photo, alt: "", style: { width: '100%', height: '100%', objectFit: 'cover', borderRadius: '50%' } }) : initials
    ), /*#__PURE__*/
    React.createElement("span", { className: "user-chip-label" }, fullName, " — ", role), /*#__PURE__*/
    React.createElement("span", { className: `user-chip-chevron ${open ? 'open' : ''}` }, /*#__PURE__*/
    React.createElement("svg", { width: "12", height: "12", viewBox: "0 0 24 24", fill: "none",
      stroke: "currentColor", strokeWidth: "2", strokeLinecap: "round", strokeLinejoin: "round" }, /*#__PURE__*/
    React.createElement("polyline", { points: "6 9 12 15 18 9" })
    )
    )
    ),

    open && /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown",
      style: { animation: `${closing ? 'dropdownOut' : 'dropdownIn'} 0.18s ease forwards` } }, /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-header" }, /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-avatar" },
    photo ? /*#__PURE__*/React.createElement("img", { src: photo, alt: "", style: { width: '100%', height: '100%', objectFit: 'cover', borderRadius: '50%' } }) : initials
    ), /*#__PURE__*/
    React.createElement("div", null, /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-name" }, fullName), /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-meta" }, "@", username, " \xB7 ", role)
    )
    ), /*#__PURE__*/
    React.createElement("div", { className: "user-dropdown-menu" }, /*#__PURE__*/
    React.createElement("button", { className: "user-dropdown-item",
      onClick: (e) => {handleClose();setTimeout(() => window.openEditProfileModal && window.openEditProfileModal(), 50);} }, /*#__PURE__*/
    React.createElement("svg", { width: "15", height: "15", viewBox: "0 0 24 24", fill: "none",
      stroke: "currentColor", strokeWidth: "1.75", strokeLinecap: "round", strokeLinejoin: "round" }, /*#__PURE__*/
    React.createElement("path", { d: "M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" }), /*#__PURE__*/
    React.createElement("circle", { cx: "12", cy: "7", r: "4" })
    ), "Edit Profile"

    ), /*#__PURE__*/
    React.createElement("a", { href: `${basePath}modules/admin/settings.php`, className: "user-dropdown-item", onClick: handleClose }, /*#__PURE__*/
    React.createElement("svg", { width: "15", height: "15", viewBox: "0 0 24 24", fill: "none",
      stroke: "currentColor", strokeWidth: "1.75", strokeLinecap: "round", strokeLinejoin: "round" }, /*#__PURE__*/
    React.createElement("circle", { cx: "12", cy: "12", r: "3" }), /*#__PURE__*/
    React.createElement("path", { d: "M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" })
    ), "Settings"

    ), /*#__PURE__*/
    React.createElement("div", { style: { height: '1px', background: 'var(--border)', margin: '0.3rem 0' } }), /*#__PURE__*/
    React.createElement("a", { href: `${basePath}auth/logout.php`, className: "user-dropdown-item", style: { color: 'var(--danger)' }, onClick: handleClose }, /*#__PURE__*/
    React.createElement("svg", { width: "15", height: "15", viewBox: "0 0 24 24", fill: "none",
      stroke: "currentColor", strokeWidth: "1.75", strokeLinecap: "round", strokeLinejoin: "round" }, /*#__PURE__*/
    React.createElement("path", { d: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" }), /*#__PURE__*/
    React.createElement("polyline", { points: "16 17 21 12 16 7" }), /*#__PURE__*/
    React.createElement("line", { x1: "21", y1: "12", x2: "9", y2: "12" })
    ), "Logout"

    )
    )
    )

    ));

}

// Mount user dropdown
const userDropdownRoot = document.getElementById('user-dropdown-root');
if (userDropdownRoot) {
  const { name, initials, role, username, base, photo } = userDropdownRoot.dataset;
  ReactDOM.createRoot(userDropdownRoot).render(/*#__PURE__*/
    React.createElement(UserDropdown, {
      fullName: name,
      initials: initials,
      role: role,
      username: username,
      basePath: base,
      photo: photo || '' }
    )
  );
}
});
