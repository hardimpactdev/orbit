import { defineConfig } from 'vite';

export default defineConfig({
    root: 'frontend',
    build: {
        emptyOutDir: true,
        outDir: 'dist',
    },
    server: {
        host: '127.0.0.1',
        port: 5174,
        strictPort: true,
    },
});
