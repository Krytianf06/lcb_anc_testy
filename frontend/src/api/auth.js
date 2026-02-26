import { apiRequest } from "./client.js";

export async function login(username, password) {
  return apiRequest("/auth.php?action=login", {
    method: "POST",
    body: { username, password },
  });
}

export async function logout(token) {
  return apiRequest("/auth.php?action=logout", {
    method: "POST",
    token,
  });
}
