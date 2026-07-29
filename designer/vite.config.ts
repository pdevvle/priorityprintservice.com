import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// base './' so the built app works from any path — GitHub Pages serves it from
// /priorityprintservice.com/designer/dist/, a Tauri build serves it from the
// bundle root, and `npm run preview` serves it from '/'. Same artifact.
export default defineConfig({
  base: './',
  plugins: [react()],
  build: { outDir: 'dist', emptyOutDir: true, target: 'es2022' },
});
