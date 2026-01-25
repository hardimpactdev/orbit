import { defineConfig } from 'vite';
import { getPlugins, getResolveConfig, getServerConfig } from '@hardimpactdev/craft-ui/vite';

export default defineConfig(({ mode, command }) => ({
    plugins: getPlugins({}),
    resolve: getResolveConfig({}),
    server: getServerConfig(mode),
    // For production builds, set base to match where assets are served from
    base: command === 'build' ? '/vendor/orbit/build/' : '/',
    build: {
        outDir: 'public/build',
        manifest: 'manifest.json',
    },
}));
