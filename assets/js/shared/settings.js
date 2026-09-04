// ── CSRF token helper ──
function csrfToken() {
    return window._csrf || '';
}

// ── Tab switching ──
document.querySelectorAll('.settings-tab-btn').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab-btn').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const panel = document.getElementById('panel-' + tab.dataset.tab);
        if (panel) panel.classList.add('active');
    });
});

// ── AJAX form save ──
document.querySelectorAll('.settings-form').forEach(form => {
    form.addEventListener('submit', (e) => e.preventDefault()); // block native submit always
    const btn = form.querySelector('.js-settings-save');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.textContent = 'Saving...';

        let data = null;
        try {
            const res = await fetch('settings.php', {
                method: 'POST',
                body: new FormData(form)
            });
            data = await res.json();
        } catch (err) {
            data = null;
        }

        btn.disabled = false;
        btn.style.opacity = '';
        btn.innerHTML = originalHTML;
        document.body.style.cursor = '';

        if (!data) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.', confirmButtonColor: '#B8860B' });
        } else if (data.ok) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
            if (form.querySelector('[name="section"]').value === 'account') {
                form.querySelectorAll('input[type="password"]').forEach(p => p.value = '');
            }
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
        }
    });
});

// ── Claim Notify Recipients (single field: search users or type a custom email, max 5) ──
(function() {
    const list    = document.getElementById('claim-notify-list');
    const addBtn  = document.getElementById('claim-notify-add');
    if (!list || !addBtn) return;
    const MAX   = 5;
    const users = window._claimNotifyUsers || [];

    function escapeHtml(s) {
        return String(s).replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
    }

    function renderDropdown(row, query) {
        const dd = row.querySelector('.claim-notify-dropdown');
        const q  = (query || '').trim().toLowerCase().replace(/^@/, '');
        const matches = users.filter(u =>
            u.username.toLowerCase().includes(q) || u.label.toLowerCase().includes(q)
        ).slice(0, 8);

        if (!matches.length) {
            dd.innerHTML = '<div style="padding:0.65rem 0.9rem;font-size:0.78rem;color:var(--text-muted);">No matching users — this will be saved as a custom email.</div>';
        } else {
            dd.innerHTML = matches.map(u =>
                '<div class="claim-notify-option" data-id="' + u.id + '" data-username="' + escapeHtml(u.username) + '" ' +
                'style="padding:0.6rem 0.9rem;font-size:0.82rem;cursor:pointer;color:var(--text-primary);">' + escapeHtml(u.label) + '</div>'
            ).join('');
        }
        dd.style.display = 'block';
    }

    function hideDropdown(row) {
        row.querySelector('.claim-notify-dropdown').style.display = 'none';
    }

    function setManual(row, value) {
        row.querySelector('.claim-notify-uid').value   = '';
        row.querySelector('.claim-notify-email').value = value.trim();
    }

    function selectUser(row, id, username) {
        const input = row.querySelector('.claim-notify-input');
        input.value = '@' + username;
        row.querySelector('.claim-notify-uid').value   = id;
        row.querySelector('.claim-notify-email').value = '';
        hideDropdown(row);
    }

    function makeRow() {
        const row = document.createElement('div');
        row.className = 'claim-notify-row';
        row.style.cssText = 'position:relative;display:flex;gap:0.5rem;align-items:center;';
        row.innerHTML =
            '<div style="position:relative;flex:1;">' +
              '<input type="text" class="field-input claim-notify-input" autocomplete="off" placeholder="Type an email or search for a user...">' +
              '<div class="claim-notify-dropdown" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:50;background:var(--bg-3);border:1px solid var(--border);border-radius:9px;box-shadow:var(--shadow-lg);max-height:220px;overflow-y:auto;"></div>' +
            '</div>' +
            '<input type="hidden" name="claim_notify_user_ids[]" class="claim-notify-uid" value="">' +
            '<input type="hidden" name="claim_notify_emails[]" class="claim-notify-email" value="">' +
            '<button type="button" class="btn-ghost claim-notify-remove" style="padding:0.55rem;flex-shrink:0;">' +
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>';
        return row;
    }

    function refreshAddBtn() {
        addBtn.style.display = list.children.length >= MAX ? 'none' : '';
    }

    function clearRow(row) {
        row.querySelector('.claim-notify-input').value = '';
        row.querySelector('.claim-notify-uid').value   = '';
        row.querySelector('.claim-notify-email').value = '';
    }

    addBtn.addEventListener('click', function() {
        if (list.children.length >= MAX) return;
        list.appendChild(makeRow());
        refreshAddBtn();
    });

    // Typing → treat as a custom email + live-filter suggestions
    list.addEventListener('input', function(e) {
        if (!e.target.classList.contains('claim-notify-input')) return;
        const row = e.target.closest('.claim-notify-row');
        setManual(row, e.target.value);
        renderDropdown(row, e.target.value);
    });

    // Focus → show suggestions
    list.addEventListener('focusin', function(e) {
        if (!e.target.classList.contains('claim-notify-input')) return;
        renderDropdown(e.target.closest('.claim-notify-row'), e.target.value);
    });

    // Blur → hide suggestions shortly after (lets a click on an option register first)
    list.addEventListener('focusout', function(e) {
        if (!e.target.classList.contains('claim-notify-input')) return;
        const row = e.target.closest('.claim-notify-row');
        setTimeout(() => hideDropdown(row), 150);
    });

    // Picking a suggestion
    list.addEventListener('mousedown', function(e) {
        const opt = e.target.closest('.claim-notify-option');
        if (!opt) return;
        e.preventDefault(); // keep focus so 'focusout' doesn't fire before this handles
        selectUser(opt.closest('.claim-notify-row'), opt.dataset.id, opt.dataset.username);
    });

    // Remove (with confirmation)
    list.addEventListener('click', async function(e) {
        const btn = e.target.closest('.claim-notify-remove');
        if (!btn) return;
        const row = btn.closest('.claim-notify-row');

        const result = await Swal.fire({
            title: 'Remove recipient?',
            text: 'They will no longer receive claim requirement emails.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#C0392B',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, remove',
            cancelButtonText: 'Cancel'
        });
        if (!result.isConfirmed) return;

        if (list.children.length <= 1) {
            clearRow(row);
            return;
        }
        row.remove();
        refreshAddBtn();
    });

    refreshAddBtn();
})();

