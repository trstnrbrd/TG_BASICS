/* Login page: the submit button (with loading state and Enter-key handling) and the lockout toast.
 *
 * 2026-09-25: precompiled from JSX (was `type="text/babel"`, compiled by babel-standalone in the browser on
 * every visit to the login page — the single heaviest thing on this page). Precompiled once with
 * @babel/standalone (classic runtime) into the React.createElement() calls below; behavior is unchanged, this
 * is a mechanical transform. React and ReactDOM (UMD) still load — this file calls their hooks and createRoot()
 * same as before. Edit as plain React.createElement() calls; don't reintroduce a browser Babel dependency.
 */
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
  return (/*#__PURE__*/
    React.createElement("div", {
      style: {
        display: "flex",
        alignItems: "center",
        gap: "0.6rem",
        background: type === "lockout" ? "#FDF2F2" : "#1C1A17",
        border:
        type === "lockout" ?
        "1px solid rgba(192,57,43,0.25)" :
        "1px solid rgba(212,160,23,0.25)",
        color: type === "lockout" ? "#C0392B" : "#D4A017",
        padding: "0.75rem 1.25rem",
        borderRadius: "10px",
        fontSize: "0.8rem",
        fontWeight: "600",
        fontFamily: "'Plus Jakarta Sans',sans-serif",
        boxShadow: "0 8px 24px rgba(0,0,0,0.15)",
        pointerEvents: "auto",
        animation: leaving ?
        "toastOut 0.4s ease forwards" :
        "toastIn 0.35s ease forwards",
        maxWidth: "360px",
        lineHeight: "1.4"
      } }, /*#__PURE__*/

    React.createElement("span", {
      style: { flexShrink: 0, display: "flex", alignItems: "center" },
      dangerouslySetInnerHTML: {
        __html: type === "lockout" ? iconLockout : iconWarning
      } }
    ), /*#__PURE__*/
    React.createElement("span", null, message)
    ));

}

function ToastManager({ initialToast }) {
  const [toasts, setToasts] = useState(
    initialToast ? [{ id: Date.now(), ...initialToast }] : []
  );
  const remove = (id) => setToasts((prev) => prev.filter((t) => t.id !== id));
  return (/*#__PURE__*/
    React.createElement(React.Fragment, null,
    toasts.map((t) => /*#__PURE__*/
    React.createElement(Toast, {
      key: t.id,
      message: t.message,
      type: t.type,
      onDone: () => remove(t.id) }
    )
    )
    ));

}

function SubmitButton() {
  const [loading, setLoading] = useState(false);
  const loadingRef = React.useRef(false);

  const handleSubmit = () => {
    if (loadingRef.current) return;

    var usernameEl = document.getElementById("username");
    var passwordEl = document.getElementById("password");

    // Clear previous inline errors (helpers from login.js)
    if (typeof clearFieldError === "function") {
      clearFieldError(usernameEl);
      clearFieldError(passwordEl);
    }

    var username = usernameEl ? usernameEl.value.trim() : "";
    var password = passwordEl ? passwordEl.value.trim() : "";
    var valid = true;

    if (!username) {
      if (typeof showFieldError === "function")
      showFieldError(usernameEl, "Please enter your username.");
      valid = false;
    }
    if (!password) {
      if (typeof showFieldError === "function")
      showFieldError(passwordEl, "Please enter your password.");
      valid = false;
    }

    if (!valid) {
      if (!username && usernameEl) usernameEl.focus();else
      if (passwordEl) passwordEl.focus();
      return;
    }

    var form = document.getElementById("login-form");
    loadingRef.current = true;
    setLoading(true);
    setTimeout(() => form.submit(), 400);
  };

  useEffect(() => {
    const onKeyDown = (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        handleSubmit();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, []);

  const handleClick = handleSubmit;

  return (/*#__PURE__*/
    React.createElement("button", {
      type: "button",
      className: "btn-submit",
      id: "react-submit-btn",
      onClick: handleClick,
      disabled: loading },

    loading ? /*#__PURE__*/
    React.createElement(React.Fragment, null, /*#__PURE__*/
    React.createElement("div", { className: "spinner" }), "Signing in..."
    ) : /*#__PURE__*/

    React.createElement(React.Fragment, null, "Sign In to TG-BASICS")

    ));

}

ReactDOM.createRoot(document.getElementById("submit-root")).render(/*#__PURE__*/
  React.createElement(SubmitButton, null)
);

// Toast for lockout - injected via PHP data attributes
const toastRoot = document.getElementById("toast-root");
const lockoutData = toastRoot ? toastRoot.dataset.lockout : null;
const lockoutMsg = toastRoot ? toastRoot.dataset.message : null;
const iconLockout = toastRoot ? toastRoot.dataset.iconLockout : "";
const iconWarning = toastRoot ? toastRoot.dataset.iconWarning : "";

if (lockoutData === "1" && lockoutMsg) {
  ReactDOM.createRoot(toastRoot).render(/*#__PURE__*/
    React.createElement(ToastManager, { initialToast: { message: lockoutMsg, type: "lockout" } })
  );
}
