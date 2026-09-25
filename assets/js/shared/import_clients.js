/* Import Clients (modules/clients/import_clients.php): upload step and preview step. */
(function () {
  "use strict";

  // ── Upload step ──
  var upForm = document.getElementById("imp-upload-form");
  if (upForm) {
    var drop = document.getElementById("imp-drop");
    var file = document.getElementById("imp-file");
    var name = document.getElementById("imp-file-name");
    var showName = function () {
      var f = file.files && file.files[0];
      name.textContent = f ? f.name : "Choose a CSV file or drop it here";
      drop.classList.toggle("has-file", !!f);
    };
    file.addEventListener("change", showName);
    ["dragenter", "dragover"].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add("is-over"); });
    });
    ["dragleave", "drop"].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove("is-over"); });
    });
    drop.addEventListener("drop", function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { file.files = e.dataTransfer.files; showName(); }
    });

    upForm.addEventListener("submit", function (e) {
      var problem = "";
      var f = file.files && file.files[0];
      if (!document.getElementById("imp-agent").value) problem = "Please select the insurance agent.";
      else if (!f) problem = "Please choose the CSV file to import.";
      else if (/\.(xlsx|xls|xlsm)$/i.test(f.name)) problem = "That is an Excel workbook, not a CSV file. In Excel choose File > Save As > \"CSV UTF-8 (Comma delimited)\", then upload the new file.";
      else if (!/\.(csv|txt)$/i.test(f.name)) problem = "Please upload a .csv file.";
      else if (f.size > 2097152) problem = "The file is too large (max 2 MB).";
      else if (!document.getElementById("imp-consent").checked) problem = "Please confirm that every client in the file has signed the printed Data Privacy Consent Form.";
      if (problem) {
        e.preventDefault();
        Swal.fire({ icon: "warning", title: "Cannot check the file yet", text: problem, confirmButtonColor: "#B8860B" });
        return;
      }
      var btn = document.getElementById("imp-upload-btn");
      btn.disabled = true;
      btn.lastChild.textContent = " Checking file…";
    });
    return;
  }

  // ── Preview step ──
  var form = document.getElementById("imp-import-form");
  if (!form) return;
  var rows    = Array.prototype.slice.call(form.querySelectorAll("tbody tr[data-status]"));
  var empty   = form.querySelector(".imp-empty");
  var go      = document.getElementById("imp-go");
  var summary = document.getElementById("imp-summary");

  // Groups (one client, one or more vehicle rows) that will be saved: every group except unticked warnings
  function totals() {
    var off = {};
    form.querySelectorAll(".imp-include").forEach(function (cb) { if (!cb.checked) off[cb.value] = true; });
    var clients = 0, vehicles = 0, seen = {};
    rows.forEach(function (tr) {
      var g = tr.getAttribute("data-group");
      var skipped = g !== null && off[g];
      tr.classList.toggle("is-skipped", !!skipped);
      if (g === null || skipped) return;
      vehicles++;
      if (!seen[g]) { seen[g] = true; clients++; }
    });
    return { clients: clients, vehicles: vehicles };
  }
  var plural = function (n, word) { return n.toLocaleString() + " " + word + (n === 1 ? "" : "s"); };
  function refresh() {
    var t = totals();
    summary.textContent = plural(t.clients, "client") + " with " + plural(t.vehicles, "vehicle") + " will be saved";
    go.querySelector("span").textContent = t.clients ? "Import " + plural(t.clients, "Client") : "Nothing to Import";
    go.disabled = t.clients === 0;
    return t;
  }
  form.addEventListener("change", function (e) { if (e.target.classList.contains("imp-include")) refresh(); });
  refresh();

  var untick = document.getElementById("imp-untick");
  if (untick) untick.addEventListener("click", function () {
    form.querySelectorAll(".imp-include").forEach(function (cb) { cb.checked = false; });
    refresh();
  });

  var tabs = document.querySelectorAll(".imp-tab");
  tabs.forEach(function (tab) {
    tab.addEventListener("click", function () {
      var show = tab.getAttribute("data-show"), shown = 0;
      tabs.forEach(function (t) {
        t.classList.toggle("is-on", t === tab);
        t.setAttribute("aria-pressed", t === tab ? "true" : "false");
      });
      rows.forEach(function (tr) {
        var on = show === "all" || tr.getAttribute("data-status") === show;
        tr.hidden = !on;
        if (on) shown++;
      });
      empty.hidden = shown > 0;
    });
  });

  var sending = false;
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (sending) return;
    var t = refresh();
    if (!t.clients) return;
    Swal.fire({
      icon: "question",
      title: "Import " + plural(t.clients, "client") + "?",
      text: plural(t.clients, "client") + " with " + plural(t.vehicles, "vehicle") + " will be added to Client Records. Rows with errors or unticked rows are skipped.",
      showCancelButton: true,
      confirmButtonText: "Yes, Import",
      cancelButtonText: "Review Again",
      confirmButtonColor: "#B8860B",
      cancelButtonColor: "#6c757d",
      reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      sending = true;
      go.disabled = true;
      go.querySelector("span").textContent = "Importing…";
      Swal.fire({ title: "Importing…", text: "Please keep this page open.", allowOutsideClick: false, allowEscapeKey: false, didOpen: function () { Swal.showLoading(); } });
      form.submit();
    });
  });

  document.getElementById("imp-cancel").addEventListener("click", function () {
    Swal.fire({
      icon: "warning",
      title: "Start over?",
      text: "This preview is discarded and nothing is saved.",
      showCancelButton: true,
      confirmButtonText: "Yes, Start Over",
      cancelButtonText: "Keep Preview",
      confirmButtonColor: "#B8860B",
      cancelButtonColor: "#6c757d",
      reverseButtons: true
    }).then(function (r) { if (r.isConfirmed) document.getElementById("imp-cancel-form").submit(); });
  });
})();
