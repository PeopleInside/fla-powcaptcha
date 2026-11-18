const config = require('flarum-webpack-config')();
const webpack = require('webpack');
const { version } = require('./package.json');

// Source maps: emit js/dist/*.js.map next to the bundles so the original TypeScript
// can be recovered from the shipped files (commit the .map files with the dist).
config.devtool = 'source-map';

// Flarum registers the extension from `module.exports`; keep it set and expose the
// build id (flarum.extensions['peopleinside-fla-powcaptcha'].build).
config.plugins = [
  ...(config.plugins || []),
  new webpack.BannerPlugin({
    raw: true,
    footer: true,
    banner: `"object"==typeof module&&module&&(module.exports=module.exports||{},module.exports.build=${JSON.stringify(version)});`,
  }),
];

module.exports = config;
