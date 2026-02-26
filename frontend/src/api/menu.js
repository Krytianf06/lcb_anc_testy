import { apiRequest } from "./client.js";

export async function listMenu(token) {
  return apiRequest("/menu.php?action=list", {
    method: "GET",
    token,
  });
}

export async function createMenuItem(payload, token) {
  return apiRequest("/menu.php?action=create", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function updateMenuItem(payload, token) {
  return apiRequest("/menu.php?action=update", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function deleteMenuItem(id, children_mode, token) {
  return apiRequest("/menu.php?action=delete", {
    method: "POST",
    token,
    body: { id, children_mode },
  });
}

export async function reorderMenu(items, token) {
  return apiRequest("/menu.php?action=reorder", {
    method: "POST",
    token,
    body: { items },
  });
}
