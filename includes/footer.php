<?php
/**
 * includes/footer.php
 * Usage: require_once 'path/to/includes/footer.php';
 * Optional variables:
 *   $footer_scripts - string of extra inline JS to run after React loads
 *   $base_path      - string, path back to root
 */
$base_path      = $base_path      ?? '../';
$footer_scripts = $footer_scripts ?? '';
?>

<!-- React + Babel CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>

<script type="text/babel">
  const { useState, useEffect, useRef } = React;

  // ── TOAST SYSTEM ──
  // Usage from any page: window.showToast('message', 'success' | 'danger' | 'warning' | 'info')
  function ToastContainer() {
    const [toasts, setToasts] = useState([]);

    useEffect(() => {
      window.showToast = (message, type = 'success') => {
        const id = Date.now();
        setToasts(prev => [...prev, { id, message, type }]);
        setTimeout(() => {
          setToasts(prev => prev.filter(t => t.id !== id));
        }, 3500);
      };
    }, []);

    const icons = {
      success: '✓',
      danger:  '✕',
      warning: '⚠',
      info:    'ℹ',
    };

    const colors = {
      success: { bg: 'var(--success-bg)', border: 'var(--success-border)', color: 'var(--success)' },
      danger:  { bg: 'var(--danger-bg)',  border: 'var(--danger-border)',  color: 'var(--danger)' },
      warning: { bg: 'var(--warning-bg)', border: 'var(--warning-border)', color: 'var(--warning)' },
      info:    { bg: 'var(--info-bg)',    border: 'var(--info-border)',    color: 'var(--info)' },
    };

    return (
      <div style={{
        position: 'fixed', top: '1.5rem', right: '1.5rem',
        zIndex: 9999, display: 'flex', flexDirection: 'column', gap: '0.5rem',
        pointerEvents: 'none',
      }}>
        {toasts.map(t => {
          const c = colors[t.type] || colors.info;
          return (
            <div key={t.id} style={{
              display: 'flex', alignItems: 'center', gap: '0.6rem',
              background: c.bg, border: `1px solid ${c.border}`, color: c.color,
              padding: '0.75rem 1.25rem', borderRadius: '10px',
              fontSize: '0.8rem', fontWeight: '600',
              fontFamily: "'Plus Jakarta Sans', sans-serif",
              boxShadow: '0 8px 24px rgba(0,0,0,0.1)',
              animation: 'toastSlideIn 0.3s ease forwards',
              pointerEvents: 'auto', maxWidth: '340px', lineHeight: '1.4',
            }}>
              <span style={{ fontSize: '0.9rem', flexShrink: 0 }}>{icons[t.type]}</span>
              <span>{t.message}</span>
            </div>
          );
        })}
      </div>
    );
  }

  // Mount toast container on every page
  const toastRoot = document.getElementById('toast-root');
  if (toastRoot) {
    ReactDOM.createRoot(toastRoot).render(<ToastContainer />);
  }

  // ── EXPIRY BADGE - fetch urgent policies count ──
  // Shows red badge on Renewal Tracking nav item if any urgent policies exist
  async function loadExpiryBadge() {
    try {
      const res  = await fetch('<?= $base_path ?>modules/renewal/get_urgent_count.php');
      const data = await res.json();
      const badge = document.getElementById('expiry-badge');
      if (badge && data.count > 0) {
        badge.textContent = data.count;
        badge.style.display = 'inline-flex';
      }
    } catch(e) {
      // Fail silently if renewal module not yet built
    }
  }

  loadExpiryBadge();

</script>

<?php if ($footer_scripts): ?>
<script type="text/babel">
  <?= $footer_scripts ?>
</script>
<?php endif; ?>

<style>
  @keyframes toastSlideIn {
    from { opacity: 0; transform: translateX(16px); }
    to   { opacity: 1; transform: translateX(0); }
  }
</style>

<!-- TOAST MOUNT POINT -->
<div id="toast-root"></div>

</body>
</html>