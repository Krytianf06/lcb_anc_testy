const API_BASE = "/anc/api";

export async function uploadImage(file, altText, token) {
  const form = new FormData();
  form.append("image", file);
  if (altText) form.append("alt_text", altText);

  const res = await fetch(`${API_BASE}/upload.php?action=upload`, {
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

export async function listImages(token) {
  const res = await fetch(`${API_BASE}/upload.php?action=list`, {
    method: "GET",
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  });
  const data = await res.json();
  if (!res.ok || data?.success === false) {
    throw new Error(data?.message || "List images failed");
  }
  return data?.data;
}

export async function deleteImage(id, force, token) {
  const res = await fetch(`${API_BASE}/upload.php?action=delete`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ id, force: !!force }),
  });
  const data = await res.json();
  if (!res.ok || data?.success === false) {
    throw new Error(data?.message || "Delete image failed");
  }
  return data?.data;
}
