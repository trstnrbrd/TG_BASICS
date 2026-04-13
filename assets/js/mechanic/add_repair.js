/* ── add_repair.js — Unit Inspection / Add Repair Job ── */
// Requires window.areaLabels to be set inline by PHP before this file loads.

// ── Client search autocomplete ──
const clientSearch   = document.getElementById('client-search');
const clientIdInput  = document.getElementById('client_id_input');
const clientDropdown = document.getElementById('client-dropdown');
const vehicleSelect  = document.getElementById('vehicle_id_select');
const vehicleDetails = document.getElementById('vehicle-details');
const contactInput   = document.getElementById('contact_number');

let clients = {};
let searchTimeout;

clientSearch.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    if (q.length < 2) { clientDropdown.style.display = 'none'; return; }
    searchTimeout = setTimeout(async () => {
        const res  = await fetch('../../modules/clients/client_list.php?ajax_ac=1&q=' + encodeURIComponent(q));
        const data = await res.json();
        if (!data.length) { clientDropdown.style.display = 'none'; return; }

        clients = {};
        data.forEach(r => {
            if (!clients[r.client_id]) {
                clients[r.client_id] = { name: r.full_name, id: r.client_id, contact: r.contact_number || '', plates: [] };
            }
            if (r.plate_number) clients[r.client_id].plates.push(r.plate_number);
        });

        clientDropdown.innerHTML = Object.values(clients).map(c => `
            <div class="ac-item" data-id="${c.id}" data-name="${c.name}" data-contact="${c.contact}"
                 style="padding:0.65rem 1rem;cursor:pointer;font-size:0.82rem;border-bottom:1px solid var(--border);
                        display:flex;align-items:center;justify-content:space-between;transition:background 0.1s;"
                 onmouseover="this.style.background='var(--gold-pale)'" onmouseout="this.style.background=''">
              <span style="font-weight:600;color:var(--text-primary);">${c.name}</span>
              <span style="font-size:0.72rem;color:var(--text-muted);">${c.plates.join(', ') || 'No vehicles'}</span>
            </div>`).join('');
        clientDropdown.style.display = 'block';
    }, 250);
});

clientDropdown.addEventListener('click', async function(e) {
    const item = e.target.closest('.ac-item');
    if (!item) return;
    const id      = item.dataset.id;
    const name    = item.dataset.name;
    const contact = item.dataset.contact;

    clientSearch.value           = name;
    clientIdInput.value          = id;
    contactInput.value           = contact;
    clientDropdown.style.display = 'none';

    const res  = await fetch('ajax_get_vehicles.php?client_id=' + id);
    const data = await res.json();

    vehicleSelect.innerHTML = data.length
        ? '<option value="">— Select vehicle —</option>' + data.map(v =>
            `<option value="${v.vehicle_id}"
              data-make="${v.make || ''}" data-model="${v.model || ''}"
              data-year="${v.year_model || ''}" data-color="${v.color || ''}"
              data-plate="${v.plate_number || ''}">${v.plate_number} — ${v.make} ${v.model}</option>`
          ).join('')
        : '<option value="">No vehicles found for this client</option>';
    vehicleSelect.disabled = data.length === 0;

    if (data.length === 1) {
        vehicleSelect.value = data[0].vehicle_id;
        updateVehicleDetails();
    }
});

document.addEventListener('click', e => {
    if (!clientSearch.contains(e.target) && !clientDropdown.contains(e.target))
        clientDropdown.style.display = 'none';
});

vehicleSelect.addEventListener('change', updateVehicleDetails);
function updateVehicleDetails() {
    const opt = vehicleSelect.selectedOptions[0];
    if (!opt || !opt.value) { vehicleDetails.style.display = 'none'; return; }
    document.getElementById('vd-make-model').textContent = (opt.dataset.make + ' ' + opt.dataset.model).trim() || '—';
    document.getElementById('vd-year').textContent       = opt.dataset.year  || '—';
    document.getElementById('vd-color').textContent      = opt.dataset.color || '—';
    document.getElementById('vd-plate').textContent      = opt.dataset.plate || '—';
    vehicleDetails.style.display = 'block';
}

// ── Panel state storage ──
const panelState = {}; // key → { val: 'none'|'minor'|'major', note: '' }

