// ── DELETE USER ──
document.querySelectorAll(".js-delete-user").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var name = this.dataset.name;
    var form = this.closest("form");
    var confirmed = await Swal.fire({
      title: "Delete account?",
      text: 'Delete the account of "' + name + '"? This cannot be undone.',
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#C0392B",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Yes, delete",
      cancelButtonText: "Cancel",
    });
    if (!confirmed.isConfirmed) return;
    const ok = await requirePin();
    if (ok) form.requestSubmit ? form.requestSubmit() : form.submit();
  });
});

// ── CREATE & SEND ACTIVATION ──
document.getElementById("js-create-btn").addEventListener("click", function () {
  var first = document.querySelector('[name="new_first_name"]').value.trim();
  var last = document.querySelector('[name="new_last_name"]').value.trim();
  var name = (first + " " + last).trim();
  var email = document.querySelector('[name="new_email"]').value.trim();
  var uname = document.querySelector('[name="new_username"]').value.trim();
  var role = document.querySelector('[name="new_role"]').value;

  if (!first || !last || !email || !uname || !role) {
    Swal.fire({
      icon: "warning",
      title: "Incomplete Fields",
      text: "Please fill in all required fields before creating an account.",
      confirmButtonColor: "#B8860B",
    });
    return;
  }

  Swal.fire({
    title: "Create account?",
    html:
      "Create an account for <b>" +
      name +
      "</b> as <b>" +
      role.charAt(0).toUpperCase() +
      role.slice(1) +
      '</b>?<br><small style="color:#888;">An activation email will be sent to ' +
      email +
      ".</small>",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#B8860B",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Yes, create & send",
    cancelButtonText: "Cancel",
  }).then(function (result) {
    if (result.isConfirmed) {
      var f = document.getElementById("js-create-btn").closest("form");
      f.requestSubmit ? f.requestSubmit() : f.submit();
    }
  });
});
