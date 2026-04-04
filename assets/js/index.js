const { useState, useEffect, useRef } = React;

// ── ANIMATED COUNTER ──
function AnimatedCounter({ target, suffix = "", duration = 1400 }) {
  const [count, setCount] = useState(0);
  const ref = useRef(null);
  const started = useRef(false);

  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && !started.current) {
          started.current = true;
          let start = 0;
          const step = Math.ceil(target / (duration / 20));
          const timer = setInterval(() => {
            start += step;
            if (start >= target) {
              setCount(target);
              clearInterval(timer);
            } else {
              setCount(start);
            }
          }, 20);
        }
      },
      { threshold: 0.5 }
    );
    if (ref.current) observer.observe(ref.current);
    return () => observer.disconnect();
  }, [target]);

  return <span ref={ref}>{count}{suffix}</span>;
}

// ── HERO CARD STATS ──
function HeroStats() {
  const stats = [
    { num: 48, label: "Clients" },
    { num: 31, label: "Policies" },
    { num: 7,  label: "In Repair" },
  ];
  return (
    <>
      {stats.map((s, i) => (
        <div key={i} className="hc-stat">
          <div className="hc-stat-num">
            <AnimatedCounter target={s.num} duration={1000 + i * 200} />
          </div>
          <div className="hc-stat-label">{s.label}</div>
        </div>
      ))}
    </>
  );
}

// ── TYPEWRITER ──
function Typewriter({ words, speed = 80, pause = 1800 }) {
  const [display, setDisplay] = useState("");
  const [wordIdx, setWordIdx] = useState(0);
  const [charIdx, setCharIdx] = useState(0);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    const current = words[wordIdx];
    const timeout = setTimeout(() => {
      if (!deleting) {
        setDisplay(current.slice(0, charIdx + 1));
        if (charIdx + 1 === current.length) {
          setTimeout(() => setDeleting(true), pause);
        } else {
          setCharIdx(c => c + 1);
        }
      } else {
        setDisplay(current.slice(0, charIdx - 1));
        if (charIdx - 1 === 0) {
          setDeleting(false);
          setWordIdx(w => (w + 1) % words.length);
          setCharIdx(0);
        } else {
          setCharIdx(c => c - 1);
        }
      }
    }, deleting ? speed / 2 : speed);
    return () => clearTimeout(timeout);
  }, [charIdx, deleting, wordIdx]);

  return (
    <span style={{ color: "var(--gold-bright)", position: "relative" }}>
      {display}
      <span style={{ borderRight: "2px solid var(--gold-bright)", marginLeft: "2px", animation: "blink 0.7s step-end infinite" }}></span>
    </span>
  );
}

// ── NAVBAR SCROLL SHRINK ──
function NavScroll() {
  useEffect(() => {
    const nav = document.querySelector(".topnav");
    if (!nav) return;
    const onScroll = () => {
      if (window.scrollY > 40) {
        nav.classList.add("scrolled");
      } else {
        nav.classList.remove("scrolled");
      }
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);
  return null;
}

// ── MODULES GRID REVEAL ──
function ModulesReveal() {
  const cards = document.querySelectorAll(".module-card");
  useEffect(() => {
    const observers = [];
    cards.forEach((card, i) => {
      card.style.opacity = "0";
      card.style.transform = "translateY(28px)";
      card.style.transition = `opacity 0.6s ease ${i * 80}ms, transform 0.6s ease ${i * 80}ms`;

      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          card.style.opacity = "1";
          card.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.1 });
      obs.observe(card);
      observers.push(obs);
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── ROLE CARDS REVEAL ──
function RoleCardsReveal() {
  useEffect(() => {
    const cards = document.querySelectorAll(".role-card");
    const observers = [];
    cards.forEach((card, i) => {
      card.style.opacity = "0";
      card.style.transform = "translateY(24px)";
      card.style.transition = `opacity 0.6s ease ${i * 100}ms, transform 0.6s ease ${i * 100}ms`;

      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          card.style.opacity = "1";
          card.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.1 });
      obs.observe(card);
      observers.push(obs);
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── TECH CARDS REVEAL ──
function TechCardsReveal() {
  useEffect(() => {
    const cards = document.querySelectorAll(".tech-card");
    const observers = [];
    cards.forEach((card, i) => {
      card.style.opacity = "0";
      card.style.transform = "translateY(24px)";
      card.style.transition = `opacity 0.6s ease ${i * 90}ms, transform 0.6s ease ${i * 90}ms`;

      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          card.style.opacity = "1";
          card.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.1 });
      obs.observe(card);
      observers.push(obs);
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── ABOUT STRIP REVEAL ──
function AboutStripReveal() {
  useEffect(() => {
    const strip = document.querySelector(".about-strip .strip-inner");
    if (!strip) return;
    strip.style.opacity = "0";
    strip.style.transform = "translateY(20px)";
    strip.style.transition = "opacity 0.7s ease, transform 0.7s ease";

    const obs = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) {
        strip.style.opacity = "1";
        strip.style.transform = "none";
        obs.disconnect();
      }
    }, { threshold: 0.2 });
    obs.observe(strip);
    return () => obs.disconnect();
  }, []);
  return null;
}

// ── FOOTER REVEAL ──
function FooterReveal() {
  useEffect(() => {
    const cols = document.querySelectorAll(".footer-inner > div");
    const observers = [];
    cols.forEach((col, i) => {
      col.style.opacity = "0";
      col.style.transform = "translateY(20px)";
      col.style.transition = `opacity 0.6s ease ${i * 80}ms, transform 0.6s ease ${i * 80}ms`;

      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          col.style.opacity = "1";
          col.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.1 });
      obs.observe(col);
      observers.push(obs);
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── SECTION LABEL + TITLE + DESC REVEAL ──
function SectionTextReveal() {
  useEffect(() => {
    const groups = document.querySelectorAll(
      ".modules-section, .roles-section, .tech-section"
    );
    const observers = [];
    groups.forEach(section => {
      const label = section.querySelector(".section-label");
      const title = section.querySelector(".section-title");
      const desc  = section.querySelector(".section-desc");

      [label, title, desc].forEach((el, i) => {
        if (!el) return;
        el.style.opacity = "0";
        el.style.transform = "translateY(20px)";
        el.style.transition = `opacity 0.6s ease ${i * 100}ms, transform 0.6s ease ${i * 100}ms`;

        const obs = new IntersectionObserver(([entry]) => {
          if (entry.isIntersecting) {
            el.style.opacity = "1";
            el.style.transform = "none";
            obs.disconnect();
          }
        }, { threshold: 0.15 });
        obs.observe(el);
        observers.push(obs);
      });
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── MOUNT ALL ──
const heroStatRoot = document.getElementById("hero-stat-root");
if (heroStatRoot) ReactDOM.createRoot(heroStatRoot).render(<HeroStats />);

const twRoot = document.getElementById("typewriter-root");
if (twRoot) ReactDOM.createRoot(twRoot).render(
  <Typewriter words={["Always ready.", "Always accurate.", "Always organized."]} />
);

// Mount all reveal controllers into a single hidden root
const revealRoot = document.createElement("div");
revealRoot.style.display = "none";
document.body.appendChild(revealRoot);
ReactDOM.createRoot(revealRoot).render(
  <>
    <NavScroll />
    <ModulesReveal />
    <RoleCardsReveal />
    <TechCardsReveal />
    <AboutStripReveal />
    <FooterReveal />
    <SectionTextReveal />
  </>
);
