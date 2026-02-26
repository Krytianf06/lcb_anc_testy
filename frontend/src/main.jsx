import React from "react";
import { createRoot } from "react-dom/client";
import App from "./App.jsx";
import "./index.css";

const appEl = document.getElementById("app");
if (appEl) {
  createRoot(appEl).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
} else {
  // eslint-disable-next-line no-console
  console.warn("React mount point #app not found.");
}
