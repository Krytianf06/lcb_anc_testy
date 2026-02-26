import { useEffect, useMemo, useState } from "react";
import {
  createPage,
  deletePage,
  getPage,
  listPages,
  reorderPages,
  updatePage,
} from "../api/pages.js";
import {
  createBlock,
  deleteBlock,
  duplicateBlock,
  reorderBlocks,
  updateBlock,
} from "../api/blocks.js";

const BLOCK_TYPES = [
  "heading",
  "text",
  "image",
  "button",
  "link",
  "html",
  "php",
  "map",
  "download",
  "spacer",
];

const BLOCK_WIDTHS = ["full", "half", "third", "two-thirds"];

function safeJsonParse(value) {
  try {
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
}

export default function PagesManager({ token }) {
  const [pages, setPages] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [pageDetail, setPageDetail] = useState(null);
  const [blocks, setBlocks] = useState([]);
  const [loading, setLoading] = useState(false);
  const [blocksLoading, setBlocksLoading] = useState(false);
  const [error, setError] = useState("");

  const [newPage, setNewPage] = useState({
    slug: "",
    title_pl: "",
    title_en: "",
    is_active: 1,
  });

  const [newBlock, setNewBlock] = useState({
    type: "text",
    content_pl: "",
    content_en: "",
    width: "full",
    settings: "",
  });

  const loadPages = async () => {
    setLoading(true);
    setError("");
    try {
      const data = await listPages(token);
      setPages(data?.pages ?? []);
      if (!selectedId && data?.pages?.length) {
        setSelectedId(data.pages[0].id);
      }
    } catch (err) {
      setError(err.message || "Błąd pobierania stron");
    } finally {
      setLoading(false);
    }
  };

  const loadPageDetail = async (id) => {
    if (!id) return;
    setBlocksLoading(true);
    setError("");
    try {
      const data = await getPage({ id }, token);
      setPageDetail(data?.page ?? null);
      setBlocks(data?.page?.blocks ?? []);
    } catch (err) {
      setError(err.message || "Błąd pobierania strony");
    } finally {
      setBlocksLoading(false);
    }
  };

  useEffect(() => {
    loadPages();
  }, []);

  useEffect(() => {
    if (selectedId) loadPageDetail(selectedId);
  }, [selectedId]);

  const handleCreatePage = async () => {
    setError("");
    try {
      await createPage(newPage, token);
      setNewPage({ slug: "", title_pl: "", title_en: "", is_active: 1 });
      await loadPages();
    } catch (err) {
      setError(err.message || "Błąd tworzenia strony");
    }
  };

  const handleUpdatePage = async () => {
    if (!pageDetail) return;
    setError("");
    try {
      await updatePage(
        {
          id: pageDetail.id,
          slug: pageDetail.slug,
          title_pl: pageDetail.title_pl,
          title_en: pageDetail.title_en,
          is_active: pageDetail.is_active,
        },
        token
      );
      await loadPages();
    } catch (err) {
      setError(err.message || "Błąd aktualizacji strony");
    }
  };

  const handleDeletePage = async (id) => {
    if (!id) return;
    if (!confirm("Usunąć stronę?")) return;
    setError("");
    try {
      await deletePage(id, token);
      setSelectedId(null);
      setPageDetail(null);
      setBlocks([]);
      await loadPages();
    } catch (err) {
      setError(err.message || "Błąd usuwania strony");
    }
  };

  const movePage = async (index, dir) => {
    const next = [...pages];
    const target = index + dir;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    setPages(next);
    try {
      await reorderPages(
        next.map((p) => p.id),
        token
      );
    } catch (err) {
      setError(err.message || "Błąd zmiany kolejności stron");
    }
  };

  const handleCreateBlock = async () => {
    if (!selectedId) return;
    setError("");
    try {
      const settingsObj = safeJsonParse(newBlock.settings);
      await createBlock(
        {
          page_id: selectedId,
          type: newBlock.type,
          content_pl: newBlock.content_pl,
          content_en: newBlock.content_en,
          width: newBlock.width,
          settings: settingsObj,
        },
        token
      );
      setNewBlock({
        type: "text",
        content_pl: "",
        content_en: "",
        width: "full",
        settings: "",
      });
      await loadPageDetail(selectedId);
    } catch (err) {
      setError(err.message || "Błąd tworzenia bloku");
    }
  };

  const handleUpdateBlock = async (block) => {
    setError("");
    try {
      const settingsObj = safeJsonParse(block.settings_raw);
      await updateBlock(
        {
          id: block.id,
          type: block.type,
          content_pl: block.content_pl,
          content_en: block.content_en,
          width: block.width,
          is_active: block.is_active ? 1 : 0,
          settings: settingsObj,
        },
        token
      );
      await loadPageDetail(selectedId);
    } catch (err) {
      setError(err.message || "Błąd aktualizacji bloku");
    }
  };

  const handleDeleteBlock = async (id) => {
    if (!confirm("Usunąć blok?")) return;
    setError("");
    try {
      await deleteBlock(id, token);
      await loadPageDetail(selectedId);
    } catch (err) {
      setError(err.message || "Błąd usuwania bloku");
    }
  };

  const handleDuplicateBlock = async (id) => {
    setError("");
    try {
      await duplicateBlock(id, token);
      await loadPageDetail(selectedId);
    } catch (err) {
      setError(err.message || "Błąd duplikowania bloku");
    }
  };

  const moveBlock = async (index, dir) => {
    const next = [...blocks];
    const target = index + dir;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    setBlocks(next);
    try {
      await reorderBlocks(
        selectedId,
        next.map((b) => b.id),
        token
      );
    } catch (err) {
      setError(err.message || "Błąd zmiany kolejności bloków");
    }
  };

  const blocksView = useMemo(
    () =>
      blocks.map((b) => ({
        ...b,
        settings_raw: b.settings ? JSON.stringify(b.settings, null, 2) : "",
      })),
    [blocks]
  );

  return (
    <div className="max-w-6xl mx-auto px-4 py-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-lg font-bold text-gray-800">Strony</h2>
            <button
              onClick={loadPages}
              className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-200"
            >
              Odśwież
            </button>
          </div>

          {loading ? (
            <div className="text-sm text-gray-500">Ładowanie...</div>
          ) : (
            <div className="space-y-2">
              {pages.map((p, idx) => (
                <div
                  key={p.id}
                  className={`border rounded-lg px-3 py-2 flex items-center justify-between ${
                    selectedId === p.id
                      ? "border-blue-400 bg-blue-50"
                      : "border-gray-200"
                  }`}
                >
                  <button
                    onClick={() => setSelectedId(p.id)}
                    className="text-left flex-1"
                  >
                    <div className="text-sm font-semibold text-gray-800">
                      {p.title_pl || "(brak tytułu)"}
                    </div>
                    <div className="text-xs text-gray-500">{p.slug}</div>
                  </button>
                  <div className="flex gap-1">
                    <button
                      onClick={() => movePage(idx, -1)}
                      className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                      title="W górę"
                    >
                      ↑
                    </button>
                    <button
                      onClick={() => movePage(idx, 1)}
                      className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                      title="W dół"
                    >
                      ↓
                    </button>
                    <button
                      onClick={() => handleDeletePage(p.id)}
                      className="w-7 h-7 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200"
                      title="Usuń"
                    >
                      ✕
                    </button>
                  </div>
                </div>
              ))}
              {pages.length === 0 ? (
                <div className="text-sm text-gray-500">Brak stron.</div>
              ) : null}
            </div>
          )}

          <div className="mt-4 pt-4 border-t border-gray-200">
            <h3 className="text-sm font-semibold text-gray-700 mb-2">
              Nowa strona
            </h3>
            <div className="grid grid-cols-1 gap-2">
              <input
                className="border rounded px-2 py-1 text-sm"
                placeholder="slug"
                value={newPage.slug}
                onChange={(e) =>
                  setNewPage((s) => ({ ...s, slug: e.target.value }))
                }
              />
              <input
                className="border rounded px-2 py-1 text-sm"
                placeholder="title_pl"
                value={newPage.title_pl}
                onChange={(e) =>
                  setNewPage((s) => ({ ...s, title_pl: e.target.value }))
                }
              />
              <input
                className="border rounded px-2 py-1 text-sm"
                placeholder="title_en"
                value={newPage.title_en}
                onChange={(e) =>
                  setNewPage((s) => ({ ...s, title_en: e.target.value }))
                }
              />
              <label className="text-xs text-gray-600">
                <input
                  type="checkbox"
                  checked={!!newPage.is_active}
                  onChange={(e) =>
                    setNewPage((s) => ({
                      ...s,
                      is_active: e.target.checked ? 1 : 0,
                    }))
                  }
                />{" "}
                Aktywna
              </label>
              <button
                onClick={handleCreatePage}
                className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
              >
                Utwórz stronę
              </button>
            </div>
          </div>
        </div>

        <div className="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
          <h2 className="text-lg font-bold text-gray-800 mb-2">
            Szczegóły strony
          </h2>
          {!pageDetail ? (
            <div className="text-sm text-gray-500">Wybierz stronę.</div>
          ) : (
            <div className="space-y-3">
              <div className="grid grid-cols-1 gap-2">
                <input
                  className="border rounded px-2 py-1 text-sm"
                  value={pageDetail.slug}
                  onChange={(e) =>
                    setPageDetail((s) => ({ ...s, slug: e.target.value }))
                  }
                />
                <input
                  className="border rounded px-2 py-1 text-sm"
                  value={pageDetail.title_pl || ""}
                  onChange={(e) =>
                    setPageDetail((s) => ({ ...s, title_pl: e.target.value }))
                  }
                />
                <input
                  className="border rounded px-2 py-1 text-sm"
                  value={pageDetail.title_en || ""}
                  onChange={(e) =>
                    setPageDetail((s) => ({ ...s, title_en: e.target.value }))
                  }
                />
                <label className="text-xs text-gray-600">
                  <input
                    type="checkbox"
                    checked={!!pageDetail.is_active}
                    onChange={(e) =>
                      setPageDetail((s) => ({
                        ...s,
                        is_active: e.target.checked ? 1 : 0,
                      }))
                    }
                  />{" "}
                  Aktywna
                </label>
              </div>
              <button
                onClick={handleUpdatePage}
                className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700"
              >
                Zapisz stronę
              </button>
            </div>
          )}
        </div>
      </div>

      <div className="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-4">
        <h2 className="text-lg font-bold text-gray-800 mb-2">Bloki strony</h2>
        {blocksLoading ? (
          <div className="text-sm text-gray-500">Ładowanie bloków...</div>
        ) : (
          <div className="space-y-3">
            {blocksView.map((b, idx) => (
              <div key={b.id} className="border rounded-lg p-3">
                <div className="flex items-center justify-between mb-2">
                  <div className="text-xs text-gray-500">
                    #{b.id} • {b.type}
                  </div>
                  <div className="flex gap-1">
                    <button
                      onClick={() => moveBlock(idx, -1)}
                      className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                      title="W górę"
                    >
                      ↑
                    </button>
                    <button
                      onClick={() => moveBlock(idx, 1)}
                      className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                      title="W dół"
                    >
                      ↓
                    </button>
                    <button
                      onClick={() => handleDuplicateBlock(b.id)}
                      className="w-7 h-7 text-xs bg-green-100 text-green-700 rounded hover:bg-green-200"
                      title="Duplikuj"
                    >
                      ⧉
                    </button>
                    <button
                      onClick={() => handleDeleteBlock(b.id)}
                      className="w-7 h-7 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200"
                      title="Usuń"
                    >
                      ✕
                    </button>
                  </div>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                  <div>
                    <label className="text-xs text-gray-600">content_pl</label>
                    <textarea
                      className="border rounded px-2 py-1 text-sm w-full"
                      rows={3}
                      defaultValue={b.content_pl || ""}
                      onChange={(e) => (b.content_pl = e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="text-xs text-gray-600">content_en</label>
                    <textarea
                      className="border rounded px-2 py-1 text-sm w-full"
                      rows={3}
                      defaultValue={b.content_en || ""}
                      onChange={(e) => (b.content_en = e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="text-xs text-gray-600">type</label>
                    <select
                      className="border rounded px-2 py-1 text-sm w-full"
                      defaultValue={b.type}
                      onChange={(e) => (b.type = e.target.value)}
                    >
                      {BLOCK_TYPES.map((t) => (
                        <option key={t} value={t}>
                          {t}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="text-xs text-gray-600">width</label>
                    <select
                      className="border rounded px-2 py-1 text-sm w-full"
                      defaultValue={b.width || "full"}
                      onChange={(e) => (b.width = e.target.value)}
                    >
                      {BLOCK_WIDTHS.map((w) => (
                        <option key={w} value={w}>
                          {w}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="md:col-span-2">
                    <label className="text-xs text-gray-600">
                      settings (JSON)
                    </label>
                    <textarea
                      className="border rounded px-2 py-1 text-sm w-full font-mono"
                      rows={3}
                      defaultValue={b.settings_raw}
                      onChange={(e) => (b.settings_raw = e.target.value)}
                    />
                  </div>
                  <label className="text-xs text-gray-600">
                    <input
                      type="checkbox"
                      defaultChecked={!!b.is_active}
                      onChange={(e) => (b.is_active = e.target.checked)}
                    />{" "}
                    Aktywny
                  </label>
                </div>
                <button
                  onClick={() => handleUpdateBlock(b)}
                  className="mt-2 px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                >
                  Zapisz blok
                </button>
              </div>
            ))}
            {blocksView.length === 0 ? (
              <div className="text-sm text-gray-500">Brak bloków.</div>
            ) : null}
          </div>
        )}

        <div className="mt-4 pt-4 border-t border-gray-200">
          <h3 className="text-sm font-semibold text-gray-700 mb-2">
            Nowy blok
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
            <select
              className="border rounded px-2 py-1 text-sm"
              value={newBlock.type}
              onChange={(e) =>
                setNewBlock((s) => ({ ...s, type: e.target.value }))
              }
            >
              {BLOCK_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
            <select
              className="border rounded px-2 py-1 text-sm"
              value={newBlock.width}
              onChange={(e) =>
                setNewBlock((s) => ({ ...s, width: e.target.value }))
              }
            >
              {BLOCK_WIDTHS.map((w) => (
                <option key={w} value={w}>
                  {w}
                </option>
              ))}
            </select>
            <textarea
              className="border rounded px-2 py-1 text-sm"
              rows={3}
              placeholder="content_pl"
              value={newBlock.content_pl}
              onChange={(e) =>
                setNewBlock((s) => ({ ...s, content_pl: e.target.value }))
              }
            />
            <textarea
              className="border rounded px-2 py-1 text-sm"
              rows={3}
              placeholder="content_en"
              value={newBlock.content_en}
              onChange={(e) =>
                setNewBlock((s) => ({ ...s, content_en: e.target.value }))
              }
            />
            <textarea
              className="border rounded px-2 py-1 text-sm font-mono md:col-span-2"
              rows={3}
              placeholder="settings JSON"
              value={newBlock.settings}
              onChange={(e) =>
                setNewBlock((s) => ({ ...s, settings: e.target.value }))
              }
            />
          </div>
          <button
            onClick={handleCreateBlock}
            className="mt-2 px-3 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700"
          >
            Dodaj blok
          </button>
        </div>
      </div>

      {error ? (
        <div className="mt-4 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
          {error}
        </div>
      ) : null}
    </div>
  );
}
