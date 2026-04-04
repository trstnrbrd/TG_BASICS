const liveSearch   = document.getElementById('live-search');
const liveDropdown = document.getElementById('live-dropdown');
let timer;

if (liveSearch) {
  liveSearch.addEventListener('input', function () {
    clearTimeout(timer);
    const val = this.value.trim();
    if (val.length === 0) {
      liveDropdown.style.display = 'none';
      return;
    }
    timer = setTimeout(() => {
      fetch('eligibility_check.php?ajax=1&search=' + encodeURIComponent(val))
        .then(res => res.text())
        .then(html => {
          if (html.trim() === '') {
            liveDropdown.style.display = 'none';
          } else {
            liveDropdown.innerHTML = html;
            liveDropdown.style.display = 'block';
          }
        });
    }, 300);
  });
}
