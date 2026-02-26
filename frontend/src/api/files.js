const API_BASE = "/anc/api";

export async function uploadFile(file, token) {
  const form = new FormData();
  form.append("file", file);

  const res = await fetch(`${API_BASE}/file-upload.php?action=upload`, {
    method: "POST",
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
    body: form,
  });
  const data = await res.json();
  if (!res.ok || data?.success === false) {
    throw new Error(data?.message || "Upload failed");
  }
  return data?.data;
}

export async function listFiles(token) {
  const res = await fetch(`${API_BASE}/file-upload.php?action=list`, {
    method: "GET",
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  });
  const data = await res.json();
  if (!res.ok || data?.success === false) {
    throw new Error(data?.message || "List files failed");
  }
  return data?.data;
}

export async function deleteFile(id, token) {
  const res = await fetch(`${API_BASE}/file-upload.php?action=delete`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ id }),
  });
  const data = await res.json();
  if (!res.ok || data?.success === false) {
    throw new Error(data?.message || "Delete file failed");
  }
  return data?.data;
}
