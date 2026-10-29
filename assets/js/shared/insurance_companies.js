// Settings > Insurance Companies (super admin): add / edit a company, turn one on or off for new policies.
// Saves go to settings.php (sections insurance_company_save / insurance_company_active), then the page reloads
// so the list, sidebar and renewal links all show the change. The fields sit inside the System Settings form
// but carry data-f instead of name, so "Save All Settings" never sends them; this file posts them itself.
(function () {
  const form = document.getElementById("ins-co-form");
  const table = document.getElementById("ins-co-table");
  if (!form || !table) return;

  const title = document.getElementById("ins-co-form-title");
  const saveBtn = document.getElementById("ins-co-save");
  const cancelBtn = document.getElementById("ins-co-cancel");
  const field = (n) => form.querySelector('[data-f="' + n + '"]');
  const csrf = () => document.querySelector('input[name="csrf_token"]').value;

  function setMode(row) {
    field("company_id").value = row ? row.dataset.id : "0";
    field("name").value = row ? row.dataset.name : "";
    field("short_name").value = row ? row.dataset.short : "";
    field("billing_name").value = row ? row.dataset.billing : "";
    field("sort_order").value = row ? row.dataset.order : "";
    title.textContent = row ? "Edit: " + row.dataset.name : "Add a Company";
    saveBtn.querySelector("span").textContent = row ? "Save Changes" : "Add Company";
    cancelBtn.style.display = row ? "" : "none";
  }

  async function post(body) {
    try {
      const res = await fetch("settings.php", { method: "POST", body: body });
      return await res.json();
    } catch (e) {
      return null;
    }
  }

  function done(data) {
    if (data && data.ok) {
      Swal.fire({ icon: "success", title: data.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
    } else {
      Swal.fire({
        icon: "error",
        title: "Not saved",
        text: (data && data.error) || "Please check your connection and try again.",
        confirmButtonColor: "#B8860B",
      });
    }
  }

  table.addEventListener("click", async (e) => {
    const row = e.target.closest("tr[data-id]");
    if (!row) return;
    if (e.target.closest(".js-ins-edit")) {
      setMode(row);
      form.scrollIntoView({ behavior: "smooth", block: "center" });
      field("name").focus();
      return;
    }
    const toggle = e.target.closest(".js-ins-active");
    if (!toggle) return;
    const activate = toggle.dataset.active === "1";
    if (!activate) {
      const ok = await Swal.fire({
        icon: "warning",
        title: "Deactivate " + row.dataset.name + "?",
        text: "It will no longer be offered for new policies. Its existing policies stay and can still be tracked.",
        showCancelButton: true,
        confirmButtonText: "Deactivate",
        confirmButtonColor: "#B8860B",
      });
      if (!ok.isConfirmed) return;
    }
    const body = new FormData();
    body.append("section", "insurance_company_active");
    body.append("company_id", row.dataset.id);
    body.append("active", activate ? "1" : "0");
    body.append("csrf_token", csrf());
    toggle.disabled = true;
    const data = await post(body);
    toggle.disabled = false;
    done(data);
  });

  cancelBtn.addEventListener("click", () => setMode(null));

  async function save() {
    if (field("name").value.trim() === "") {
      field("name").focus();
      return;
    }
    const body = new FormData();
    body.append("section", "insurance_company_save");
    body.append("csrf_token", csrf());
    ["company_id", "name", "short_name", "billing_name", "sort_order"].forEach((n) => body.append(n, field(n).value));
    saveBtn.disabled = true;
    const data = await post(body);
    saveBtn.disabled = false;
    done(data);
  }
  saveBtn.addEventListener("click", save);
  // Enter in one of these fields saves the company, not the whole System Settings form
  form.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && e.target.matches("input")) {
      e.preventDefault();
      save();
    }
  });
})();
