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
      { threshold: 0.5 },
    );

    if (ref.current) observer.observe(ref.current);
    return () => observer.disconnect();
  }, [target]);

  return (
    <span ref={ref}>
      {count}
      {suffix}
    </span>
  );
}

// ── HERO CARD STATS - animated on load ──
function HeroStats() {
  const stats = [
    { num: 48, label: "Clients", suffix: "" },
    { num: 31, label: "Policies", suffix: "" },
    { num: 7, label: "In Repair", suffix: "" },
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

// ── TYPEWRITER for hero title accent ──
function Typewriter({ words, speed = 80, pause = 1800 }) {
  const [display, setDisplay] = useState("");
  const [wordIdx, setWordIdx] = useState(0);
  const [charIdx, setCharIdx] = useState(0);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    const current = words[wordIdx];

    const timeout = setTimeout(
      () => {
        if (!deleting) {
          setDisplay(current.slice(0, charIdx + 1));
          if (charIdx + 1 === current.length) {
            setTimeout(() => setDeleting(true), pause);
          } else {
            setCharIdx((c) => c + 1);
          }
        } else {
          setDisplay(current.slice(0, charIdx - 1));
          if (charIdx - 1 === 0) {
            setDeleting(false);
            setWordIdx((w) => (w + 1) % words.length);
            setCharIdx(0);
          } else {
            setCharIdx((c) => c - 1);
          }
        }
      },
      deleting ? speed / 2 : speed,
    );

    return () => clearTimeout(timeout);
  }, [charIdx, deleting, wordIdx]);

  return (
    <span style={{ color: "var(--gold-bright)", position: "relative" }}>
      {display}
      <span
        style={{
          borderRight: "2px solid var(--gold-bright)",
          marginLeft: "2px",
          animation: "blink 0.7s step-end infinite",
        }}
      ></span>
    </span>
  );
}

// ── SCROLL REVEAL wrapper ──
function RevealOnScroll({ children, delay = 0 }) {
  const ref = useRef(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setTimeout(() => setVisible(true), delay);
          observer.disconnect();
        }
      },
      { threshold: 0.15 },
    );

    if (ref.current) observer.observe(ref.current);
    return () => observer.disconnect();
  }, []);

  return (
    <div
      ref={ref}
      style={{
        opacity: visible ? 1 : 0,
        transform: visible ? "translateY(0)" : "translateY(24px)",
        transition: `opacity 0.6s ease, transform 0.6s ease`,
      }}
    >
      {children}
    </div>
  );
}

// ── MOUNT HERO CARD STATS ──
const heroStatRoot = document.getElementById("hero-stat-root");
if (heroStatRoot) {
  ReactDOM.createRoot(heroStatRoot).render(<HeroStats />);
}

// ── MOUNT TYPEWRITER in hero title ──
const twRoot = document.getElementById("typewriter-root");
if (twRoot) {
  ReactDOM.createRoot(twRoot).render(
    <Typewriter
      words={["Always ready.", "Always accurate.", "Always organized."]}
    />,
  );
}
