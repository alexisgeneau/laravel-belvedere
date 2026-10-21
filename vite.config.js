import vue from '@vitejs/plugin-vue';

/** @type {import('vite').UserConfig} */
export default {
    plugins: [vue()],
    build: {
        outDir: 'resources/dist',
        emptyOutDir: true,
        assetsDir: '',
        chunkSizeWarningLimit: 1200,
        rollupOptions: {
            input: ['resources/js/app.js', 'resources/sass/styles.scss', 'resources/sass/styles-dark.scss'],
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
    css: {
        preprocessorOptions: {
            scss: {
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
};
