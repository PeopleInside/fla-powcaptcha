const webpack = require('webpack');
const flarumConfig = require('flarum-webpack-config');

module.exports = function (env, args) {
    const config = typeof flarumConfig === 'function' ? flarumConfig(env, args) : flarumConfig;

    // Flarum wraps every extension bundle like this (see Flarum\Extend\Frontend):
    //
    //     var module={};  <bundle>  flarum.extensions['vendor-name']=module.exports;
    //
    // so the bundle MUST leave a value in `module.exports`. If it does not, Flarum registers
    // the extension as `undefined` and the whole forum crashes at boot with
    // "Cannot read properties of undefined (reading 'extend')" in Application.bootExtensions().
    if (config && config.output) {
        delete config.output.library;
        delete config.output.libraryTarget;
    }

    config.plugins = [
        ...(config.plugins || []),
        new webpack.BannerPlugin({
            banner: 'if(typeof module==="object"&&module){module.exports=module.exports||{};}',
            raw: true,
            footer: true,
        }),
    ];

    return config;
};
