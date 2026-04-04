document.querySelectorAll('.js-delete-client').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var name = this.dataset.name;
    var form = this.closest('form');
    Swal.fire({
      title: 'Delete client?',
      text: 'Delete "' + name + '" and all their records? This cannot be undone.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#C0392B',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel'
    }).then(function(result) {
      if (result.isConfirmed) form.submit();
    });
  });
});
