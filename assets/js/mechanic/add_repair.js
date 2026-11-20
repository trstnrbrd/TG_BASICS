const clientSearch = document.getElementById("client-search"),
  clientIdInput = document.getElementById("client_id_input"),
  clientDropdown = document.getElementById("client-dropdown"),
  vehicleSelect = document.getElementById("vehicle_id_select"),
  vehicleDetails = document.getElementById("vehicle-details"),
  contactInput = document.getElementById("contact_number");
let searchTimeout,
  clients = {};
function updateVehicleDetails() {
  const opt = vehicleSelect.selectedOptions[0];
  opt && opt.value
    ? ((document.getElementById("vd-make-model").textContent =
        (opt.dataset.make + " " + opt.dataset.model).trim() || "—"),
      (document.getElementById("vd-year").textContent =
        opt.dataset.year || "—"),
      (document.getElementById("vd-color").textContent =
        opt.dataset.color || "—"),
      (document.getElementById("vd-plate").textContent =
        opt.dataset.plate || "—"),
      (vehicleDetails.style.display = "block"))
    : (vehicleDetails.style.display = "none");
}
(clientSearch.addEventListener("input", function () {
  clearTimeout(searchTimeout);
  const q = this.value.trim();
  q.length < 2
    ? (clientDropdown.style.display = "none")
    : (searchTimeout = setTimeout(async () => {
        const res = await fetch(
            "../../modules/clients/client_list.php?ajax_ac=1&q=" +
              encodeURIComponent(q),
          ),
          data = await res.json();
        data.length
          ? ((clients = {}),
            data.forEach((r) => {
              (clients[r.client_id] ||
                (clients[r.client_id] = {
                  name: r.full_name,
                  id: r.client_id,
                  contact: r.contact_number || "",
                  plates: [],
                }),
                r.plate_number &&
                  clients[r.client_id].plates.push(r.plate_number));
            }),
            (clientDropdown.innerHTML = Object.values(clients)
              .map(
                (c) =>
                  `\n            <div class="ac-item" data-id="${c.id}" data-name="${c.name}" data-contact="${c.contact}"\n                 style="padding:0.65rem 1rem;cursor:pointer;font-size:0.82rem;border-bottom:1px solid var(--border);\n                        display:flex;align-items:center;justify-content:space-between;transition:background 0.1s;"\n                 onmouseover="this.style.background='var(--gold-pale)'" onmouseout="this.style.background=''">\n              <span style="font-weight:600;color:var(--text-primary);">${c.name}</span>\n              <span style="font-size:0.72rem;color:var(--text-muted);">${c.plates.join(", ") || "No vehicles"}</span>\n            </div>`,
              )
              .join("")),
            (clientDropdown.style.display = "block"))
          : (clientDropdown.style.display = "none");
      }, 250));
}),
  clientDropdown.addEventListener("click", async function (e) {
    const item = e.target.closest(".ac-item");
    if (!item) return;
    const id = item.dataset.id,
      name = item.dataset.name,
      contact = item.dataset.contact;
    ((clientSearch.value = name),
      (clientIdInput.value = id),
      (contactInput.value = contact),
      (clientDropdown.style.display = "none"));
    const res = await fetch("ajax_get_vehicles.php?client_id=" + id),
      data = (await res.json()).vehicles || [];
    ((vehicleSelect.innerHTML = data.length
      ? '<option value="">— Select vehicle —</option>' +
        data
          .map(
            (v) =>
              `<option value="${v.vehicle_id}"\n              data-make="${v.make || ""}" data-model="${v.model || ""}"\n              data-year="${v.year_model || ""}" data-color="${v.color || ""}"\n              data-plate="${v.plate_number || ""}">${v.plate_number} — ${v.make} ${v.model}</option>`,
          )
          .join("")
      : '<option value="">No vehicles found for this client</option>'),
      (vehicleSelect.disabled = 0 === data.length),
      1 === data.length &&
        ((vehicleSelect.value = data[0].vehicle_id), updateVehicleDetails()));
  }),
  document.addEventListener("click", (e) => {
    clientSearch.contains(e.target) ||
      clientDropdown.contains(e.target) ||
      (clientDropdown.style.display = "none");
  }),
  vehicleSelect.addEventListener("change", updateVehicleDetails));
