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

// Transaction PIN handlers are inline in settings.php
