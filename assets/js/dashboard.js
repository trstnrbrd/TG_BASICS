const { useState, useEffect, useRef } = React;

// ── LIVE CLOCK ──
function LiveClock() {
  const [now, setNow] = useState(new Date());
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  const h = now.getHours();
  const m = String(now.getMinutes()).padStart(2, "0");
  const hr12 = h % 12 || 12;
  const period = h >= 12 ? "PM" : "AM";
  const opts = {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  };
  const dateStr = now.toLocaleDateString("en-US", opts);
  return (
    <div className="dash-welcome-right">
      <div className="dash-clock">
        {hr12}:{m}
        <span className="dash-clock-period">{period}</span>
      </div>
      <div className="dash-date">{dateStr}</div>
    </div>
  );
}

// ── ANIMATED COUNTER ──
function AnimatedCounter({ target, duration = 1000 }) {
  const [count, setCount] = useState(0);
  useEffect(() => {
    if (target === 0) {
      setCount(0);
      return;
    }
    let start = 0;
    const step = Math.max(1, Math.ceil(target / (duration / 16)));
    const timer = setInterval(() => {
      start += step;
      if (start >= target) {
        setCount(target);
        clearInterval(timer);
      } else setCount(start);
    }, 16);
    return () => clearInterval(timer);
  }, [target]);
  return <>{count}</>;
}

// ── STAT CARDS ──
function StatCards({ stats }) {
  const themes = {
    gold: {
      accent: "linear-gradient(90deg,#D4A017,#E8D5A3)",
      iconBg: "var(--gold-light)",
      iconColor: "var(--gold)",
    },
    green: {
      accent: "linear-gradient(90deg,#2E7D52,#52B788)",
      iconBg: "var(--success-bg)",
      iconColor: "var(--success)",
    },
    amber: {
      accent: "linear-gradient(90deg,#B8860B,#D4A017)",
      iconBg: "var(--warning-bg)",
      iconColor: "var(--warning)",
    },
    red: {
      accent: "linear-gradient(90deg,#C0392B,#E74C3C)",
      iconBg: "var(--danger-bg)",
      iconColor: "var(--danger)",
    },
    blue: {
      accent: "linear-gradient(90deg,#1A6B9A,#3498DB)",
      iconBg: "var(--info-bg)",
      iconColor: "var(--info)",
    },
  };

  return (
    <div className="dash-stats">
      {stats.map((s, i) => {
        const t = themes[s.theme] || themes.gold;
        return (
          <div className="dash-stat" key={i}>
            <div
              className="dash-stat-accent"
              style={{ background: t.accent }}
            />
            <div className="dash-stat-top">
              <div
                className="dash-stat-icon"
                style={{ background: t.iconBg, color: t.iconColor }}
                dangerouslySetInnerHTML={{ __html: s.icon }}
              />
              <span
                className="dash-stat-badge"
                style={{
                  background: s.trendUp ? "var(--success-bg)" : "var(--bg)",
                  color: s.trendUp ? "var(--success)" : "var(--text-muted)",
                  border: s.trendUp ? "none" : "1px solid var(--border)",
                }}
              >
                {s.trend}
              </span>
            </div>
            <div className="dash-stat-value">
              {s.prefix || ""}
              <AnimatedCounter target={s.value} />
            </div>
            <div className="dash-stat-label">{s.label}</div>
          </div>
        );
      })}
    </div>
  );
}

// ── ACTIVITY FEED ──
function ActivityFeed({ items }) {
  const dotColors = {
    LOGIN: "var(--success)",
    LOGOUT: "var(--text-muted)",
    ACCOUNT_CREATED: "var(--gold-bright)",
    ACCOUNT_DELETED: "var(--danger)",
    PASSWORD_RESET: "var(--warning)",
    CLIENT_ADDED: "var(--success)",
    CLIENT_UPDATED: "var(--warning)",
    VEHICLE_ADDED: "var(--success)",
    POLICY_CREATED: "var(--gold-bright)",
    POLICY_SAVED: "var(--success)",
  };

  if (!items.length) {
    return (
      <div
        style={{
          padding: "2rem",
          textAlign: "center",
          color: "var(--text-muted)",
          fontSize: "0.8rem",
        }}
      >
        No recent activity.
      </div>
    );
  }

  return (
    <>
      {items.map((item, i) => (
        <div className="activity-item" key={i}>
          <div className="activity-line">
            <div
              className="activity-dot"
              style={{ background: dotColors[item.action] || "var(--border)" }}
            />
            {i < items.length - 1 && <div className="activity-connector" />}
          </div>
          <div className="activity-body">
            <div
              className="activity-text"
              dangerouslySetInnerHTML={{
                __html: item.description.replace(
                  /^([^\s]+\s[^\s]+)/,
                  "<strong>$1</strong>",
                ),
              }}
            />
            <div className="activity-time">{item.time_ago}</div>
          </div>
        </div>
      ))}
    </>
  );
}

// ── MOUNT ──
document.addEventListener("DOMContentLoaded", function () {
  // Clock
  const clockRoot = document.getElementById("dash-clock-root");
  if (clockRoot) ReactDOM.createRoot(clockRoot).render(<LiveClock />);

  // Stats
  const statsRoot = document.getElementById("dash-stats-root");
  if (statsRoot) {
    const stats = JSON.parse(statsRoot.dataset.stats || "[]");
    ReactDOM.createRoot(statsRoot).render(<StatCards stats={stats} />);
  }

  // Activity Feed
  const activityRoot = document.getElementById("dash-activity-root");
  if (activityRoot) {
    const items = JSON.parse(activityRoot.dataset.items || "[]");
    ReactDOM.createRoot(activityRoot).render(<ActivityFeed items={items} />);
  }
});