// ── Unit Inspection ──
// The radio buttons in the list (name="area_<key>") are the real form fields; the car diagram, the numbers,
// the sector counts and the popup only show and change them, so a live-update restore of the radios repaints it all.
const areaLabels = window.areaLabels || {};
// the condition names (No Damage / Minor Scratch / Major Damage) come from REPAIR_DAMAGE_LEVELS via the page
const damageLabels = window.damageLabels || {};
const inspForm = document.querySelector(".insp-wrap")?.closest("form") || document;
const popup = document.getElementById("panel-popup");
let activeKey = null;

const escHtml = (s) =>
  String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
const conditionOf = (key) =>
  inspForm.querySelector(`input[name="area_${key}"]:checked`)?.value || "none";
const noteInput = (key) => inspForm.querySelector(`input[name="note_${key}"]`);
const partsOf = (key) => document.querySelectorAll(`[data-panel="${key}"], [data-pin="${key}"]`);

function paintArea(key) {
  const val = conditionOf(key);
  partsOf(key).forEach((el) => {
    el.classList.toggle("minor", val === "minor");
    el.classList.toggle("major", val === "major");
  });
  const row = document.getElementById("row_" + key);
  if (row) {
    row.classList.toggle("state-minor", val === "minor");
    row.classList.toggle("state-major", val === "major");
    // the notes field shows once the area is damaged (or already has a note)
    const note = noteInput(key);
    row.classList.toggle("show-note", val !== "none" || !!(note && note.value.trim()));
  }
}

function paintTotals() {
  let minor = 0,
    major = 0;
  document.querySelectorAll(".insp-sector").forEach((sec) => {
    let n = 0;
    sec.querySelectorAll(".insp-row").forEach((row) => {
      const v = conditionOf(row.dataset.key);
      if (v !== "none") n++;
      if (v === "minor") minor++;
      if (v === "major") major++;
    });
    const badge = sec.querySelector(".insp-sector-count");
    if (badge) {
      badge.textContent = n ? n + " damaged" : "OK";
      badge.classList.toggle("has-damage", n > 0);
    }
  });
  const sum = document.getElementById("insp-summary");
  if (sum) {
    const total = minor + major;
    sum.classList.toggle("has-damage", total > 0);
    sum.textContent = total
      ? `${total} area${total > 1 ? "s" : ""} damaged` +
        [major && `${major} ${damageLabels.major || "major"}`, minor && `${minor} ${damageLabels.minor || "minor"}`]
          .filter(Boolean)
          .reduce((s, p, i) => s + (i ? ", " : " — ") + p, "")
      : "No damage marked";
  }
}

function setCondition(key, val) {
  const radio = inspForm.querySelector(`input[name="area_${key}"][value="${val}"]`);
  if (radio && !radio.checked) {
    radio.checked = true;
    radio.dispatchEvent(new Event("change", { bubbles: true }));
  }
}

// highlight an area's parts on the car (hovering its row) or a whole sector (hovering the sector)
function setHot(selector, on) {
  document.querySelectorAll(selector).forEach((el) => el.classList.toggle("hot", on));
}

function showPopup(key, anchor, x, y) {
  activeKey = key;
  const cur = conditionOf(key);
  const note = noteInput(key)?.value || "";
  const btn = (v) =>
    `<button type="button" class="pp-btn ${cur === v ? "active" : ""}" data-val="${v}">${escHtml(damageLabels[v] || v)}</button>`;
  popup.innerHTML =
    `<div class="pp-title"><span class="pp-num">${escHtml(document.querySelector(`#row_${key} .insp-num`)?.textContent || "")}</span>${escHtml(areaLabels[key] || key)}</div>` +
    `<div class="pp-options">${btn("none")}${btn("minor")}${btn("major")}</div>` +
    `<input type="text" class="field-input" id="pp-note" maxlength="255" placeholder="Notes (optional)" value="${escHtml(note)}"/>` +
    `<div class="pp-actions"><button type="button" class="pp-done">Done</button></div>`;
  popup.style.display = "block";
  // beside the tap, or beside the part when opened from the keyboard
  if (x == null && anchor) {
    const r = anchor.getBoundingClientRect();
    x = r.right;
    y = r.top;
  }
  const pw = popup.offsetWidth,
    ph = popup.offsetHeight;
  popup.style.left = Math.max(12, Math.min(x + 10, window.innerWidth - pw - 12)) + "px";
  popup.style.top = Math.max(12, Math.min(y + 10, window.innerHeight - ph - 12)) + "px";
  popup.querySelector(".pp-btn.active")?.focus({ preventScroll: true });
  document.querySelectorAll(".panel.open").forEach((el) => el.classList.remove("open"));
  document.querySelectorAll(`[data-panel="${key}"]`).forEach((el) => el.classList.add("open"));
}

