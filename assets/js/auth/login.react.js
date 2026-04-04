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
      <span style={{ flexShrink: 0, display: "flex", alignItems: "center" }}
        dangerouslySetInnerHTML={{ __html: type === "lockout" ? iconLockout : iconWarning }}
      />
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
    var form = document.getElementById("login-form");
    var event = new Event("submit", { bubbles: true, cancelable: true });
    var allowed = form.dispatchEvent(event);
    if (!allowed) return;
    setLoading(true);
    setTimeout(() => form.submit(), 400);
  };

  return (
    <button
      type="button"
      className="btn-submit"
      id="react-submit-btn"
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

// Toast for lockout - injected via PHP data attributes
const toastRoot    = document.getElementById("toast-root");
const lockoutData  = toastRoot ? toastRoot.dataset.lockout : null;
const lockoutMsg   = toastRoot ? toastRoot.dataset.message : null;
const iconLockout  = toastRoot ? toastRoot.dataset.iconLockout : '';
const iconWarning  = toastRoot ? toastRoot.dataset.iconWarning : '';

if (lockoutData === "1" && lockoutMsg) {
  ReactDOM.createRoot(toastRoot).render(
    <ToastManager initialToast={{ message: lockoutMsg, type: "lockout" }} />,
  );
}
