function animateCounter(el, target, suffix, duration) {
  let current = 0;
  const step = Math.ceil(target / (duration / 20)),
    timer = setInterval(() => {
      ((current += step),
        current >= target
          ? ((el.textContent = target + suffix), clearInterval(timer))
          : (el.textContent = current + suffix));
    }, 20);
}
function observeCounter(el, target, suffix, duration) {
  let started = !1;
  new IntersectionObserver(
    ([entry]) => {
      entry.isIntersecting &&
        !started &&
        ((started = !0), animateCounter(el, target, suffix, duration));
    },
    { threshold: 0.5 },
  ).observe(el);
}
(!(function () {
  const root = document.getElementById("hero-stat-root");
  if (!root) return;
  ((root.innerHTML = [
    { num: 12, label: "Clients" },
    { num: 9, label: "Policies" },
    { num: 3, label: "In Repair" },
  ]
    .map(
      (s, i) =>
        `<div class="hc-stat">\n      <div class="hc-stat-num"><span data-target="${s.num}" data-dur="${1e3 + 200 * i}">0</span></div>\n      <div class="hc-stat-label">${s.label}</div>\n    </div>`,
    )
    .join("")),
    root.querySelectorAll("[data-target]").forEach((el) => {
      observeCounter(el, +el.dataset.target, "", +el.dataset.dur);
    }));
})(),
  (function () {
    const root = document.getElementById("typewriter-root");
    if (!root) return;
    const words = ["Always ready.", "Always accurate.", "Always organized."],
      textEl = document.createElement("span"),
      cursor = document.createElement("span");
    ((textEl.style.color = "var(--gold-bright)"),
      (cursor.style.cssText =
        "border-right:2px solid var(--gold-bright);margin-left:2px;animation:blink 0.7s step-end infinite"),
      root.appendChild(textEl),
      root.appendChild(cursor));
    let wordIdx = 0,
      charIdx = 0,
      deleting = !1;
    !(function tick() {
      const current = words[wordIdx];
      if (deleting)
        (charIdx--,
          (textEl.textContent = current.slice(0, charIdx)),
          0 === charIdx &&
            ((deleting = !1), (wordIdx = (wordIdx + 1) % words.length)));
      else if (
        (charIdx++,
        (textEl.textContent = current.slice(0, charIdx)),
        charIdx === current.length)
      )
        return ((deleting = !0), void setTimeout(tick, 1800));
      setTimeout(tick, deleting ? 40 : 80);
    })();
  })(),
  (function () {
    const nav = document.querySelector(".topnav"),
      links = document.querySelectorAll(".nav-link[href^='#']"),
      sections = [...links]
        .map((l) => document.querySelector(l.getAttribute("href")))
        .filter(Boolean),
      setActive = () => {
        const scrollY = window.scrollY + 120;
        let current = null;
        (sections.forEach((sec) => {
          sec.getBoundingClientRect().top + window.scrollY <= scrollY &&
            (current = sec.id);
        }),
          links.forEach((l) => {
            l.classList.toggle(
              "active",
              l.getAttribute("href") === "#" + current,
            );
          }));
      };
    (links.forEach((link) => {
      link.addEventListener("click", (e) => {
        e.preventDefault();
        const target = document.querySelector(link.getAttribute("href"));
        target && target.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }),
      window.addEventListener(
        "scroll",
        () => {
          (nav && nav.classList.toggle("scrolled", window.scrollY > 40),
            setActive());
        },
        { passive: !0 },
      ),
      setActive());
  })(),
  (function () {
    function revealGroup(selector, dy, stagger) {
      document.querySelectorAll(selector).forEach((el, i) => {
        ((el.style.opacity = "0"),
          (el.style.transform = `translateY(${dy}px)`),
          (el.style.transition = `opacity 0.6s ease ${stagger ? i * stagger : 0}ms, transform 0.6s ease ${stagger ? i * stagger : 0}ms`));
        const obs = new IntersectionObserver(
          ([entry]) => {
            entry.isIntersecting &&
              ((el.style.opacity = "1"),
              (el.style.transform = "none"),
              obs.disconnect());
          },
          { threshold: 0.1 },
        );
        obs.observe(el);
      });
    }
    (revealGroup(".js-reveal", 24, 0),
      revealGroup(".feat-row", 28, 0),
      revealGroup(".sec-card", 24, 100),
      revealGroup(".tech-card", 24, 90),
      revealGroup(".service-pillar", 24, 150),
      revealGroup(".testimonial-card", 24, 90),
      revealGroup(".footer-inner > div", 20, 80),
      revealGroup(".js-reveal-item", 14, 0));
  })(),
  [
    { id: "stat-clients-root", target: 6, suffix: "" },
    { id: "stat-policies-root", target: 3, suffix: "" },
    { id: "stat-modules-root", target: 7, suffix: "" },
    { id: "stat-years-root", target: 9, suffix: "+" },
  ].forEach(({ id: id, target: target, suffix: suffix }) => {
    const el = document.getElementById(id);
    if (!el) return;
    const span = document.createElement("span");
    ((span.className = "stat-num-inner"),
      (span.textContent = "0"),
      el.appendChild(span),
      observeCounter(span, target, suffix, 1200));
  }),
  (function () {
    const track = document.getElementById("testimonial-carousel"),
      prevBtn = document.getElementById("testimonial-prev"),
      nextBtn = document.getElementById("testimonial-next");
    function cardStep() {
      const card = track.querySelector(".testimonial-card");
      if (!card) return track.clientWidth;
      const style = getComputedStyle(track),
        gap = parseFloat(style.columnGap || style.gap) || 0;
      return card.getBoundingClientRect().width + gap;
    }
    function updateButtons() {
      const maxScroll = track.scrollWidth - track.clientWidth - 2;
      ((prevBtn.disabled = track.scrollLeft <= 2),
        (nextBtn.disabled = maxScroll <= 2 || track.scrollLeft >= maxScroll));
    }
    track &&
      prevBtn &&
      nextBtn &&
      (prevBtn.addEventListener("click", () =>
        track.scrollBy({ left: -cardStep(), behavior: "smooth" }),
      ),
      nextBtn.addEventListener("click", () =>
        track.scrollBy({ left: cardStep(), behavior: "smooth" }),
      ),
      track.addEventListener("scroll", updateButtons, { passive: !0 }),
      window.addEventListener("resize", updateButtons),
      updateButtons());
  })());
