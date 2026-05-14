import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: {
    // If your PHP app is served at http://localhost/attendanceqr,
    // this lets the dev server call the PHP endpoints with relative paths.
    proxy: {
      "/attendanceqr": {
        target: "http://localhost",
        changeOrigin: true,
      },
    },
  },
});

