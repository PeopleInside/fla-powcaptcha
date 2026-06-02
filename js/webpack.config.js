const config = require('flarum-webpack-config');
const { BannerPlugin } = require('webpack');

const registryShim = `
(function () {
    var g = typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this);
    var fl = g.flarum = g.flarum || {};

    if (!fl.reg) {
        var registry = {};

        fl.reg = {
            get: function (namespace, id) {
                if (namespace === 'core') {
                    if (typeof g.require === 'function') {
                        return g.require('flarum/' + id);
                    }
                    if (typeof g.requirejs === 'function') {
                        return g.requirejs('flarum/' + id);
                    }
                }

                return registry[namespace + ':' + id];
            },
            add: function (namespace, id, module) {
                registry[namespace + ':' + id] = module;
            },
            addChunk: function () {},
        };
    }
})();
`.trim();

module.exports = () => {
    const baseConfig = config();
    baseConfig.plugins = baseConfig.plugins || [];
    baseConfig.plugins.unshift(new BannerPlugin({ banner: registryShim, raw: true }));
    return baseConfig;
};
