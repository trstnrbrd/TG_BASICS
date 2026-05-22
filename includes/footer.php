<?php
/**
 * includes/footer.php
 * Usage: require_once 'path/to/includes/footer.php';
 * Optional variables:
 *   $footer_scripts - string of extra inline JS to run after React loads
 *   $base_path      - string, path back to root
 */
$base_path             = $base_path             ?? '../';
$footer_scripts        = $footer_scripts        ?? '';
$footer_extra_scripts  = $footer_extra_scripts  ?? '';
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
      if (data.count > 0) {
        const badge = document.getElementById('expiry-badge');
        if (badge) { badge.textContent = data.count; badge.style.display = 'inline-flex'; }
        const mobBadge = document.getElementById('mob-expiry-badge');
        if (mobBadge) { mobBadge.textContent = data.count; mobBadge.style.display = 'inline-flex'; }
      }
    } catch(e) {
      // Fail silently if renewal module not yet built
    }
  }

  loadExpiryBadge();

  // ── ROW EXPAND toggle ──
  document.addEventListener('click', function(e) {
    const row = e.target.closest('.tg-expandable-row');
    if (!row) return;
    const expandId = row.dataset.expand;
    const expandRow = expandId ? document.getElementById(expandId) : null;
    if (!expandRow) return;
    const isOpen = expandRow.style.display !== 'none';
    expandRow.style.display = isOpen ? 'none' : 'table-row';
    row.classList.toggle('expanded', !isOpen);
  });

  // ── TOPBAR CLOCK ──
  (function() {
    function updateClock() {
      const now = new Date();
      const timeEl = document.getElementById('topbar-time');
      const dateEl = document.getElementById('topbar-date');
      if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      if (dateEl) dateEl.textContent = now.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }
    updateClock();
    setInterval(updateClock, 1000);
  })();

  // ── USER DROPDOWN ──
  function UserDropdown({ fullName, initials, role, username, basePath, photo }) {
    const [open, setOpen] = useState(false);
    const [closing, setClosing] = useState(false);
    const wrapRef = useRef(null);

    const handleClose = () => {
      setClosing(true);
      setTimeout(() => { setOpen(false); setClosing(false); }, 150);
    };

    const handleToggle = () => {
      if (open) handleClose();
      else setOpen(true);
    };

    useEffect(() => {
      const handler = (e) => {
        if (wrapRef.current && !wrapRef.current.contains(e.target)) handleClose();
      };
      if (open) document.addEventListener('mousedown', handler);
      return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    return (
      <div className="user-dropdown-wrap" ref={wrapRef}>
        <div className="user-chip" onClick={handleToggle}>
          <div className="user-avatar">
            {photo ? <img src={photo} alt="" style={{width:'100%',height:'100%',objectFit:'cover',borderRadius:'50%'}}/> : initials}
          </div>
          <span className="user-chip-label">{fullName} &mdash; {role}</span>
          <span className={`user-chip-chevron ${open ? 'open' : ''}`}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </span>
        </div>

        {open && (
          <div className="user-dropdown"
            style={{ animation: `${closing ? 'dropdownOut' : 'dropdownIn'} 0.18s ease forwards` }}>
            <div className="user-dropdown-header">
              <div className="user-dropdown-avatar">
                {photo ? <img src={photo} alt="" style={{width:'100%',height:'100%',objectFit:'cover',borderRadius:'50%'}}/> : initials}
              </div>
              <div>
                <div className="user-dropdown-name">{fullName}</div>
                <div className="user-dropdown-meta">@{username} &middot; {role}</div>
              </div>
            </div>
            <div className="user-dropdown-menu">
              <button className="user-dropdown-item"
                onClick={(e) => { handleClose(); setTimeout(() => window.openEditProfileModal && window.openEditProfileModal(), 50); }}>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
                Edit Profile
              </button>
              <a href={`${basePath}modules/admin/settings.php`} className="user-dropdown-item" onClick={handleClose}>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                  <circle cx="12" cy="12" r="3"/>
                  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                Settings
              </a>
              <div style={{ height: '1px', background: 'var(--border)', margin: '0.3rem 0' }}></div>
              <a href={`${basePath}auth/logout.php`} className="user-dropdown-item" style={{ color: 'var(--danger)' }} onClick={handleClose}>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                  <polyline points="16 17 21 12 16 7"/>
                  <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Logout
              </a>
            </div>
          </div>
        )}
      </div>
    );
  }

  // Mount user dropdown
  const userDropdownRoot = document.getElementById('user-dropdown-root');
  if (userDropdownRoot) {
    const { name, initials, role, username, base, photo } = userDropdownRoot.dataset;
    ReactDOM.createRoot(userDropdownRoot).render(
      <UserDropdown
        fullName={name}
        initials={initials}
        role={role}
        username={username}
        basePath={base}
        photo={photo || ''}
      />
    );
  }

</script>

<?php if ($footer_extra_scripts): ?>
  <?= $footer_extra_scripts ?>
<?php endif; ?>
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

<!-- CSRF token for AJAX calls -->
<?php if (!function_exists('csrf_token')) require_once __DIR__ . '/../config/validators.php'; ?>
<script>window._csrf = <?= json_encode(csrf_token()) ?>;</script>

<!-- ── EDIT PROFILE MODAL ── -->
<div id="edit-profile-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)window.closeEditProfileModal()">
  <div style="background:var(--bg-3);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--bg-2);">
      <div style="font-weight:700;font-size:0.9rem;color:var(--text-primary);display:flex;align-items:center;gap:0.5rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Edit Profile
      </div>
      <button onclick="window.closeEditProfileModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0.25rem;border-radius:6px;display:flex;align-items:center;transition:color 0.15s;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-muted)'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
      <!-- Avatar row -->
      <div style="display:flex;align-items:center;gap:1rem;">
        <div id="ep-avatar-preview" style="width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;border:2px solid var(--border);"></div>
        <div style="display:flex;flex-direction:column;gap:0.4rem;">
          <div style="font-size:0.78rem;font-weight:600;color:var(--text-secondary);">Profile Photo</div>
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-size:0.75rem;font-weight:600;padding:0.35rem 0.75rem;border-radius:7px;background:var(--bg);border:1px solid var(--border);color:var(--text-secondary);transition:all 0.15s;" onmouseover="this.style.borderColor='var(--gold-muted)';this.style.color='var(--gold)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-secondary)'">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              Upload Photo
              <input type="file" id="ep-avatar-input" accept="image/jpeg,image/png,image/webp" hidden/>
            </label>
            <button type="button" id="ep-avatar-remove-btn" style="display:none;font-size:0.75rem;font-weight:600;padding:0.35rem 0.75rem;border-radius:7px;background:var(--bg);border:1px solid var(--border);color:var(--danger);cursor:pointer;transition:all 0.15s;font-family:'Plus Jakarta Sans',sans-serif;" onmouseover="this.style.borderColor='var(--danger)'" onmouseout="this.style.borderColor='var(--border)'">Remove</button>
          </div>
          <span style="font-size:0.67rem;color:var(--text-muted);">JPG, PNG, or WebP. Max 2 MB.</span>
        </div>
      </div>
      <div style="height:1px;background:var(--border);"></div>
      <div class="form-grid">
        <div class="field">
          <label class="field-label">First Name <span class="req">*</span></label>
          <input type="text" id="ep-first-name" class="field-input" placeholder="e.g. Juan" autocomplete="given-name"/>
        </div>
        <div class="field">
          <label class="field-label">Last Name <span class="req">*</span></label>
          <input type="text" id="ep-last-name" class="field-input" placeholder="e.g. dela Cruz" autocomplete="family-name"/>
        </div>
        <div class="field span-2">
          <label class="field-label">Email Address</label>
          <input type="email" id="ep-email" class="field-input" placeholder="your@email.com" autocomplete="email"/>
          <span class="field-hint">Changing your email will require verification.</span>
        </div>
      </div>
      <div id="ep-error" style="display:none;color:var(--danger);font-size:0.8rem;background:var(--danger-bg);border:1px solid var(--danger-border);border-radius:8px;padding:0.6rem 0.8rem;"></div>
    </div>
    <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;gap:0.5rem;justify-content:flex-end;background:var(--bg-2);">
      <button type="button" class="btn-ghost" onclick="window.closeEditProfileModal()">Cancel</button>
      <button type="button" class="btn-primary" id="ep-save-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        Save Changes
      </button>
    </div>
  </div>
</div>

<script>
(function() {
  var modal = document.getElementById('edit-profile-modal');
  if (!modal) return;

  function renderAvatarPreview() {
    var root    = document.getElementById('user-dropdown-root');
    var photo   = root ? root.dataset.photo : '';
    var initials = root ? root.dataset.initials : '?';
    var preview = document.getElementById('ep-avatar-preview');
    var removeBtn = document.getElementById('ep-avatar-remove-btn');
    if (photo) {
      preview.innerHTML = '<img src="' + photo + '" style="width:100%;height:100%;object-fit:cover;"/>';
      if (removeBtn) removeBtn.style.display = '';
    } else {
      preview.innerHTML = initials;
      if (removeBtn) removeBtn.style.display = 'none';
    }
  }

  window.openEditProfileModal = function() {
    var root     = document.getElementById('user-dropdown-root');
    var fullName = root ? (root.dataset.name || '') : '';
    var parts    = fullName.trim().split(/\s+/);
    var lastName = parts.length > 1 ? parts.slice(1).join(' ') : '';
    document.getElementById('ep-first-name').value = parts[0] || '';
    document.getElementById('ep-last-name').value  = lastName;
    document.getElementById('ep-email').value = '';
    document.getElementById('ep-error').style.display = 'none';
    document.getElementById('ep-avatar-input').value = '';
    renderAvatarPreview();
    modal.style.display = 'flex';
    setTimeout(function() { document.getElementById('ep-first-name').focus(); }, 60);
  };

  window.closeEditProfileModal = function() {
    modal.style.display = 'none';
  };

  // Avatar file preview (local, before upload)
  document.getElementById('ep-avatar-input').addEventListener('change', function() {
    if (!this.files.length) return;
    var file = this.files[0];
    var reader = new FileReader();
    reader.onload = function(e) {
      var preview = document.getElementById('ep-avatar-preview');
      preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;"/>';
      document.getElementById('ep-avatar-remove-btn').style.display = '';
    };
    reader.readAsDataURL(file);
  });

  // Avatar remove button
  document.getElementById('ep-avatar-remove-btn').addEventListener('click', function() {
    this._pendingRemove = true;
    var root = document.getElementById('user-dropdown-root');
    var initials = root ? root.dataset.initials : '?';
    var preview = document.getElementById('ep-avatar-preview');
    preview.innerHTML = initials;
    preview.style.background = 'linear-gradient(135deg,var(--gold-bright),var(--gold))';
    document.getElementById('ep-avatar-input').value = '';
    this.style.display = 'none';
  });

  document.getElementById('ep-save-btn').addEventListener('click', async function() {
    var firstName = document.getElementById('ep-first-name').value.trim();
    var lastName  = document.getElementById('ep-last-name').value.trim();
    var email     = document.getElementById('ep-email').value.trim();
    var errBox    = document.getElementById('ep-error');

    errBox.style.display = 'none';
    if (!firstName) { errBox.textContent = 'First name is required.'; errBox.style.display = 'block'; return; }
    if (!lastName)  { errBox.textContent = 'Last name is required.';  errBox.style.display = 'block'; return; }

    var orig = this.innerHTML;
    this.disabled = true;
    this.textContent = 'Saving...';

    var root = document.getElementById('user-dropdown-root');
    var base = root ? (root.dataset.base || '') : '';
    var endpoint = base + 'modules/admin/settings.php';
    var removeBtn = document.getElementById('ep-avatar-remove-btn');
    var avatarInput = document.getElementById('ep-avatar-input');

    // 1. Handle avatar upload
    if (avatarInput.files.length) {
      var afd = new FormData();
      afd.append('section', 'avatar_upload');
      afd.append('csrf_token', window._csrf || '');
      afd.append('avatar', avatarInput.files[0]);
      try {
        var ares = await fetch(endpoint, { method:'POST', body:afd });
        var adata = await ares.json();
        if (!adata.ok) {
          errBox.textContent = adata.error || 'Photo upload failed.';
          errBox.style.display = 'block';
          this.disabled = false; this.innerHTML = orig;
          return;
        }
        // Update dropdown photo data
        if (root && adata.photo) root.dataset.photo = base + 'uploads/avatars/' + adata.photo;
      } catch(e) {}
    }

    // 2. Handle avatar remove
    if (removeBtn && removeBtn._pendingRemove) {
      removeBtn._pendingRemove = false;
      var rfd = new FormData();
      rfd.append('section', 'avatar_remove');
      rfd.append('csrf_token', window._csrf || '');
      try {
        await fetch(endpoint, { method:'POST', body:rfd });
        if (root) root.dataset.photo = '';
      } catch(e) {}
    }

    // 3. Save profile info
    var fd = new FormData();
    fd.append('section', 'account');
    fd.append('csrf_token', window._csrf || '');
    fd.append('first_name', firstName);
    fd.append('last_name', lastName);
    fd.append('email', email);
    fd.append('current_password', '');
    fd.append('new_password', '');
    fd.append('confirm_password', '');

    var data = null;
    try {
      var res = await fetch(endpoint, { method: 'POST', body: fd });
      data = await res.json();
    } catch(e) {}

    this.disabled = false;
    this.innerHTML = orig;

    if (!data) { errBox.textContent = 'Something went wrong. Please try again.'; errBox.style.display = 'block'; return; }
    if (data.ok) {
      window.closeEditProfileModal();
      // Update name in user chip
      var newFullName = (firstName + ' ' + lastName).trim();
      var chipLabel = document.querySelector('.user-chip-label');
      if (chipLabel) {
        var parts = chipLabel.textContent.split('—');
        chipLabel.textContent = newFullName + (parts[1] ? ' —' + parts[1] : '');
      }
      if (root) root.dataset.name = newFullName;
      // Update avatar in user chip
      var avatar = document.querySelector('.user-avatar');
      if (avatar && root) {
        if (root.dataset.photo) {
          avatar.innerHTML = '<img src="' + root.dataset.photo + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"/>';
        } else {
          avatar.innerHTML = root.dataset.initials || '?';
        }
      }
      Swal.fire({ toast:true, position:'top-end', icon:'success', title: data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
    } else {
      errBox.textContent = data.error || 'Failed to save.';
      errBox.style.display = 'block';
    }
  });
})();
</script>

<!-- ── USER PROFILE CARD MODAL ── -->
<div id="user-profile-modal" style="display:none;position:fixed;inset:0;z-index:9100;background:rgba(0,0,0,0.45);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)document.getElementById('user-profile-modal').style.display='none'">
  <div style="background:var(--bg-3);border:1px solid var(--border);border-radius:16px;width:100%;max-width:340px;box-shadow:var(--shadow-lg);overflow:hidden;">
    <!-- Header banner -->
    <div id="upm-banner" style="height:80px;background:linear-gradient(135deg,var(--gold-bright),var(--gold));position:relative;">
      <button onclick="document.getElementById('user-profile-modal').style.display='none'" style="position:absolute;top:0.6rem;right:0.6rem;background:rgba(0,0,0,0.25);border:none;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;transition:background 0.15s;" onmouseover="this.style.background='rgba(0,0,0,0.45)'" onmouseout="this.style.background='rgba(0,0,0,0.25)'">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <!-- Avatar (overlapping banner) -->
    <div style="padding:0 1.25rem;margin-top:-30px;display:flex;align-items:flex-end;justify-content:space-between;position:relative;z-index:1;">
      <div id="upm-avatar" style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;color:#fff;overflow:hidden;border:3px solid var(--bg-3);flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>
      <div id="upm-online-badge" style="display:none;margin-bottom:0.25rem;"></div>
    </div>
    <!-- Info -->
    <div style="padding:0.75rem 1.25rem 1.25rem;">
      <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
        <div id="upm-name" style="font-size:1rem;font-weight:800;color:var(--text-primary);"></div>
        <div id="upm-role-badge"></div>
      </div>
      <div id="upm-username" style="font-size:0.72rem;color:var(--text-muted);margin-top:0.1rem;"></div>
      <div style="height:1px;background:var(--border);margin:0.9rem 0;"></div>
      <div style="display:flex;flex-direction:column;gap:0.55rem;">
        <div id="upm-email-row" style="display:none;align-items:center;gap:0.5rem;font-size:0.78rem;color:var(--text-secondary);">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" style="flex-shrink:0;color:var(--text-muted)"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span id="upm-email"></span>
        </div>
        <div id="upm-last-active-row" style="display:flex;align-items:center;gap:0.5rem;font-size:0.78rem;color:var(--text-secondary);">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" style="flex-shrink:0;color:var(--text-muted)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span id="upm-last-active"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var modal = document.getElementById('user-profile-modal');
  if (!modal) return;

  var base = (document.querySelector('#user-dropdown-root') || {}).dataset?.base || '../';

  var roleBadgeClass  = { super_admin:'badge-gold', admin:'badge-green', mechanic:'badge-blue' };
  var roleBannerColor = {
    super_admin: 'linear-gradient(135deg,#D4A017,#B8860B)',
    admin:       'linear-gradient(135deg,#34a76a,#2E7D52)',
    mechanic:    'linear-gradient(135deg,#3b91d4,#1A6B9A)',
  };

  function timeAgo(dateStr) {
    if (!dateStr) return 'no recent activity';
    var diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
  }

  function openUserProfile(userId) {
    // Show skeleton state
    modal.style.display = 'flex';
    document.getElementById('upm-name').textContent = 'Loading…';
    document.getElementById('upm-username').textContent = '';
    document.getElementById('upm-role-badge').innerHTML = '';
    document.getElementById('upm-avatar').innerHTML = '';
    document.getElementById('upm-email-row').style.display = 'none';
    document.getElementById('upm-online-badge').style.display = 'none';

    fetch(base + 'ajax/get_user_profile.php?id=' + encodeURIComponent(userId))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok) { modal.style.display = 'none'; return; }

        // Avatar
        var av = document.getElementById('upm-avatar');
        if (d.photo) {
          av.innerHTML = '<img src="' + base + d.photo + '" style="width:100%;height:100%;object-fit:cover;" alt=""/>';
        } else {
          var initials = d.full_name.split(' ').map(function(w){return w[0]||'';}).join('').substring(0,2).toUpperCase();
          av.textContent = initials;
        }

        // Banner color based on role
        document.getElementById('upm-banner').style.background = roleBannerColor[d.role] || roleBannerColor.super_admin;

        // Name, username, role
        document.getElementById('upm-name').textContent = d.full_name;
        document.getElementById('upm-username').textContent = '@' + d.username;
        var badgeCls = roleBadgeClass[d.role] || 'badge-gray';
        document.getElementById('upm-role-badge').innerHTML = '<span class="badge ' + badgeCls + '">' + d.role_label + '</span>';

        // Online badge
        var onlineBadge = document.getElementById('upm-online-badge');
        if (d.is_online) {
          onlineBadge.style.display = 'flex';
          onlineBadge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.67rem;font-weight:700;color:#22c55e;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);border-radius:100px;padding:0.15rem 0.55rem;"><span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;"></span>Online</span>';
        } else {
          onlineBadge.style.display = 'none';
        }

        // Email (only show to admins/super_admin — role stored in session, passed via data attr)
        var myRole = (document.querySelector('#user-dropdown-root') || {}).dataset?.role || '';
        var emailRow = document.getElementById('upm-email-row');
        if (d.email && (myRole === 'Owner' || myRole === 'Admin')) {
          document.getElementById('upm-email').textContent = d.email;
          emailRow.style.display = 'flex';
        } else {
          emailRow.style.display = 'none';
        }

        // Last active
        document.getElementById('upm-last-active').textContent = d.is_online ? 'Active now' : 'Last seen ' + timeAgo(d.last_active);
      })
      .catch(function() { modal.style.display = 'none'; });
  }

  // Global click handler: any element with data-user-id triggers the modal
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-user-id]');
    if (!el) return;
    e.preventDefault();
    openUserProfile(el.dataset.userId);
  });

  window.openUserProfile = openUserProfile;
})();
</script>

