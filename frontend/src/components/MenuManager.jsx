import { useEffect, useMemo, useState } from "react";
import {
  createMenuItem,
  deleteMenuItem,
  listMenu,
  reorderMenu,
  updateMenuItem,
} from "../api/menu.js";
import { listPages } from "../api/pages.js";

function flattenMenu(items, parentId = null, depth = 0) {
  const out = [];
  items.forEach((item) => {
    out.push({ ...item, parent_id: parentId, depth });
    if (item.children && item.children.length) {
      out.push(...flattenMenu(item.children, item.id, depth + 1));
    }
  });
  return out;
}

function cloneTree(tree) {
  return tree.map((n) => ({
    ...n,
    children: n.children ? cloneTree(n.children) : [],
  }));
}

function findNodeAndParent(tree, id, parent = null) {
  for (const node of tree) {
    if (node.id === id) return { node, parent };
    if (node.children?.length) {
      const found = findNodeAndParent(node.children, id, node);
      if (found) return found;
    }
  }
  return null;
}

function reorderItemsPayload(tree, items = []) {
  tree.forEach((item, idx) => {
    items.push({
      id: item.id,
      parent_id: item.parent_id || null,
      sort_order: idx,
    });
    if (item.children?.length) reorderItemsPayload(item.children, items);
  });
  return items;
}

