export default function PagesList({ pages, onReload }) {
  return (
    <div className="max-w-5xl mx-auto px-4 py-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-bold text-gray-800">Strony</h2>
        <button
          onClick={onReload}
          className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-200"
        >
          Odśwież
        </button>
      </div>
      <div className="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="text-left px-4 py-2 text-xs font-semibold text-gray-600">
                Tytuł (PL)
              </th>
              <th className="text-left px-4 py-2 text-xs font-semibold text-gray-600">
                Slug
              </th>
              <th className="text-center px-4 py-2 text-xs font-semibold text-gray-600">
                Bloki
              </th>
              <th className="text-center px-4 py-2 text-xs font-semibold text-gray-600">
                Status
              </th>
            </tr>
          </thead>
          <tbody>
            {pages.map((p) => (
              <tr key={p.id} className="border-b border-gray-100">
                <td className="px-4 py-2 text-gray-800">
                  {p.title_pl || "(brak)"}
                </td>
                <td className="px-4 py-2 text-gray-500 font-mono text-xs">
                  {p.slug}
                </td>
                <td className="px-4 py-2 text-center text-gray-700">
                  {p.block_count ?? 0}
                </td>
                <td className="px-4 py-2 text-center">
                  <span
                    className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
                      p.is_active
                        ? "bg-green-100 text-green-700"
                        : "bg-gray-200 text-gray-600"
                    }`}
                  >
                    {p.is_active ? "Aktywna" : "Nieaktywna"}
                  </span>
                </td>
              </tr>
            ))}
            {pages.length === 0 ? (
              <tr>
                <td
                  colSpan={4}
                  className="px-4 py-6 text-center text-gray-500"
                >
                  Brak stron do wyświetlenia.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </div>
  );
}
