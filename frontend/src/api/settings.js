import { apiRequest } from "./client.js";

export async function listSettings(token) {
  return apiRequest("/settings.php?action=list", {
    method: "GET",
    token,
  });
}

export async function updateSetting(payload, token) {
  return apiRequest("/settings.php?action=update", {
    method: "POST",
    token,
    body: payload,
  });
}

export async function bulkUpdateSettings(settings, token) {
  return apiRequest("/settings.php?action=bulk_update", {
    method: "POST",
    token,
    body: { settings },
  });
}
