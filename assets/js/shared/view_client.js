function confirmDeleteDoc(btn, name) {
  var form = btn.closest("form");
  Swal.fire({
    title: "Remove document?",
    text: '"' + name + '" will be permanently deleted.',
    icon: "warning",
    showCancelButton: !0,
    confirmButtonColor: "#C0392B",
    cancelButtonColor: "#6B7280",
    confirmButtonText: "Yes, remove",
  }).then(async function (r) {
    r.isConfirmed &&
      (await requirePin()) &&
      (form.requestSubmit ? form.requestSubmit() : form.submit());
  });
}
(document.querySelectorAll(".js-delete-client-profile").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var name = this.dataset.name,
      form = this.closest("form");
    if (
      !(
        await Swal.fire({
          title: "Delete client?",
          text:
            'Delete "' +
            name +
            '" and all their records? This cannot be undone.',
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
  document.querySelectorAll(".js-delete-vehicle-form").forEach(function (form) {
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      var plate = this.dataset.plate;
      if (
        !(
          await Swal.fire({
            title: "Unregister Vehicle?",
            text:
              "Unregister " +
              plate +
              "? This will also permanently remove all associated insurance policies. This cannot be undone.",
            icon: "warning",
            showCancelButton: !0,
            confirmButtonColor: "#C0392B",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, unregister",
            cancelButtonText: "Cancel",
          })
        ).isConfirmed
      )
        return;
      (await requirePin()) && this.submit();
    });
  }));
