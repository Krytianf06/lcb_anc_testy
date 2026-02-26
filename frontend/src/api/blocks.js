import { apiRequest } from "./client.js";

export async function listBlocks(pageId, token) {
  return apiRequest(`/blocks.php?action=list&page_id=${pageId}`, {
    method: "GET",
    token,
  });
}

export async function createBlock(payload, token) {
  return apiRequest("/blocks.php?action=create", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function updateBlock(payload, token) {
  return apiRequest("/blocks.php?action=update", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function deleteBlock(id, token) {
  return apiRequest("/blocks.php?action=delete", {
    method: "POST",
    token,
    body: { id },
  });
}

export async function reorderBlocks(pageId, order, token) {
  return apiRequest("/blocks.php?action=reorder", {
    method: "POST",
    token,
    body: { page_id: pageId, order },
  });
}

export async function duplicateBlock(id, token) {
  return apiRequest("/blocks.php?action=duplicate", {
    method: "POST",
    token,
    body: { id },
  });
}
