/* Settings > System Settings > Database Backup (Owner only). The password is checked first; the server then
   hands back a one-time link that downloads the file, so the page itself never navigates away. */
(function () {
  "use strict";

  // Opened from the dashboard reminder (settings.php#db-backup): show the System Settings tab and the card
  if (location.hash === "#db-backup") {
    var tab = document.querySelector('.settings-tab-btn[data-tab="system_settings"]');
    if (tab) tab.click();
    var card = document.getElementById("db-backup");
    if (card) setTimeout(function () { card.scrollIntoView({ block: "start" }); }, 50);
  }
  if (new URLSearchParams(location.search).get("backup") === "expired") {
    Swal.fire({ icon: "warning", title: "Download link expired", text: "Please press Download Backup again.", confirmButtonColor: "#B8860B" });
  }

  var btn = document.getElementById("db-backup-btn");
  if (!btn) return;
  btn.addEventListener("click", function () {
    Swal.fire({
      icon: "question",
      title: "Download database backup",
      text: "Enter your password to continue.",
      input: "password",
      inputPlaceholder: "Your password",
      inputAttributes: { autocomplete: "current-password", autocapitalize: "off", "aria-label": "Your password" },
      showCancelButton: true,
      confirmButtonText: "Download",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#B8860B",
      cancelButtonColor: "#6c757d",
      reverseButtons: true,
      showLoaderOnConfirm: true,
      allowOutsideClick: function () { return !Swal.isLoading(); },
      preConfirm: function (password) {
        if (!password) { Swal.showValidationMessage("Please enter your password."); return false; }
        var fd = new FormData();
        fd.append("csrf_token", window._csrf || "");
        fd.append("password", password);
        return fetch("backup_database.php", { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (!d.ok) { Swal.showValidationMessage(d.msg || d.error || "Something went wrong. Please try again."); return false; }
            return d;
          })
          .catch(function () { Swal.showValidationMessage("Could not reach the server. Please check the connection and try again."); return false; });
      }
    }).then(function (r) {
      if (!r.isConfirmed || !r.value || !r.value.url) return;
      window.location.href = r.value.url;
      Swal.fire({
        icon: "success",
        title: "Backup is downloading",
        text: "Keep the file somewhere safe, like your Google Drive. Do not share it.",
        confirmButtonColor: "#B8860B"
      });
    });
  });
})();