export default function MenuManager({ token }) {
  const [menuTree, setMenuTree] = useState([]);
  const [pages, setPages] = useState([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [edit, setEdit] = useState(null);
  const [create, setCreate] = useState({
    label_pl: "",
    label_en: "",
    parent_id: "",
    page_id: "",
    url: "",
    target: "_self",
    is_active: 1,
  });

  const flat = useMemo(() => flattenMenu(menuTree), [menuTree]);

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const data = await listMenu(token);
      setMenuTree(data?.menu ?? []);
      const pagesData = await listPages(token);
      setPages(pagesData?.pages ?? []);
    } catch (err) {
      setError(err.message || "Błąd pobierania menu");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const handleCreate = async () => {
    setError("");
    try {
      const payload = {
        ...create,
        parent_id: create.parent_id ? parseInt(create.parent_id, 10) : null,
        page_id: create.page_id ? parseInt(create.page_id, 10) : null,
      };
      await createMenuItem(payload, token);
      setCreate({
        label_pl: "",
        label_en: "",
        parent_id: "",
        page_id: "",
        url: "",
        target: "_self",
        is_active: 1,
      });
      await load();
    } catch (err) {
      setError(err.message || "Błąd tworzenia menu");
    }
  };

  const handleUpdate = async () => {
    if (!edit) return;
    setError("");
    try {
      await updateMenuItem(
        {
          id: edit.id,
          label_pl: edit.label_pl,
          label_en: edit.label_en,
          url: edit.url,
          target: edit.target,
          is_active: edit.is_active ? 1 : 0,
          parent_id: edit.parent_id ? parseInt(edit.parent_id, 10) : null,
          page_id: edit.page_id ? parseInt(edit.page_id, 10) : null,
        },
        token
      );
      setEdit(null);
      await load();
    } catch (err) {
      setError(err.message || "Błąd aktualizacji menu");
    }
  };

  const handleDelete = async (id) => {
    const mode = confirm("Przenieść dzieci poziom wyżej?") ? "move_up" : "delete";
    setError("");
    try {
      await deleteMenuItem(id, mode, token);
      await load();
    } catch (err) {
      setError(err.message || "Błąd usuwania menu");
    }
  };

  const moveItem = async (id, dir) => {
    const tree = cloneTree(menuTree);
    const found = findNodeAndParent(tree, id);
    if (!found) return;
    const list = found.parent ? found.parent.children : tree;
    const idx = list.findIndex((i) => i.id === id);
    const target = idx + dir;
    if (target < 0 || target >= list.length) return;
    [list[idx], list[target]] = [list[target], list[idx]];
    setMenuTree(tree);
    try {
      const payload = reorderItemsPayload(tree);
      await reorderMenu(payload, token);
    } catch (err) {
      setError(err.message || "Błąd zmiany kolejności menu");
    }
  };

  return (
    <div className="max-w-6xl mx-auto px-4 py-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-bold text-gray-800">Menu</h2>
        <button
          onClick={load}
          className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-200"
        >
          Odśwież
        </button>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
        {loading ? (
          <div className="text-sm text-gray-500">Ładowanie...</div>
        ) : (
          <div className="space-y-2">
            {flat.map((item) => (
              <div
                key={item.id}
                className="border rounded-lg px-3 py-2 flex items-center justify-between"
              >
                <div className="flex-1">
                  <div
                    className="text-sm font-semibold text-gray-800"
                    style={{ paddingLeft: `${item.depth * 16}px` }}
                  >
                    {item.label_pl}
                  </div>
                  <div className="text-xs text-gray-500">
                    {item.page_slug || item.url || "-"}
                  </div>
                </div>
                <div className="flex gap-1">
                  <button
                    onClick={() => moveItem(item.id, -1)}
                    className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                  >
                    ↑
                  </button>
                  <button
                    onClick={() => moveItem(item.id, 1)}
                    className="w-7 h-7 text-xs bg-gray-100 rounded hover:bg-gray-200"
                  >
                    ↓
                  </button>
                  <button
                    onClick={() => setEdit({ ...item })}
                    className="w-7 h-7 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                  >
                    ✎
                  </button>
                  <button
                    onClick={() => handleDelete(item.id)}
                    className="w-7 h-7 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200"
                  >
                    ✕
                  </button>
                </div>
              </div>
            ))}
            {flat.length === 0 ? (
              <div className="text-sm text-gray-500">Brak pozycji menu.</div>
            ) : null}
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <div className="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
          <h3 className="text-sm font-semibold text-gray-700 mb-2">
            Nowa pozycja menu
          </h3>
          <div className="grid grid-cols-1 gap-2">
            <input
              className="border rounded px-2 py-1 text-sm"
              placeholder="label_pl"
              value={create.label_pl}
              onChange={(e) =>
                setCreate((s) => ({ ...s, label_pl: e.target.value }))
              }
            />
            <input
              className="border rounded px-2 py-1 text-sm"
              placeholder="label_en"
              value={create.label_en}
              onChange={(e) =>
                setCreate((s) => ({ ...s, label_en: e.target.value }))
              }
            />
            <select
              className="border rounded px-2 py-1 text-sm"
              value={create.parent_id}
              onChange={(e) =>
                setCreate((s) => ({ ...s, parent_id: e.target.value }))
              }
            >
              <option value="">(root)</option>
              {flat.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.label_pl}
                </option>
              ))}
            </select>
            <select
              className="border rounded px-2 py-1 text-sm"
              value={create.page_id}
              onChange={(e) =>
                setCreate((s) => ({ ...s, page_id: e.target.value }))
              }
            >
              <option value="">(brak strony)</option>
              {pages.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title_pl} ({p.slug})
                </option>
              ))}
            </select>
            <input
              className="border rounded px-2 py-1 text-sm"
              placeholder="URL zewnętrzny"
              value={create.url}
              onChange={(e) =>
                setCreate((s) => ({ ...s, url: e.target.value }))
              }
            />
            <select
              className="border rounded px-2 py-1 text-sm"
              value={create.target}
              onChange={(e) =>
                setCreate((s) => ({ ...s, target: e.target.value }))
              }
            >
              <option value="_self">_self</option>
              <option value="_blank">_blank</option>
            </select>
            <label className="text-xs text-gray-600">
              <input
                type="checkbox"
                checked={!!create.is_active}
                onChange={(e) =>
                  setCreate((s) => ({
                    ...s,
                    is_active: e.target.checked ? 1 : 0,
                  }))
                }
              />{" "}
              Aktywna
            </label>
            <button
              onClick={handleCreate}
              className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700"
            >
              Dodaj pozycję
            </button>
          </div>
        </div>

        <div className="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
          <h3 className="text-sm font-semibold text-gray-700 mb-2">
            Edycja pozycji
          </h3>
          {!edit ? (
            <div className="text-sm text-gray-500">Wybierz pozycję.</div>
          ) : (
            <div className="grid grid-cols-1 gap-2">
              <input
                className="border rounded px-2 py-1 text-sm"
                value={edit.label_pl || ""}
                onChange={(e) =>
                  setEdit((s) => ({ ...s, label_pl: e.target.value }))
                }
              />
              <input
                className="border rounded px-2 py-1 text-sm"
                value={edit.label_en || ""}
                onChange={(e) =>
                  setEdit((s) => ({ ...s, label_en: e.target.value }))
                }
              />
              <select
                className="border rounded px-2 py-1 text-sm"
                value={edit.parent_id || ""}
                onChange={(e) =>
                  setEdit((s) => ({ ...s, parent_id: e.target.value }))
                }
              >
                <option value="">(root)</option>
                {flat.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.label_pl}
                  </option>
                ))}
              </select>
              <select
                className="border rounded px-2 py-1 text-sm"
                value={edit.page_id || ""}
                onChange={(e) =>
                  setEdit((s) => ({ ...s, page_id: e.target.value }))
                }
              >
                <option value="">(brak strony)</option>
                {pages.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.title_pl} ({p.slug})
                  </option>
                ))}
              </select>
              <input
                className="border rounded px-2 py-1 text-sm"
                value={edit.url || ""}
                onChange={(e) => setEdit((s) => ({ ...s, url: e.target.value }))}
              />
              <select
                className="border rounded px-2 py-1 text-sm"
                value={edit.target || "_self"}
                onChange={(e) =>
                  setEdit((s) => ({ ...s, target: e.target.value }))
                }
              >
                <option value="_self">_self</option>
                <option value="_blank">_blank</option>
              </select>
              <label className="text-xs text-gray-600">
                <input
                  type="checkbox"
                  checked={!!edit.is_active}
                  onChange={(e) =>
                    setEdit((s) => ({ ...s, is_active: e.target.checked }))
                  }
                />{" "}
                Aktywna
              </label>
              <div className="flex gap-2">
                <button
                  onClick={handleUpdate}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                >
                  Zapisz
                </button>
                <button
                  onClick={() => setEdit(null)}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300"
                >
                  Anuluj
                </button>
              </div>
            </div>
          )}
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
