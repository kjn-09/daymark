import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import { defineConfig, searchForWorkspaceRoot } from 'vite'

const sharedDirectory = fileURLToPath(new URL('../shared', import.meta.url))

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/ui/',
  build: {
    outDir: '../backend/public/ui',
    emptyOutDir: true,
  },
  server: {
    fs: {
      allow: [searchForWorkspaceRoot(process.cwd()), sharedDirectory],
    },
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/csrf-token': 'http://127.0.0.1:8000',
    },
  },
})
