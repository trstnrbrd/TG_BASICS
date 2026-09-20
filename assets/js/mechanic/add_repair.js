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
      data = await res.json();
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
const panelState = {};
function setPanel(key, val) {
  (panelState[key] || (panelState[key] = { val: "none", note: "" }),
    (panelState[key].val = val));
  const inp = document.getElementById("inp_area_" + key);
  (inp && (inp.value = val),
    document.querySelectorAll(`.panel[data-panel="${key}"]`).forEach((el) => {
      (el.classList.remove("minor", "major"),
        "minor" === val && el.classList.add("minor"),
        "major" === val && el.classList.add("major"));
    }));
  const row = document.getElementById("row_" + key);
  row &&
    (row.querySelectorAll(".radio-dot").forEach((rd) => {
      (rd.classList.remove("active", "minor", "major"),
        rd.dataset.val === val &&
          rd.classList.add("none" === val ? "active" : val));
    }),
    row.classList.remove("state-minor", "state-major"),
    "minor" === val && row.classList.add("state-minor"),
    "major" === val && row.classList.add("state-major"));
}
const popup = document.getElementById("panel-popup");
let activeKey = null;
function showPopup(key, x, y) {
  activeKey = key;
  const label =
      document.querySelector(`#row_${key} .area-name`)?.textContent || key,
    cur = panelState[key]?.val || "none",
    note = panelState[key]?.note || "";
  ((popup.innerHTML = `\n        <div class="pp-title">${label}</div>\n        <div class="pp-options">\n          <div class="pp-btn ${"none" === cur ? "active" : ""}" data-val="none"  onclick="pickVal('none')">No Damage</div>\n          <div class="pp-btn ${"minor" === cur ? "active" : ""}" data-val="minor" onclick="pickVal('minor')">Minor Scratch</div>\n          <div class="pp-btn ${"major" === cur ? "active" : ""}" data-val="major" onclick="pickVal('major')">Major Damage</div>\n        </div>\n        <input type="text" class="field-input" id="pp-note" placeholder="Notes (optional)"\n          value="${note}" style="font-size:0.78rem;padding:0.35rem 0.6rem;"\n          oninput="saveNote(this.value)"/>\n        <div style="text-align:right;margin-top:0.5rem;">\n          <button type="button" onclick="closePopup()"\n            style="font-size:0.72rem;padding:0.3rem 0.8rem;border-radius:6px;border:1px solid var(--border);\n                   background:var(--bg-3);color:var(--text-muted);cursor:pointer;">Done</button>\n        </div>`),
    (popup.style.display = "block"));
  const pw = popup.offsetWidth,
    ph = popup.offsetHeight,
    vw = window.innerWidth,
    vh = window.innerHeight;
  ((popup.style.left = Math.min(x + 10, vw - pw - 12) + "px"),
    (popup.style.top = Math.min(y + 10, vh - ph - 12) + "px"));
}
function pickVal(val) {
  activeKey &&
    (setPanel(activeKey, val),
    popup
      .querySelectorAll(".pp-btn")
      .forEach((b) => b.classList.toggle("active", b.dataset.val === val)));
}
function saveNote(val) {
  if (!activeKey) return;
  (panelState[activeKey] || (panelState[activeKey] = { val: "none", note: "" }),
    (panelState[activeKey].note = val));
  const inp = document.getElementById("inp_note_" + activeKey);
  inp && (inp.value = val);
  const tableNote = document.querySelector(
    `.note-input[data-key="${activeKey}"]`,
  );
  tableNote && (tableNote.value = val);
}
function closePopup() {
  ((popup.style.display = "none"), (activeKey = null));
}
const areaLabels = window.areaLabels || {};
(document.querySelectorAll(".panel[data-panel]").forEach((el) => {
  const key = el.dataset.panel,
    label = areaLabels[key] || key;
  if (!el.querySelector("title")) {
    const t = document.createElementNS("http://www.w3.org/2000/svg", "title");
    ((t.textContent = label), el.insertBefore(t, el.firstChild));
  }
}),
  document.querySelectorAll(".panel").forEach((el) => {
    el.addEventListener("click", function (e) {
      e.stopPropagation();
      const key = this.dataset.panel;
      if (!key) return;
      const row = document.getElementById("row_" + key);
      (row &&
        (row.scrollIntoView({ behavior: "smooth", block: "nearest" }),
        row.classList.add("row-flash"),
        setTimeout(() => row.classList.remove("row-flash"), 900)),
        showPopup(key, e.clientX, e.clientY));
    });
  }),
  document.querySelectorAll(".radio-dot").forEach((rd) => {
    rd.addEventListener("click", function () {
      const key = this.dataset.key,
        val = this.dataset.val;
      key && val && setPanel(key, val);
    });
  }),
  document.querySelectorAll(".note-input").forEach((inp) => {
    inp.addEventListener("input", function () {
      const key = this.dataset.key;
      (panelState[key] || (panelState[key] = { val: "none", note: "" }),
        (panelState[key].note = this.value),
        (document.getElementById("inp_note_" + key).value = this.value));
    });
  }),
  document.addEventListener("click", (e) => {
    "block" !== popup.style.display ||
      popup.contains(e.target) ||
      e.target.closest(".panel") ||
      closePopup();
  }));
