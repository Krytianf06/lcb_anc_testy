import { useEffect, useState } from "react";
import { deleteImage, listImages, uploadImage } from "../api/uploads.js";
import { deleteFile, listFiles, uploadFile } from "../api/files.js";

export default function UploadsManager({ token }) {
  const [images, setImages] = useState([]);
  const [files, setFiles] = useState([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [imgAlt, setImgAlt] = useState("");
  const [tab, setTab] = useState("images");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const imgData = await listImages(token);
      setImages(imgData?.images ?? []);
      const fileData = await listFiles(token);
      setFiles(fileData?.files ?? []);
    } catch (err) {
      setError(err.message || "Błąd pobierania plików");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const handleImageUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError("");
    try {
      await uploadImage(file, imgAlt, token);
      setImgAlt("");
      e.target.value = "";
      await load();
    } catch (err) {
      setError(err.message || "Błąd uploadu obrazu");
    }
  };

  const handleFileUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError("");
    try {
      await uploadFile(file, token);
      e.target.value = "";
      await load();
    } catch (err) {
      setError(err.message || "Błąd uploadu pliku");
    }
  };

  const handleDeleteImage = async (id) => {
    const force = confirm("Wymusić usunięcie jeśli obraz jest używany?");
    setError("");
    try {
      await deleteImage(id, force, token);
      await load();
    } catch (err) {
      setError(err.message || "Błąd usuwania obrazu");
    }
  };

  const handleDeleteFile = async (id) => {
    if (!confirm("Usunąć plik?")) return;
    setError("");
    try {
      await deleteFile(id, token);
      await load();
    } catch (err) {
      setError(err.message || "Błąd usuwania pliku");
    }
  };

  return (
    <div className="max-w-6xl mx-auto px-4 py-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-bold text-gray-800">Pliki</h2>
        <button
          onClick={load}
          className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-200"
        >
          Odśwież
        </button>
      </div>

      <div className="flex gap-2 mb-4">
        <button
          onClick={() => setTab("images")}
          className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
            tab === "images"
              ? "bg-blue-600 text-white"
              : "bg-gray-100 text-gray-700"
          }`}
        >
          Obrazy
        </button>
        <button
          onClick={() => setTab("files")}
          className={`px-3 py-1.5 text-xs font-semibold rounded-lg ${
            tab === "files"
              ? "bg-blue-600 text-white"
              : "bg-gray-100 text-gray-700"
          }`}
        >
          Pliki (PDF/DOC)
        </button>
      </div>

      {loading ? (
        <div className="text-sm text-gray-500">Ładowanie...</div>
      ) : tab === "images" ? (
        <div className="space-y-4">
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
              <input
                className="border rounded px-2 py-1 text-sm"
                placeholder="alt text"
                value={imgAlt}
                onChange={(e) => setImgAlt(e.target.value)}
              />
              <input type="file" accept="image/*" onChange={handleImageUpload} />
            </div>
          </div>
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {images.map((img) => (
                <div key={img.id} className="border rounded overflow-hidden">
                  <img
                    src={`/${img.file_path}`}
                    alt={img.alt_text || ""}
                    className="w-full h-32 object-cover"
                  />
                  <div className="p-2 text-xs">
                    <div className="truncate">{img.original_name}</div>
                    <button
                      onClick={() => handleDeleteImage(img.id)}
                      className="mt-1 px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded"
                    >
                      Usuń
                    </button>
                  </div>
                </div>
              ))}
              {images.length === 0 ? (
                <div className="text-sm text-gray-500">Brak obrazów.</div>
              ) : null}
            </div>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <input type="file" accept=".pdf,.doc,.docx" onChange={handleFileUpload} />
          </div>
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <div className="space-y-2">
              {files.map((f) => (
                <div
                  key={f.id}
                  className="border rounded px-3 py-2 flex items-center justify-between"
                >
                  <div className="text-sm">
                    {f.original_name}{" "}
                    <span className="text-xs text-gray-500">
                      ({f.extension})
                    </span>
                  </div>
                  <div className="flex gap-2">
                    <a
                      href={`/${f.file_path}`}
                      className="text-xs text-blue-600 hover:underline"
                      target="_blank"
                      rel="noreferrer"
                    >
                      Pobierz
                    </a>
                    <button
                      onClick={() => handleDeleteFile(f.id)}
                      className="px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded"
                    >
                      Usuń
                    </button>
                  </div>
                </div>
              ))}
              {files.length === 0 ? (
                <div className="text-sm text-gray-500">Brak plików.</div>
              ) : null}
            </div>
          </div>
        </div>
      )}

      {error ? (
        <div className="mt-4 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
          {error}
        </div>
      ) : null}
    </div>
  );
}
