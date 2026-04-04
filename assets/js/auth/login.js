// Autofocus username on page load
window.addEventListener('load', function() {
  var u = document.getElementById('username');
  if (u) u.focus();
});

// Password toggle
document.getElementById('pw-toggle').addEventListener('click', function () {
  var pw       = document.getElementById('password');
  var iconShow = document.getElementById('pw-icon-show');
  var iconHide = document.getElementById('pw-icon-hide');
  var visible  = pw.type === 'password';
  pw.type                = visible ? 'text'  : 'password';
  iconShow.style.display = visible ? 'none'  : '';
  iconHide.style.display = visible ? ''      : 'none';
});

// Validate before form submits
document.getElementById('login-form').addEventListener('submit', function(e) {
  var username = document.getElementById('username').value.trim();
  var password = document.getElementById('password').value.trim();

  if (!username || !password) {
    e.preventDefault();
    e.stopImmediatePropagation();

    var title, text, focusId;
    if (!username && !password) {
      title = 'Fields Required';
      text  = 'Please enter your username and password to sign in.';
      focusId = 'username';
    } else if (!username) {
      title = 'Username Required';
      text  = 'Please enter your username.';
      focusId = 'username';
    } else {
      title = 'Password Required';
      text  = 'Please enter your password.';
      focusId = 'password';
    }

    Swal.fire({
      icon: 'warning',
      title: title,
      text: text,
      confirmButtonColor: '#B8860B'
    }).then(function() {
      document.getElementById(focusId).focus();
    });
  }
});
