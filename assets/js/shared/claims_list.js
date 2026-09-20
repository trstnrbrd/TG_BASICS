document.querySelectorAll(".js-delete-list").forEach(function (btn) {
  btn.addEventListener("click", async function () {
    const id = this.dataset.id;
    const confirmed = await Swal.fire({
      icon: "warning",
      title: "Delete Claim?",
      text: "This will permanently delete this claim record. This action cannot be undone.",
      showCancelButton: true,
      confirmButtonText: "Yes, delete it",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#c0392b",
      cancelButtonColor: "#6c757d",
    });
    if (!confirmed.isConfirmed) return;
    if (!(await requirePin())) return;

    // POST + CSRF token (the hidden form in claims_list.php), not a GET link.
    const form = document.getElementById("delete-claim-form");
    form.action = "view_claim.php?id=" + encodeURIComponent(id);
    form.submit();
  });
});
