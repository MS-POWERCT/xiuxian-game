import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // 共享数值配置：仓库根目录 config/（前后端共用同一份数值）
      '@config': fileURLToPath(new URL('../config', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    // 允许读取项目外的 config/ 目录
    fs: { allow: ['..'] },
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
    },
  },
})