// ── Avatar Upload ──
const avatarInput = document.getElementById('avatar-file-input');
if (avatarInput) {
    avatarInput.addEventListener('change', async function() {
        if (!this.files.length) return;
        const fd = new FormData();
        fd.append('section', 'avatar_upload');
        fd.append('csrf_token', csrfToken());
        fd.append('avatar', this.files[0]);

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
                setTimeout(() => location.reload(), 600);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed. Please try again.', confirmButtonColor: '#B8860B' });
        }
        this.value = '';
    });
}

// ── Avatar Remove ──
const avatarRemoveBtn = document.getElementById('avatar-remove-btn');
if (avatarRemoveBtn) {
    avatarRemoveBtn.addEventListener('click', async function() {
        const fd = new FormData();
        fd.append('section', 'avatar_remove');
        fd.append('csrf_token', csrfToken());

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
                setTimeout(() => location.reload(), 600);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        }
    });
}

// ── Theme Picker ──
document.querySelectorAll('.theme-option').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        opt.querySelector('input[type="radio"]').checked = true;
    });
});

// ── Save Design Preferences ──
const saveDesignBtn = document.getElementById('save-design-btn');
if (saveDesignBtn) {
    saveDesignBtn.addEventListener('click', async function() {
        const theme = document.querySelector('input[name="theme"]:checked')?.value || 'light';
        const originalHTML = this.innerHTML;
        this.disabled = true;
        this.style.opacity = '0.6';
        this.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('section', 'design_prefs');
        fd.append('csrf_token', csrfToken());
        fd.append('theme', theme);

        let data = null;
        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            data = await res.json();
        } catch (err) {
            data = null;
        }

        this.disabled = false;
        this.style.opacity = '';
        this.innerHTML = originalHTML;
        document.body.style.cursor = '';

        if (!data) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        } else if (data.ok) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
            document.documentElement.setAttribute('data-theme', theme);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
        }
    });
}

