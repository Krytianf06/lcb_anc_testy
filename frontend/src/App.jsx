import { useEffect, useMemo, useState } from "react";
import { login as apiLogin, logout as apiLogout } from "./api/auth.js";
import LoginForm from "./components/LoginForm.jsx";
import PagesManager from "./components/PagesManager.jsx";
import MenuManager from "./components/MenuManager.jsx";
import SettingsManager from "./components/SettingsManager.jsx";
import UploadsManager from "./components/UploadsManager.jsx";

const STORAGE_KEY = "anc_auth";

function loadStoredAuth() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function storeAuth(auth) {
  if (!auth) {
    localStorage.removeItem(STORAGE_KEY);
    return;
  }
  localStorage.setItem(STORAGE_KEY, JSON.stringify(auth));
}

export default function App() {
  const [auth, setAuth] = useState(() => loadStoredAuth());
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [view, setView] = useState("pages");
  const token = auth?.token || null;

  const userLabel = useMemo(() => {
    if (!auth?.user) return "Gość";
    return `${auth.user.username} (${auth.user.role})`;
  }, [auth]);

  useEffect(() => {
    if (!token) setView("pages");
  }, [token]);

  const handleLogin = async (username, password) => {
    setLoading(true);
    setError("");
    try {
      const data = await apiLogin(username, password);
      const nextAuth = { token: data.token, user: data.user };
      setAuth(nextAuth);
      storeAuth(nextAuth);
    } catch (err) {
      setError(err.message || "Błąd logowania");
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    if (!token) return;
    setLoading(true);
    setError("");
    try {
      await apiLogout(token);
    } catch {
      // ignore logout errors
    } finally {
      setAuth(null);
      storeAuth(null);
      setLoading(false);
    }
  };

  if (!token) {
    return <LoginForm onSubmit={handleLogin} error={error} loading={loading} />;
  }

  return (
    <div className="min-h-screen bg-gray-100">
      <div className="bg-white border-b border-gray-200">
        <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
          <div>
            <div className="text-sm font-semibold text-gray-800">
              Portal Projektu | CMS
            </div>
            <div className="text-xs text-gray-500">Zalogowany: {userLabel}</div>
          </div>
          <button
            onClick={handleLogout}
            className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-red-500 text-white hover:bg-red-600"
          >
            Wyloguj
          </button>
        </div>
        <div className="max-w-6xl mx-auto px-4 pb-3 flex flex-wrap gap-2">
          <button
            onClick={() => setView("pages")}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
              view === "pages" ? "bg-blue-600 text-white" : "bg-gray-100"
            }`}
          >
            Strony i bloki
          </button>
          <button
            onClick={() => setView("menu")}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
              view === "menu" ? "bg-blue-600 text-white" : "bg-gray-100"
            }`}
          >
            Menu
          </button>
          <button
            onClick={() => setView("settings")}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
              view === "settings" ? "bg-blue-600 text-white" : "bg-gray-100"
            }`}
          >
            Ustawienia
          </button>
          <button
            onClick={() => setView("uploads")}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
              view === "uploads" ? "bg-blue-600 text-white" : "bg-gray-100"
            }`}
          >
            Pliki
          </button>
        </div>
      </div>

      {view === "pages" ? <PagesManager token={token} /> : null}
      {view === "menu" ? <MenuManager token={token} /> : null}
      {view === "settings" ? <SettingsManager token={token} /> : null}
      {view === "uploads" ? <UploadsManager token={token} /> : null}

      {error ? (
        <div className="max-w-6xl mx-auto px-4 pb-6">
          <div className="text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
            {error}
          </div>
        </div>
      ) : null}
    </div>
  );
}
