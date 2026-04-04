(function(){
  // ── Count-up animation for stat values ──
  document.querySelectorAll('.dash-stat-value').forEach(function(el) {
    var target = parseInt(el.textContent, 10);
    if (isNaN(target) || target === 0) return;
    var duration = 900;
    var start = null;
    el.textContent = '0';
    function step(ts) {
      if (!start) start = ts;
      var progress = Math.min((ts - start) / duration, 1);
      var ease = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.floor(ease * target);
      if (progress < 1) requestAnimationFrame(step);
      else el.textContent = target;
    }
    requestAnimationFrame(step);
  });

  // ── Greeting & clock ──
  var greetEl = document.getElementById('js-greeting');
  var dateEl  = document.getElementById('js-date');
  var timeEl  = document.getElementById('js-time');

  function update(){
    var now  = new Date();
    var h    = now.getHours();
    greetEl.textContent = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';

    var days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    dateEl.textContent = days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();

    var hr  = h % 12 || 12;
    var min = now.getMinutes().toString().padStart(2,'0');
    var sec = now.getSeconds().toString().padStart(2,'0');
    timeEl.textContent = hr + ':' + min + ':' + sec + ' ' + (h < 12 ? 'AM' : 'PM');
  }

  update();
  setInterval(update, 1000);
})();
