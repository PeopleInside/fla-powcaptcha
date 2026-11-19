const config = require('flarum-webpack-config')();
const webpack = require('webpack');

// Flarum compiles each extension as `var module = {}; <bundle>; flarum.extensions[id] = module.exports;`
// and then calls `extension.extend` on every registered extension while booting.
// If the bundle never sets `module.exports`, that value is `undefined` and the whole
// forum dies with "Cannot read properties of undefined (reading 'extend')".
// Keep this footer: it guarantees `module.exports` is always an object.
config.plugins = [
  ...(config.plugins || []),
  new webpack.BannerPlugin({
    raw: true,
    footer: true,
    banner: '"object"==typeof module&&module&&(module.exports=module.exports||{});',
  }),
];

module.exports = config;
