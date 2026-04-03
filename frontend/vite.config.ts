import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const apiProxyTarget = process.env.VITE_PROXY_TARGET ?? 'https://127.0.0.1:8000'
const frontendHost = process.env.VITE_HOST ?? true
const frontendPort = Number(process.env.VITE_PORT ?? '5173')

export default defineConfig({
  base: '/dist/',
  plugins: [react()],
  build: {
    outDir: '../api/public/dist',
    emptyOutDir: true,
  },
  server: {
    host: frontendHost,
    port: frontendPort,
    strictPort: true,
    proxy: {
      '/api': {
        target: apiProxyTarget,
        changeOrigin: true,
        secure: false,
      },
    },
  },
  preview: {
    host: frontendHost,
    port: frontendPort,
    strictPort: true,
  },
})
