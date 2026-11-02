// Reproduces Flarum\Extend\Frontend::registerAssets (PHP wrapper) + core Application.bootExtensions (2.x)
const fs = require('fs'), vm = require('vm');
const path = require('path'); const dist = path.join(__dirname, '..', '..', 'js', 'dist');
const [file, label] = [process.argv[2], process.argv[3]];
const mods = new Map(); const reg = { _webpack_runtimes: {}, add() {}, get: (ns, id) => mods.get(ns + ':' + id), namespaceAndIdFromPath: (p) => ['core', p.replace(/^flarum\//, '')], onLoad() {} };
class C {} ; ['common/Component','common/components/Button','common/components/LoadingIndicator'].forEach((i)=>mods.set('core:'+i, C));
mods.set('core:common/extend', { extend() {}, override() {} });
const app = { initializers: { add() {} }, registry: { for: () => ({ registerSetting() {} }) }, forum: { attribute: () => 1 }, translator: { trans: (k) => k } };
mods.set('core:forum/app', app); mods.set('core:admin/app', app);
const flarum = { reg, extensions: {} };
const m = () => ({}); m.redraw = () => {};
const ctx = vm.createContext({ flarum, m, console, window: {}, setTimeout, Promise, Error, Object, Array, String, Math, Date, MessageChannel });
const wrapped = 'var module={};' + fs.readFileSync(path.resolve(file), 'utf8') + "\nflarum.extensions['peopleinside-fla-powcaptcha']=module.exports;";
try {
  vm.runInContext(wrapped, ctx);
  const extensions = flarum.extensions;
  Object.keys(extensions).forEach((name) => { const extension = extensions[name]; if (!extension.extend) return; });   // <- core bootExtensions
  console.log(`OK     ${label}: flarum.extensions[...] = ${JSON.stringify(extensions['peopleinside-fla-powcaptcha'])}`);
} catch (e) { console.log(`ROTTO  ${label}: ${e.constructor.name}: ${e.message}`); }
process.exit(0);
