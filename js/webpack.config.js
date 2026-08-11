const flarumConfig = require('flarum-webpack-config');

module.exports = function (env, args) {
    const config = typeof flarumConfig === 'function' ? flarumConfig(env, args) : flarumConfig;

    // Prevent 'Uncaught ReferenceError: module is not defined' in browser
    if (config && config.output) {
        delete config.output.library;
        delete config.output.libraryTarget;
    }

    return config;
};