// ── Set a panel's damage level ──
function setPanel(key, val) {
    if (!panelState[key]) panelState[key] = { val: 'none', note: '' };
    panelState[key].val = val;

    const inp = document.getElementById('inp_area_' + key);
    if (inp) inp.value = val;

    document.querySelectorAll(`.panel[data-panel="${key}"]`).forEach(el => {
        el.classList.remove('minor', 'major');
        if (val === 'minor') el.classList.add('minor');
        if (val === 'major') el.classList.add('major');
    });

    const row = document.getElementById('row_' + key);
    if (row) {
        row.querySelectorAll('.radio-dot').forEach(rd => {
            rd.classList.remove('active', 'minor', 'major');
            if (rd.dataset.val === val) rd.classList.add(val === 'none' ? 'active' : val);
        });
        row.classList.remove('state-minor', 'state-major');
        if (val === 'minor') row.classList.add('state-minor');
        if (val === 'major') row.classList.add('state-major');
    }
}

// ── Panel popup ──
const popup = document.getElementById('panel-popup');
let activeKey = null;

function showPopup(key, x, y) {
    activeKey = key;
    const label = document.querySelector(`#row_${key} .area-name`)?.textContent || key;
    const cur   = panelState[key]?.val || 'none';
    const note  = panelState[key]?.note || '';

    popup.innerHTML = `
        <div class="pp-title">${label}</div>
        <div class="pp-options">
          <div class="pp-btn ${cur==='none'  ? 'active':''}" data-val="none"  onclick="pickVal('none')">No Damage</div>
          <div class="pp-btn ${cur==='minor' ? 'active':''}" data-val="minor" onclick="pickVal('minor')">Minor Scratch</div>
          <div class="pp-btn ${cur==='major' ? 'active':''}" data-val="major" onclick="pickVal('major')">Major Damage</div>
        </div>
        <input type="text" class="field-input" id="pp-note" placeholder="Notes (optional)"
          value="${note}" style="font-size:0.78rem;padding:0.35rem 0.6rem;"
          oninput="saveNote(this.value)"/>
        <div style="text-align:right;margin-top:0.5rem;">
          <button type="button" onclick="closePopup()"
            style="font-size:0.72rem;padding:0.3rem 0.8rem;border-radius:6px;border:1px solid var(--border);
                   background:var(--bg-3);color:var(--text-muted);cursor:pointer;">Done</button>
        </div>`;

    popup.style.display = 'block';
    const pw = popup.offsetWidth, ph = popup.offsetHeight;
    const vw = window.innerWidth,  vh = window.innerHeight;
    popup.style.left = Math.min(x + 10, vw - pw - 12) + 'px';
    popup.style.top  = Math.min(y + 10, vh - ph - 12) + 'px';
}

function pickVal(val) {
    if (!activeKey) return;
    setPanel(activeKey, val);
    popup.querySelectorAll('.pp-btn').forEach(b => b.classList.toggle('active', b.dataset.val === val));
}

function saveNote(val) {
    if (!activeKey) return;
    if (!panelState[activeKey]) panelState[activeKey] = { val: 'none', note: '' };
    panelState[activeKey].note = val;
    const inp = document.getElementById('inp_note_' + activeKey);
    if (inp) inp.value = val;
    const tableNote = document.querySelector(`.note-input[data-key="${activeKey}"]`);
    if (tableNote) tableNote.value = val;
}

function closePopup() {
    popup.style.display = 'none';
    activeKey = null;
}

// ── Inject SVG <title> for native hover tooltips ──
const areaLabels = window.areaLabels || {};
document.querySelectorAll('.panel[data-panel]').forEach(el => {
    const key   = el.dataset.panel;
    const label = areaLabels[key] || key;
    if (!el.querySelector('title')) {
        const t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        t.textContent = label;
        el.insertBefore(t, el.firstChild);
    }
});

// ── Click on SVG panel ──
document.querySelectorAll('.panel').forEach(el => {
    el.addEventListener('click', function(e) {
        e.stopPropagation();
        const key = this.dataset.panel;
        if (!key) return;
        const row = document.getElementById('row_' + key);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            row.classList.add('row-flash');
            setTimeout(() => row.classList.remove('row-flash'), 900);
        }
        showPopup(key, e.clientX, e.clientY);
    });
});

// ── Click on table radio dot ──
document.querySelectorAll('.radio-dot').forEach(rd => {
    rd.addEventListener('click', function() {
        const key = this.dataset.key;
        const val = this.dataset.val;
        if (!key || !val) return;
        setPanel(key, val);
    });
});

// ── Note input in table syncs to state ──
document.querySelectorAll('.note-input').forEach(inp => {
    inp.addEventListener('input', function() {
        const key = this.dataset.key;
        if (!panelState[key]) panelState[key] = { val: 'none', note: '' };
        panelState[key].note = this.value;
        document.getElementById('inp_note_' + key).value = this.value;
    });
});

// ── Close popup on outside click ──
document.addEventListener('click', e => {
    if (popup.style.display === 'block' && !popup.contains(e.target) && !e.target.closest('.panel'))
        closePopup();
});