<!-- ── GLOBAL SEARCH ── -->
<script>
(function() {
  var wrap    = document.getElementById('gs-wrap');
  var input   = document.getElementById('gs-input');
  var kbd     = document.getElementById('gs-kbd');
  var dropdown = document.getElementById('gs-dropdown');
  if (!wrap || !input || !dropdown) return;

  var base = (document.querySelector('#user-dropdown-root') || {}).dataset?.base || '../';
  var debounceTimer = null;
  var currentQuery  = '';
  var activeIdx     = -1;
  var lastResults   = [];

  var icons = {
    user:           '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    truck:          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'shield-check': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
    'clipboard-list':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>',
    wrench:         '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
  };

  var typeLabels = { client:'Clients', vehicle:'Vehicles', policy:'Policies', claim:'Claims', repair:'Repairs' };

  function open()  { wrap.classList.add('focused'); }
  function close() {
    wrap.classList.remove('focused');
    dropdown.style.display = 'none';
    activeIdx = -1;
    currentQuery = '';
  }

  function buildDropdown(results) {
    lastResults = results;
    activeIdx   = -1;
    if (!results.length) {
      dropdown.innerHTML = '<div class="gs-empty">No results found.</div>';
      dropdown.style.display = 'block';
      return;
    }

    // Group by type
    var groups = {};
    var order  = ['client','vehicle','policy','claim','repair'];
    results.forEach(function(r) {
      if (!groups[r.type]) groups[r.type] = [];
      groups[r.type].push(r);
    });

    var html = '';
    order.forEach(function(type) {
      if (!groups[type]) return;
      html += '<div class="gs-group-label">' + (typeLabels[type] || type) + '</div>';
      groups[type].forEach(function(r, i) {
        var globalIdx = results.indexOf(r);
        html += '<a href="' + base + r.url + '" class="gs-item" data-idx="' + globalIdx + '">'
              + '<div class="gs-item-icon">' + (icons[r.icon] || '') + '</div>'
              + '<div style="min-width:0;flex:1;">'
              + '<div class="gs-item-label">' + escHtml(r.label) + '</div>'
              + (r.sub ? '<div class="gs-item-sub">' + escHtml(r.sub) + '</div>' : '')
              + '</div>'
              + '</a>';
      });
    });

    html += '<div class="gs-footer">'
          + '<span><kbd>&uarr;&darr;</kbd> navigate</span>'
          + '<span><kbd>Enter</kbd> open</span>'
          + '<span><kbd>Esc</kbd> close</span>'
          + '</div>';

    dropdown.innerHTML = html;
    dropdown.style.display = 'block';

    // Click on items
    dropdown.querySelectorAll('.gs-item').forEach(function(el) {
      el.addEventListener('mousedown', function(e) {
        e.preventDefault(); // prevent blur before navigation
      });
      el.addEventListener('click', function() {
        close();
      });
    });
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function setActive(idx) {
    var items = dropdown.querySelectorAll('.gs-item');
    items.forEach(function(el) { el.classList.remove('active'); });
    if (idx >= 0 && idx < items.length) {
      items[idx].classList.add('active');
      items[idx].scrollIntoView({ block:'nearest' });
    }
    activeIdx = idx;
  }

  function doSearch(q) {
    if (!q) { dropdown.style.display = 'none'; return; }
    dropdown.innerHTML = '<div class="gs-empty" style="padding:0.75rem 0.85rem;">Searching…</div>';
    dropdown.style.display = 'block';
    fetch(base + 'ajax/global_search.php?q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (input.value.trim() !== q) return; // stale
        buildDropdown(data);
      })
      .catch(function() {
        dropdown.innerHTML = '<div class="gs-empty">Search unavailable.</div>';
        dropdown.style.display = 'block';
      });
  }

  input.addEventListener('focus', function() {
    open();
    if (input.value.trim().length >= 1 && lastResults.length) {
      dropdown.style.display = 'block';
    }
  });

  input.addEventListener('blur', function() {
    // Small delay so clicks on items register first
    setTimeout(function() {
      if (!wrap.contains(document.activeElement)) close();
    }, 180);
  });

  input.addEventListener('input', function() {
    var q = input.value.trim();
    currentQuery = q;
    clearTimeout(debounceTimer);
    if (!q) { dropdown.style.display = 'none'; lastResults = []; return; }
    debounceTimer = setTimeout(function() { doSearch(q); }, 200);
  });

  input.addEventListener('keydown', function(e) {
    var items = dropdown.querySelectorAll('.gs-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive(Math.min(activeIdx + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive(Math.max(activeIdx - 1, -1));
    } else if (e.key === 'Enter') {
      if (activeIdx >= 0 && items[activeIdx]) {
        e.preventDefault();
        items[activeIdx].click();
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close();
      input.blur();
    }
  });

  // Keyboard shortcut: / to focus search (when not in an input)
  document.addEventListener('keydown', function(e) {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA' && !document.activeElement.isContentEditable) {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });

})();
</script>

</body>
</html>