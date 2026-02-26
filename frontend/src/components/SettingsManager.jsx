import { useEffect, useState } from "react";
import { bulkUpdateSettings, listSettings } from "../api/settings.js";

export default function SettingsManager({ token }) {
  const [settings, setSettings] = useState([]);
  const [edited, setEdited] = useState({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const data = await listSettings(token);
      setSettings(Array.isArray(data) ? data : []);
      setEdited({});
    } catch (err) {
      setError(err.message || "Błąd pobierania ustawień");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const updateEdited = (key, field, value) => {
    setEdited((s) => ({
      ...s,
      [key]: { ...(s[key] || {}), [field]: value },
    }));
  };

  const handleSaveAll = async () => {
    const payload = Object.entries(edited).map(([key, values]) => ({
      key,
      value_pl: values.value_pl,
      value_en: values.value_en,
    }));
    if (payload.length === 0) return;
    setError("");
    try {
      await bulkUpdateSettings(payload, token);
      await load();
    } catch (err) {
      setError(err.message || "Błąd zapisu ustawień");
    }
  };

  return (
    <div className="max-w-6xl mx-auto px-4 py-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-bold text-gray-800">Ustawienia</h2>
        <div className="flex gap-2">
          <button
            onClick={load}
            className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-200"
          >
            Odśwież
          </button>
          <button
            onClick={handleSaveAll}
            className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
          >
            Zapisz zmiany
          </button>
        </div>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-4 text-sm text-gray-500">Ładowanie...</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-2 text-xs font-semibold text-gray-600">
                  Klucz
                </th>
                <th className="text-left px-4 py-2 text-xs font-semibold text-gray-600">
                  value_pl
                </th>
                <th className="text-left px-4 py-2 text-xs font-semibold text-gray-600">
                  value_en
                </th>
              </tr>
            </thead>
            <tbody>
              {settings.map((s) => (
                <tr key={s.id} className="border-b border-gray-100">
                  <td className="px-4 py-2 text-gray-700 font-mono text-xs">
                    {s.setting_key}
                  </td>
                  <td className="px-4 py-2">
                    <input
                      className="border rounded px-2 py-1 text-sm w-full"
                      defaultValue={s.value_pl || ""}
                      onChange={(e) =>
                        updateEdited(s.setting_key, "value_pl", e.target.value)
                      }
                    />
                  </td>
                  <td className="px-4 py-2">
                    <input
                      className="border rounded px-2 py-1 text-sm w-full"
                      defaultValue={s.value_en || ""}
                      onChange={(e) =>
                        updateEdited(s.setting_key, "value_en", e.target.value)
                      }
                    />
                  </td>
                </tr>
              ))}
              {settings.length === 0 ? (
                <tr>
                  <td
                    colSpan={3}
                    className="px-4 py-6 text-center text-gray-500"
                  >
                    Brak ustawień.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        )}
      </div>

      {error ? (
        <div className="mt-4 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
          {error}
        </div>
      ) : null}
    </div>
  );
}
