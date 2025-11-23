const Encore = require('@symfony/webpack-encore');
const path = require('path');
const webpack = require('webpack');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // public path used by the web server to access the output path
    .setPublicPath('/build')
    // only needed for CDN's or subdirectory deploy
    //.setManifestKeyPrefix('build/')

    /*
     * ENTRY CONFIG - 三端分离架构
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addEntry('frontend', './assets/frontend.js')  // 商城前台
    .addEntry('admin', './assets/admin.js')        // 管理员后台
    .addEntry('supplier', './assets/supplier.js')  // 供应商后台
    .addStyleEntry('styles', './assets/styles/frontend.css')  // 商城样式（独立入口）
    // admin.css 已在 admin.js 和 supplier.js 中导入，无需独立入口

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()

    // 🔥 优化代码分割策略，减小单个文件体积
    .configureSplitChunks(function(splitChunks) {
        splitChunks.chunks = 'all';
        splitChunks.cacheGroups = {
            // 将 node_modules 中的依赖分离到 vendors chunk
            vendors: {
                test: /[\\/]node_modules[\\/]/,
                priority: -10,
                name: 'vendors',
                reuseExistingChunk: true,
            },
            // 将 Element Plus 单独分离（如果使用）
            elementplus: {
                test: /[\\/]node_modules[\\/]element-plus[\\/]/,
                priority: 10,
                name: 'element-plus',
                reuseExistingChunk: true,
            },
            // 将 Vue 相关库单独分离
            vue: {
                test: /[\\/]node_modules[\\/](vue|vue-router|@vue)[\\/]/,
                priority: 5,
                name: 'vue-vendor',
                reuseExistingChunk: true,
            },
            // 通用代码分离
            default: {
                minChunks: 2,
                priority: -20,
                reuseExistingChunk: true,
            },
        };
    })

    .enableVueLoader(() => {}, {
        version: 3,
        runtimeCompilerBuild: false
    })

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .enableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()

    // Displays build status system notifications to the user
    // .enableBuildNotifications()

    .enableSourceMaps(!Encore.isProduction())
    // enables hashed filenames (e.g. app.abc123.css)
    .enableVersioning(Encore.isProduction())

    // 🔥 生产环境优化：移除 console.log 并压缩代码
    .configureTerserPlugin((options) => {
        if (Encore.isProduction()) {
            options.terserOptions = {
                compress: {
                    drop_console: false, // 保留 console.log 用于调试
                },
            };
        }
    })

    // configure Babel
    // .configureBabel((config) => {
    //     config.plugins.push('@babel/a-babel-plugin');
    // })

    // enables and configure @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.38';
    })

    // enables Sass/SCSS support
    //.enableSassLoader()

    // uncomment if you use TypeScript
    //.enableTypeScriptLoader()

    .enableVueLoader(() => {}, {
        version: 3,
        runtimeCompilerBuild: false
    })
    .enablePostCssLoader()

    // uncomment if you use React
    //.enableReactPreset()

    // Add alias for stimulus-bridge controllers.json
    .addAliases({
        '@symfony/stimulus-bridge/controllers.json': path.resolve(__dirname, 'assets/controllers.json'),
        '@': path.resolve(__dirname, 'assets/vue/controllers/shop'),
    })

    // uncomment to get integrity="..." attributes on your script & link tags
    // requires WebpackEncoreBundle 1.4 or higher
    //.enableIntegrityHashes(Encore.isProduction())

    // uncomment if you're having problems with a jQuery plugin
    //.autoProvidejQuery()
;

// 获取基础配置
const config = Encore.getWebpackConfig();

// 🔥 定义 Vue feature flags
config.plugins.push(
    new webpack.DefinePlugin({
        __VUE_OPTIONS_API__: JSON.stringify(true),
        __VUE_PROD_DEVTOOLS__: JSON.stringify(false),
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false)
    })
);

// 🔥 添加性能优化配置
config.performance = {
    hints: 'warning', // 显示警告但不阻止构建
    maxEntrypointSize: 512000, // 500KB
    maxAssetSize: 512000, // 500KB
};

module.exports = config;