function closePopup(returnFocus) {
  if (popup.style.display !== "block") return;
  popup.style.display = "none";
  document.querySelectorAll(".panel.open").forEach((el) => el.classList.remove("open"));
  if (returnFocus && activeKey) document.querySelector(`[data-panel="${activeKey}"][tabindex]`)?.focus();
  activeKey = null;
}

popup.addEventListener("click", (e) => {
  const b = e.target.closest(".pp-btn");
  if (b && activeKey) {
    setCondition(activeKey, b.dataset.val);
    popup.querySelectorAll(".pp-btn").forEach((x) => x.classList.toggle("active", x === b));
  }
  if (e.target.closest(".pp-done")) closePopup(true);
});
popup.addEventListener("input", (e) => {
  if (e.target.id !== "pp-note" || !activeKey) return;
  const inp = noteInput(activeKey);
  if (inp) {
    inp.value = e.target.value;
    paintArea(activeKey);
  }
});

// the car
document.querySelectorAll(".panel[data-panel]").forEach((el) => {
  const key = el.dataset.panel;
  const t = document.createElementNS("http://www.w3.org/2000/svg", "title");
  t.textContent = areaLabels[key] || key;
  el.insertBefore(t, el.firstChild);
  el.addEventListener("click", (e) => {
    e.stopPropagation();
    flashRow(key);
    showPopup(key, el, e.clientX, e.clientY);
  });
  el.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      flashRow(key);
      showPopup(key, el);
    }
  });
  el.addEventListener("mouseenter", () => document.getElementById("row_" + key)?.classList.add("hot"));
  el.addEventListener("mouseleave", () => document.getElementById("row_" + key)?.classList.remove("hot"));
});

function flashRow(key) {
  const row = document.getElementById("row_" + key);
  if (!row) return;
  // on a wide screen the list sits beside the car, so bring the row into view; on a phone the popup is enough
  if (window.matchMedia("(min-width: 960px)").matches) row.scrollIntoView({ behavior: "smooth", block: "nearest" });
  row.classList.remove("row-flash");
  void row.offsetWidth;
  row.classList.add("row-flash");
}

// the list
document.querySelectorAll(".insp-row").forEach((row) => {
  const key = row.dataset.key;
  row.addEventListener("mouseenter", () => setHot(`[data-panel="${key}"]`, true));
  row.addEventListener("mouseleave", () => setHot(`[data-panel="${key}"]`, false));
});
document.querySelectorAll(".insp-sector-head").forEach((head) => {
  const sec = head.parentElement.dataset.sector;
  head.addEventListener("mouseenter", () => setHot(`.panel[data-sector="${sec}"]`, true));
  head.addEventListener("mouseleave", () => setHot(`.panel[data-sector="${sec}"]`, false));
});
inspForm.addEventListener("change", (e) => {
  const m = e.target.name && e.target.name.match(/^area_(.+)$/);
  if (!m) return;
  paintArea(m[1]);
  paintTotals();
  if (activeKey === m[1])
    popup.querySelectorAll(".pp-btn").forEach((b) => b.classList.toggle("active", b.dataset.val === e.target.value));
});
inspForm.addEventListener("input", (e) => {
  const m = e.target.name && e.target.name.match(/^note_(.+)$/);
  if (!m) return;
  const pp = document.getElementById("pp-note");
  if (pp && activeKey === m[1]) pp.value = e.target.value;
});

document.addEventListener("click", (e) => {
  if (!popup.contains(e.target) && !e.target.closest(".panel")) closePopup(false);
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") closePopup(true);
});

// first paint (also covers values the browser or a live-update restore put back)
function paintAll() {
  document.querySelectorAll(".insp-row").forEach((row) => paintArea(row.dataset.key));
  paintTotals();
}
paintAll();
window.addEventListener("pageshow", paintAll);
