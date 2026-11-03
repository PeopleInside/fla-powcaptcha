const webpack = require('webpack');
const flarumConfig = require('flarum-webpack-config');

// Build id (shown in the browser console, see README "Troubleshooting"). The release version
// itself stays 3.8.0; this only tells two builds of it apart.
const BUILD = '3.8.0+' + new Date().toISOString().replace(/[-:T]/g, '').slice(0, 12);

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
            banner: 'if(typeof module==="object"&&module){module.exports=module.exports||{};module.exports.build="' + BUILD + '";}',
            raw: true,
            footer: true,
        }),
    ];

    return config;
};
