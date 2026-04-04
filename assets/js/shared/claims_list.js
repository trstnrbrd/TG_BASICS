document.querySelectorAll('.js-delete-list').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    Swal.fire({
      icon: 'warning',
      title: 'Delete Claim?',
      text: 'This will permanently delete this claim record. This action cannot be undone.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#c0392b',
      cancelButtonColor: '#6c757d',
    }).then(function(result) {
      if (result.isConfirmed) {
        window.location = 'view_claim.php?id=' + id + '&do_delete=1';
      }
    });
  });
});
