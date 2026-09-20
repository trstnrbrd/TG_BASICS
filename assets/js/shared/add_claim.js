const clientSearch = document.getElementById("client_search"),
  clientIdInput = document.getElementById("client_id"),
  clientNameDisp = document.getElementById("client_name_display"),
  clientDropdown = document.getElementById("client_dropdown"),
  policySelect = document.getElementById("policy_id");
let searchTimer = null;
function loadPolicies(clientId, forceSelectId) {
  ((policySelect.innerHTML = '<option value="">Loading...</option>'),
    clientId
      ? fetch("add_claim.php?ajax_policies=1&client_id=" + clientId)
          .then((r) => r.json())
          .then((data) => {
            0 !== data.length
              ? ((policySelect.innerHTML =
                  '<option value="">— Select Policy —</option>'),
                data.forEach((p) => {
                  const label =
                    p.policy_number +
                    " — " +
                    (p.plate_number || "No plate") +
                    " " +
                    (p.make || "") +
                    " " +
                    (p.model || "") +
                    " (expires " +
                    p.policy_end +
                    ")";
                  policySelect.innerHTML +=
                    '<option value="' +
                    p.policy_id +
                    '">' +
                    label +
                    "</option>";
                }),
                forceSelectId
                  ? (policySelect.value = forceSelectId)
                  : 1 === data.length &&
                    (policySelect.value = data[0].policy_id))
              : (policySelect.innerHTML =
                  '<option value="">No active policies found</option>');
          })
          .catch(() => {
            policySelect.innerHTML =
              '<option value="">Error loading policies</option>';
          })
      : (policySelect.innerHTML =
          '<option value="">— Select client first —</option>'));
}
function selectClient(id, name) {
  ((clientIdInput.value = id),
    (clientNameDisp.value = name),
    (clientSearch.value = name),
    (clientDropdown.style.display = "none"),
    loadPolicies(id));
}
(clientSearch.addEventListener("input", function () {
  const q = this.value.trim();
  ((clientIdInput.value = ""),
    (clientNameDisp.value = ""),
    (policySelect.innerHTML =
      '<option value="">— Select client first —</option>'),
    clearTimeout(searchTimer),
    q.length < 1
      ? (clientDropdown.style.display = "none")
      : (searchTimer = setTimeout(() => {
          fetch("add_claim.php?ajax_clients=1&q=" + encodeURIComponent(q))
            .then((r) => r.json())
            .then((data) => {
              (0 === data.length
                ? (clientDropdown.innerHTML =
                    '<div style="padding:0.75rem 1rem;font-size:0.82rem;color:var(--text-muted);">No clients found</div>')
                : ((clientDropdown.innerHTML = data
                    .map(
                      (c) =>
                        '<div class="client-option" data-id="' +
                        c.client_id +
                        '" data-name="' +
                        c.full_name.replace(/"/g, "&quot;") +
                        '" style="padding:0.6rem 1rem;font-size:0.82rem;color:var(--text-primary);cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.1s;"onmouseover="this.style.background=\'var(--gold-pale)\'" onmouseout="this.style.background=\'\'">' +
                        c.full_name +
                        "</div>",
                    )
                    .join("")),
                  clientDropdown
                    .querySelectorAll(".client-option")
                    .forEach((el) => {
                      el.addEventListener("mousedown", function (e) {
                        (e.preventDefault(),
                          selectClient(this.dataset.id, this.dataset.name));
                      });
                    })),
                (clientDropdown.style.display = "block"));
            });
        }, 220)));
}),
  clientSearch.addEventListener("blur", function () {
    setTimeout(() => {
      clientDropdown.style.display = "none";
    }, 150);
  }),
  clientSearch.addEventListener("focus", function () {
    this.value.trim() &&
      !clientIdInput.value &&
      this.dispatchEvent(new Event("input"));
  }),
  "undefined" != typeof prefillClientId && prefillClientId && prefillClientName
    ? selectClient(prefillClientId, prefillClientName)
    : "undefined" != typeof savedClient &&
      savedClient &&
      loadPolicies(
        savedClient,
        "undefined" != typeof savedPolicy && savedPolicy ? savedPolicy : null,
      ));
