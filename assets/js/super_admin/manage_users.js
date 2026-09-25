(document.querySelectorAll(".js-delete-user").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var name = this.dataset.name,
      form = this.closest("form");
    if (
      !(
        await Swal.fire({
          title: "Delete account?",
          text: 'Delete the account of "' + name + '"? This cannot be undone.',
          icon: "warning",
          showCancelButton: !0,
          confirmButtonColor: "#C0392B",
          cancelButtonColor: "#6c757d",
          confirmButtonText: "Yes, delete",
          cancelButtonText: "Cancel",
        })
      ).isConfirmed
    )
      return;
    (await requirePin()) &&
      (form.requestSubmit ? form.requestSubmit() : form.submit());
  });
}),
  document.getElementById("js-create-btn") &&
  document
    .getElementById("js-create-btn")
    .addEventListener("click", function () {
      var first = document
          .querySelector('[name="new_first_name"]')
          .value.trim(),
        last = document.querySelector('[name="new_last_name"]').value.trim(),
        name = (first + " " + last).trim(),
        email = document.querySelector('[name="new_email"]').value.trim(),
        uname = document.querySelector('[name="new_username"]').value.trim(),
        role = document.querySelector('[name="new_role"]').value;
      first && last && email && uname && role
        ? Swal.fire({
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
            showCancelButton: !0,
            confirmButtonColor: "#B8860B",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, create & send",
            cancelButtonText: "Cancel",
          }).then(function (result) {
            if (result.isConfirmed) {
              var f = document.getElementById("js-create-btn").closest("form");
              f.requestSubmit ? f.requestSubmit() : f.submit();
            }
          })
        : Swal.fire({
            icon: "warning",
            title: "Incomplete Fields",
            text: "Please fill in all required fields before creating an account.",
            confirmButtonColor: "#B8860B",
          });
    }));

// Deactivate: locks the account out but keeps every record (asks for the transaction PIN like Delete)
document.querySelectorAll(".js-deactivate-user").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var name = this.dataset.name,
      form = this.closest("form");
    var res = await Swal.fire({
      title: "Deactivate account?",
      text:
        name +
        " will be signed out and cannot log in until you reactivate the account. Their records and history are kept.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#B8860B",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Yes, deactivate",
      cancelButtonText: "Cancel",
    });
    if (!res.isConfirmed) return;
    (await requirePin()) &&
      (form.requestSubmit ? form.requestSubmit() : form.submit());
  });
});

// Reactivate: lets a deactivated account sign in again (plain confirm — nothing is lost either way)
document.querySelectorAll(".js-reactivate-user").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var name = this.dataset.name,
      form = this.closest("form");
    var res = await Swal.fire({
      title: "Reactivate account?",
      text: name + " will be able to sign in again.",
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#B8860B",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Yes, reactivate",
      cancelButtonText: "Cancel",
    });
    if (!res.isConfirmed) return;
    form.requestSubmit ? form.requestSubmit() : form.submit();
  });
});

