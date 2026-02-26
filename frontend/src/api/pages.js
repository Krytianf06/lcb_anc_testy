import { apiRequest } from "./client.js";

export async function listPages(token) {
  return apiRequest("/pages.php?action=list", {
    method: "GET",
    token,
  });
}

export async function getPage({ id, slug }, token) {
  const qs = id ? `id=${id}` : `slug=${encodeURIComponent(slug || "")}`;
  return apiRequest(`/pages.php?action=get&${qs}`, {
    method: "GET",
    token,
  });
}

export async function createPage(payload, token) {
  return apiRequest("/pages.php?action=create", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function updatePage(payload, token) {
  return apiRequest("/pages.php?action=update", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function deletePage(id, token) {
  return apiRequest("/pages.php?action=delete", {
    method: "POST",
    token,
    body: { id },
  });
}

export async function reorderPages(order, token) {
  return apiRequest("/pages.php?action=reorder", {
    method: "POST",
    token,
    body: { order },
  });
}
