// ── ANIMATED COUNTER ──
function animateCounter(el, target, suffix, duration) {
  let current = 0;
  const step = Math.ceil(target / (duration / 20));
  const timer = setInterval(() => {
    current += step;
    if (current >= target) {
      el.textContent = target + suffix;
      clearInterval(timer);
    } else {
      el.textContent = current + suffix;
    }
  }, 20);
}

function observeCounter(el, target, suffix, duration) {
  let started = false;
  const obs = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting && !started) {
      started = true;
      animateCounter(el, target, suffix, duration);
    }
  }, { threshold: 0.5 });
  obs.observe(el);
}

// ── HERO CARD STATS ──
(function () {
  const root = document.getElementById("hero-stat-root");
  if (!root) return;
  const stats = [
    { num: 12, label: "Clients" },
    { num: 9,  label: "Policies" },
    { num: 3,  label: "In Repair" },
  ];
  root.innerHTML = stats.map((s, i) =>
    `<div class="hc-stat">
      <div class="hc-stat-num"><span data-target="${s.num}" data-dur="${1000 + i * 200}">0</span></div>
      <div class="hc-stat-label">${s.label}</div>
    </div>`
  ).join("");
  root.querySelectorAll("[data-target]").forEach(el => {
    observeCounter(el, +el.dataset.target, "", +el.dataset.dur);
  });
})();

// ── TYPEWRITER ──
(function () {
  const root = document.getElementById("typewriter-root");
  if (!root) return;
  const words = ["Always ready.", "Always accurate.", "Always organized."];
  const speed = 80, pause = 1800;
  const textEl = document.createElement("span");
  const cursor = document.createElement("span");
  textEl.style.color = "var(--gold-bright)";
  cursor.style.cssText = "border-right:2px solid var(--gold-bright);margin-left:2px;animation:blink 0.7s step-end infinite";
  root.appendChild(textEl);
  root.appendChild(cursor);

  let wordIdx = 0, charIdx = 0, deleting = false;
  function tick() {
    const current = words[wordIdx];
    if (!deleting) {
      charIdx++;
      textEl.textContent = current.slice(0, charIdx);
      if (charIdx === current.length) {
        deleting = true;
        setTimeout(tick, pause);
        return;
      }
    } else {
      charIdx--;
      textEl.textContent = current.slice(0, charIdx);
      if (charIdx === 0) {
        deleting = false;
        wordIdx = (wordIdx + 1) % words.length;
      }
    }
    setTimeout(tick, deleting ? speed / 2 : speed);
  }
  tick();
})();

// ── NAVBAR SCROLL SHRINK + ACTIVE LINK ──
(function () {
  const nav = document.querySelector(".topnav");
  const links = document.querySelectorAll(".nav-link[href^='#']");
  const sections = [...links].map(l => document.querySelector(l.getAttribute("href"))).filter(Boolean);

  const setActive = () => {
    const scrollY = window.scrollY + 120;
    let current = null;
    sections.forEach(sec => {
      const top = sec.getBoundingClientRect().top + window.scrollY;
      if (top <= scrollY) current = sec.id;
    });
    links.forEach(l => {
      l.classList.toggle("active", l.getAttribute("href") === "#" + current);
    });
  };

  links.forEach(link => {
    link.addEventListener("click", e => {
      e.preventDefault();
      const target = document.querySelector(link.getAttribute("href"));
      if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });

  window.addEventListener("scroll", () => {
    if (nav) nav.classList.toggle("scrolled", window.scrollY > 40);
    setActive();
  }, { passive: true });
  setActive();
})();

// ── REVEAL ON SCROLL ──
(function () {
  function revealGroup(selector, dy, stagger) {
    document.querySelectorAll(selector).forEach((el, i) => {
      el.style.opacity = "0";
      el.style.transform = `translateY(${dy}px)`;
      el.style.transition = `opacity 0.6s ease ${stagger ? i * stagger : 0}ms, transform 0.6s ease ${stagger ? i * stagger : 0}ms`;
      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          el.style.opacity = "1";
          el.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.1 });
      obs.observe(el);
    });
  }

  revealGroup(".js-reveal",             24, 0);
  revealGroup(".feat-row",              28, 0);
  revealGroup(".sec-card",              24, 100);
  revealGroup(".tech-card",             24, 90);
  revealGroup(".service-pillar",        24, 150);
  revealGroup(".testimonial-card",      24, 90);
  revealGroup(".footer-inner > div",    20, 80);
  revealGroup(".js-reveal-item",        14, 0);
})();

// ── STATS STRIP COUNTERS ──
(function () {
  const stats = [
    { id: "stat-clients-root",  target: 6,  suffix: "" },
    { id: "stat-policies-root", target: 3,  suffix: "" },
    { id: "stat-modules-root",  target: 7,  suffix: "" },
    { id: "stat-years-root",    target: 9,  suffix: "+" },
  ];
  stats.forEach(({ id, target, suffix }) => {
    const el = document.getElementById(id);
    if (!el) return;
    const span = document.createElement("span");
    span.className = "stat-num-inner";
    span.textContent = "0";
    el.appendChild(span);
    observeCounter(span, target, suffix, 1200);
  });
})();

// ── TESTIMONIAL CAROUSEL ──
(function () {
  const track = document.getElementById("testimonial-carousel");
  const prevBtn = document.getElementById("testimonial-prev");
  const nextBtn = document.getElementById("testimonial-next");
  if (!track || !prevBtn || !nextBtn) return;

  function cardStep() {
    const card = track.querySelector(".testimonial-card");
    if (!card) return track.clientWidth;
    const style = getComputedStyle(track);
    const gap = parseFloat(style.columnGap || style.gap) || 0;
    return card.getBoundingClientRect().width + gap;
  }

  function updateButtons() {
    const maxScroll = track.scrollWidth - track.clientWidth - 2;
    prevBtn.disabled = track.scrollLeft <= 2;
    nextBtn.disabled = maxScroll <= 2 || track.scrollLeft >= maxScroll;
  }

  prevBtn.addEventListener("click", () => track.scrollBy({ left: -cardStep(), behavior: "smooth" }));
  nextBtn.addEventListener("click", () => track.scrollBy({ left: cardStep(), behavior: "smooth" }));
  track.addEventListener("scroll", updateButtons, { passive: true });
  window.addEventListener("resize", updateButtons);
  updateButtons();
})();