// ── 2FA Toggle ──
const tfaToggle = document.getElementById('tfa-toggle');
if (tfaToggle) {
    tfaToggle.addEventListener('change', async function() {
        const enabled = this.checked ? 1 : 0;
        const fd = new FormData();
        fd.append('section', '2fa_toggle');
        fd.append('csrf_token', csrfToken());
        fd.append('enabled', enabled);

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
                const status = document.getElementById('tfa-status');
                if (status) {
                    status.className = 'toggle-status ' + (enabled ? 'on' : 'off');
                    status.innerHTML = (enabled
                        ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Enabled</span>'
                        : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Disabled</span>'
                    );
                }
            } else {
                this.checked = !this.checked;
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            this.checked = !this.checked;
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        }
    });
}

// ── Change Username ──
const saveUsernameBtn = document.getElementById('save-username-btn');
if (saveUsernameBtn) {
    saveUsernameBtn.addEventListener('click', async function () {
        const newUsername = document.getElementById('new_username')?.value.trim();
        const curPw       = document.getElementById('username_cur_pw')?.value;

        if (!newUsername) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter a new username.', confirmButtonColor: '#B8860B' });
            return;
        }
        if (!curPw) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter your current password to confirm.', confirmButtonColor: '#B8860B' });
            return;
        }

        const originalHTML = this.innerHTML;
        this.disabled = true;
        this.style.opacity = '0.6';
        this.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('section', 'username');
        fd.append('csrf_token', csrfToken());
        fd.append('new_username', newUsername);
        fd.append('current_password', curPw);

        let data = null;
        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            data = await res.json();
        } catch (e) { data = null; }

        this.disabled = false;
        this.style.opacity = '';
        this.innerHTML = originalHTML;

        if (!data) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        } else if (data.ok) {
            Swal.fire({ icon: 'success', title: 'Username Updated!', text: data.message, confirmButtonColor: '#B8860B', timer: 2500, timerProgressBar: true })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
        }
    });
}

// ── Change Password ──
const savePasswordBtn = document.getElementById('save-password-btn');
if (savePasswordBtn) {
    savePasswordBtn.addEventListener('click', async function () {
        const curPw = document.querySelector('[name="current_password"]')?.value;
        const newPw = document.querySelector('[name="new_password"]')?.value;
        const cfmPw = document.querySelector('[name="confirm_password"]')?.value;

        if (!curPw) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter your current password.', confirmButtonColor: '#B8860B' });
            return;
        }
        if (!newPw) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter a new password.', confirmButtonColor: '#B8860B' });
            return;
        }
        if (!cfmPw) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please confirm your new password.', confirmButtonColor: '#B8860B' });
            return;
        }

        const form = savePasswordBtn.closest('.settings-form');
        const originalHTML = this.innerHTML;
        this.disabled = true;
        this.style.opacity = '0.6';
        this.textContent = 'Saving...';

        let data = null;
        try {
            const res = await fetch('settings.php', { method: 'POST', body: new FormData(form) });
            data = await res.json();
        } catch (e) { data = null; }

        this.disabled = false;
        this.style.opacity = '';
        this.innerHTML = originalHTML;

        if (!data) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.', confirmButtonColor: '#B8860B' });
        } else if (data.ok) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, confirmButtonColor: '#B8860B', timer: 2000, timerProgressBar: true });
            form.querySelectorAll('input[type="password"]').forEach(p => p.value = '');
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
        }
    });
}

// Transaction PIN handlers are inline in settings.php
// TOTP handlers are inline in settings.php (load-order dependency on QRCode CDN)
