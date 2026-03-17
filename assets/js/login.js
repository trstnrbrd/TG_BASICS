const { useState, useEffect } = React;

function Toast({ message, type, onDone }) {
  const [leaving, setLeaving] = useState(false);
  useEffect(() => {
    const t1 = setTimeout(() => setLeaving(true), 3500);
    const t2 = setTimeout(() => onDone(), 4000);
    return () => {
      clearTimeout(t1);
      clearTimeout(t2);
    };
  }, []);
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "0.6rem",
        background: type === "lockout" ? "#FDF2F2" : "#1C1A17",
        border:
          type === "lockout"
            ? "1px solid rgba(192,57,43,0.25)"
            : "1px solid rgba(212,160,23,0.25)",
        color: type === "lockout" ? "#C0392B" : "#D4A017",
        padding: "0.75rem 1.25rem",
        borderRadius: "10px",
        fontSize: "0.8rem",
        fontWeight: "600",
        fontFamily: "'Plus Jakarta Sans',sans-serif",
        boxShadow: "0 8px 24px rgba(0,0,0,0.15)",
        pointerEvents: "auto",
        animation: leaving
          ? "toastOut 0.4s ease forwards"
          : "toastIn 0.35s ease forwards",
        maxWidth: "360px",
        lineHeight: "1.4",
      }}
    >
      <span style={{ fontSize: "1rem", flexShrink: 0 }}>
        {type === "lockout" ? "🔒" : "⚠️"}
      </span>
      <span>{message}</span>
    </div>
  );
}

function ToastManager({ initialToast }) {
  const [toasts, setToasts] = useState(
    initialToast ? [{ id: Date.now(), ...initialToast }] : [],
  );
  const remove = (id) => setToasts((prev) => prev.filter((t) => t.id !== id));
  return (
    <>
      {toasts.map((t) => (
        <Toast
          key={t.id}
          message={t.message}
          type={t.type}
          onDone={() => remove(t.id)}
        />
      ))}
    </>
  );
}

function SubmitButton() {
  const [loading, setLoading] = useState(false);
  const handleClick = () => {
    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value.trim();
    if (!username || !password) return;
    setLoading(true);
    setTimeout(() => document.getElementById("login-form").submit(), 400);
  };
  return (
    <button
      type="button"
      className="btn-submit"
      onClick={handleClick}
      disabled={loading}
    >
      {loading ? (
        <>
          <div className="spinner"></div>Signing in...
        </>
      ) : (
        <>Sign In to TG-BASICS</>
      )}
    </button>
  );
}

ReactDOM.createRoot(document.getElementById("submit-root")).render(
  <SubmitButton />,
);

// Toast for lockout - injected via PHP data attribute
const toastRoot = document.getElementById("toast-root");
const lockoutData = toastRoot ? toastRoot.dataset.lockout : null;
const lockoutMsg = toastRoot ? toastRoot.dataset.message : null;
if (lockoutData === "1" && lockoutMsg) {
  ReactDOM.createRoot(toastRoot).render(
    <ToastManager initialToast={{ message: lockoutMsg, type: "lockout" }} />,
  );
}
