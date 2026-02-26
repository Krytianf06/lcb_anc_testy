import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  base: "/anc/",
  plugins: [react()],
  build: {
    outDir: "../anc",
    emptyOutDir: false
  }
});
