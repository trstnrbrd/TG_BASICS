// Delete client
document.querySelectorAll('.js-delete-client-profile').forEach(function(btn) {
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

// Delete vehicle
document.querySelectorAll('.js-delete-vehicle-form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var plate = this.dataset.plate;
    var self  = this;
    Swal.fire({
      title: 'Delete vehicle?',
      text: 'Delete ' + plate + '? This will also permanently delete all associated insurance policies. This cannot be undone.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#C0392B',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel'
    }).then(function(result) {
      if (result.isConfirmed) self.submit();
    });
  });
});
