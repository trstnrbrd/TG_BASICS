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

// ── NAVBAR SCROLL SHRINK + ACTIVE LINK ──
function NavScroll() {
  useEffect(() => {
    const nav     = document.querySelector(".topnav");
    const links   = document.querySelectorAll(".nav-link[href^='#']");
    const sections = [...links].map(l => document.querySelector(l.getAttribute("href"))).filter(Boolean);

    const setActive = () => {
      const scrollY = window.scrollY + 120;
      let current = null;
      sections.forEach(sec => {
        if (sec.offsetTop <= scrollY) current = sec.id;
      });
      links.forEach(l => {
        l.classList.toggle("active", l.getAttribute("href") === "#" + current);
      });
    };

    const onScroll = () => {
      nav && nav.classList.toggle("scrolled", window.scrollY > 40);
      setActive();
    };

    // smooth scroll for nav links
    links.forEach(link => {
      link.addEventListener("click", e => {
        e.preventDefault();
        const target = document.querySelector(link.getAttribute("href"));
        if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });

    window.addEventListener("scroll", onScroll, { passive: true });
    setActive();
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

// ── SECTION TEXT REVEAL ──
function SectionTextReveal() {
  useEffect(() => {
    const els = document.querySelectorAll(".js-reveal");
    const observers = [];
    els.forEach((el, i) => {
      el.style.opacity = "0";
      el.style.transform = "translateY(24px)";
      el.style.transition = `opacity 0.65s ease, transform 0.65s ease`;

      const obs = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          el.style.opacity = "1";
          el.style.transform = "none";
          obs.disconnect();
        }
      }, { threshold: 0.12 });
      obs.observe(el);
      observers.push(obs);
    });
    return () => observers.forEach(o => o.disconnect());
  }, []);
  return null;
}

// ── STATS COUNTERS ──
function StatsStrip() {
  const stats = [
    { id: "stat-clients-root",  target: 6,  suffix: "" },
    { id: "stat-policies-root", target: 3,  suffix: "" },
    { id: "stat-modules-root",  target: 7,  suffix: "" },
    { id: "stat-years-root",    target: 9,  suffix: "+" },
  ];
  return (
    <>
      {stats.map(({ id, target, suffix }) => {
        const el = document.getElementById(id);
        if (!el) return null;
        return ReactDOM.createPortal(
          <span className="stat-num-inner">
            <AnimatedCounter target={target} suffix={suffix} duration={1200} />
          </span>,
          el
        );
      })}
    </>
  );
}

// ── MOUNT ALL ──
const heroStatRoot = document.getElementById("hero-stat-root");
if (heroStatRoot) ReactDOM.createRoot(heroStatRoot).render(<HeroStats />);

const twRoot = document.getElementById("typewriter-root");
if (twRoot) ReactDOM.createRoot(twRoot).render(
  <Typewriter words={["Always ready.", "Always accurate.", "Always organized."]} />
);

const revealRoot = document.createElement("div");
revealRoot.style.display = "none";
document.body.appendChild(revealRoot);
ReactDOM.createRoot(revealRoot).render(
  <>
    <NavScroll />
    <ModulesReveal />
    <RoleCardsReveal />
    <TechCardsReveal />
    <FooterReveal />
    <SectionTextReveal />
    <StatsStrip />
  </>
);
