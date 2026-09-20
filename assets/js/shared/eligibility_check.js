const liveSearch = document.getElementById("live-search"),
  liveDropdown = document.getElementById("live-dropdown");
let timer;
liveSearch &&
  liveSearch.addEventListener("input", function () {
    clearTimeout(timer);
    const val = this.value.trim();
    0 !== val.length
      ? (timer = setTimeout(() => {
          fetch(
            "eligibility_check.php?ajax=1&search=" + encodeURIComponent(val),
          )
            .then((res) => res.text())
            .then((html) => {
              "" === html.trim()
                ? (liveDropdown.style.display = "none")
                : ((liveDropdown.innerHTML = html),
                  (liveDropdown.style.display = "block"));
            });
        }, 300))
      : (liveDropdown.style.display = "none");
  });
const companyRadios = document.querySelectorAll(
    'input[name="insurance_company_pick"]',
  ),
  proceedBtn = document.getElementById("proceed-to-policy-btn");
companyRadios.length &&
  proceedBtn &&
  companyRadios.forEach(function (radio) {
    radio.addEventListener("change", function () {
      (document
        .querySelectorAll(".company-pick-option")
        .forEach(function (label) {
          ((label.style.borderColor = "var(--border)"),
            (label.style.background = ""));
        }),
        (this.closest(".company-pick-option").style.borderColor =
          "var(--gold-bright)"),
        (this.closest(".company-pick-option").style.background =
          "var(--gold-pale)"));
      const baseUrl = proceedBtn.dataset.baseUrl;
      ((proceedBtn.href =
        baseUrl + "&company=" + encodeURIComponent(this.value)),
        (proceedBtn.style.opacity = "1"),
        (proceedBtn.style.pointerEvents = "auto"),
        proceedBtn.removeAttribute("aria-disabled"));
    });
  });
