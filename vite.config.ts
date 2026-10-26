import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// WordPress loads build/index.js through a classic <script> tag, so the bundle
// is emitted as a single IIFE with stable file names (cache-busted by filemtime
// in PHP) instead of hashed ES modules.
export default defineConfig(({ mode }) => ({
  plugins: [react()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production'),
  },
  build: {
    outDir: 'build',
    emptyOutDir: true,
    sourcemap: mode === 'development',
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'index.js',
        assetFileNames: 'index[extname]',
      },
    },
  },
}));
