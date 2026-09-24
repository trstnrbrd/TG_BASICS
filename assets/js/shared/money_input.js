// Money inputs: thousands separators appear while typing (500000 -> 500,000) so long peso amounts are easy to
// read and count. Mark the field <input type="text" inputmode="decimal" class="money-input">. Works for fields
// added later too (event delegation). Anything reading the value must drop the commas first; the server does
// the same with san_money() in config/validators.php.
(function () {
  // Keep digits and one ".", at most 2 decimals, no leading zeros; then group the whole part by 3
  function format(raw) {
    var s = String(raw).replace(/[^\d.]/g, "");
    var dot = s.indexOf(".");
    var whole = dot === -1 ? s : s.slice(0, dot);
    var cents =
      dot === -1
        ? null
        : s
            .slice(dot + 1)
            .replace(/\./g, "")
            .slice(0, 2);
    whole = whole.replace(/^0+(?=\d)/, "");
    if (whole === "" && cents !== null) whole = "0";
    whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    return cents === null ? whole : whole + "." + cents;
  }

  function formatField(el) {
    var old = el.value;
    var next = format(old);
    if (next === old) return;
    // Keep the caret after the same digit it was after, even though commas moved around it
    var typing = document.activeElement === el && el.selectionStart !== null;
    var keep = typing
      ? old.slice(0, el.selectionStart).replace(/[^\d.]/g, "").length
      : 0;
    el.value = next;
    if (typing) {
      var pos = 0,
        seen = 0;
      while (pos < next.length && seen < keep) {
        if (next[pos] !== ",") seen++;
        pos++;
      }
      el.setSelectionRange(pos, pos);
    }
  }

  document.addEventListener("input", function (e) {
    if (e.target.classList && e.target.classList.contains("money-input"))
      formatField(e.target);
  });

  // Values already on the page (a renewal, or the form shown again after an error)
  function formatAll() {
    document.querySelectorAll("input.money-input").forEach(formatField);
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", formatAll);
  else formatAll();
})();
