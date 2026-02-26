const API_BASE = "/anc/api";

async function parseJson(response) {
  const text = await response.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return { success: false, message: "Invalid JSON response", data: text };
  }
}

export async function apiRequest(path, { method = "GET", body, token } = {}) {
  const headers = {
    "Content-Type": "application/json",
  };
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const payload = await parseJson(res);
  if (!res.ok || (payload && payload.success === false)) {
    const message =
      payload?.message ||
      `Request failed (${res.status} ${res.statusText})`;
    const error = new Error(message);
    error.payload = payload;
    error.status = res.status;
    throw error;
  }

  return payload?.data ?? null;
}
