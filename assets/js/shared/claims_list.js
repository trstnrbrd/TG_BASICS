document.querySelectorAll('.js-delete-list').forEach(function(btn) {
  btn.addEventListener('click', async function() {
    const id = this.dataset.id;
    const confirmed = await Swal.fire({
      icon: 'warning',
      title: 'Delete Claim?',
      text: 'This will permanently delete this claim record. This action cannot be undone.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#c0392b',
      cancelButtonColor: '#6c757d',
    });
    if (!confirmed.isConfirmed) return;
    const ok = await requirePin();
    if (ok) window.location = 'view_claim.php?id=' + id + '&do_delete=1';
  });
});
