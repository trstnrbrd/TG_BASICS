document.querySelectorAll(".js-restore-client").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var form = this.closest("form");
    var answer = await Swal.fire({
      title: "Restore client?",
      text: '"' + this.dataset.name + '" will go back to the client list.',
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, restore",
      cancelButtonText: "Cancel",
    });
    if (answer.isConfirmed && (await requirePin())) form.submit();
  });
});

document.querySelectorAll(".js-delete-all").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var form = this.closest("form");
    var count = parseInt(this.dataset.count, 10);
    var answer = await Swal.fire({
      title: "Delete all " + count + " client" + (count !== 1 ? "s" : "") + " permanently?",
      text: "They, their vehicles and documents will be gone for good. This cannot be undone. Anyone with a policy, repair job or claim on record is kept.",
      icon: "warning",
      showCancelButton: true,
      focusCancel: true,
      confirmButtonColor: "#C0392B",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Yes, delete all",
      cancelButtonText: "Cancel",
    });
    if (answer.isConfirmed && (await requirePin())) form.submit();
  });
});

document.querySelectorAll(".js-delete-forever").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    var form = this.closest("form");
    var answer = await Swal.fire({
      title: "Delete permanently?",
      text: '"' + this.dataset.name + '", their vehicles and documents will be gone for good. This cannot be undone.',
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#C0392B",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Yes, delete permanently",
      cancelButtonText: "Cancel",
    });
    if (answer.isConfirmed && (await requirePin())) form.submit();
  });
});
