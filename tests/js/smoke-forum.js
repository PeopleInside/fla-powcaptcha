const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(require('path').join(__dirname, '..', '..', 'js', 'dist', 'forum.js') + '', 'utf8');
const log = []; const warn = (...a) => log.push('WARN ' + a.join(' '));

// ---- Fake Flarum 2.x runtime: lazy modules (onLoad), reg.get warns on unknown modules ----
const mods = new Map(), pending = [];
const reg = { _webpack_runtimes: {},
  add(ns, id, o) { mods.set(ns + ':' + id, o); },
  get(ns, id) { const m = mods.get(ns + ':' + id); if (!m) warn('No module found for ' + ns + ':' + id); return m; },
  namespaceAndIdFromPath(p) { return ['core', p.replace(/^flarum\//, '')]; },
  onLoad(ns, id, h) { pending.push({ id, h }); },
};
class Component { oninit() {} oncreate() {} onremove() {} }
const initializers = [];
const app = { initializers: { add: (n, f) => initializers.push(f) },
  forum: { attribute: (k) => (k === 'apiUrl' ? 'https://x.test/api' : true) },
  translator: { trans: (k) => k }, alerts: { show: (t, m) => log.push('ALERT ' + m) } };
const extendFns = {
  extend(object, methods, cb) {
    if (typeof object === 'string') { const [ns, id] = reg.namespaceAndIdFromPath(object); return reg.onLoad(ns, id, (mod) => extendFns.extend(mod.prototype, methods, cb)); }
    [].concat(methods).forEach((m) => { const orig = object[m]; object[m] = function (...a) { const v = orig ? orig.apply(this, a) : undefined; cb.apply(this, [v, ...a]); return v; }; });
  },
  override(object, methods, nm) {
    if (typeof object === 'string') { const [ns, id] = reg.namespaceAndIdFromPath(object); return reg.onLoad(ns, id, (mod) => extendFns.override(mod.prototype, methods, nm)); }
    [].concat(methods).forEach((m) => { const orig = object[m]; object[m] = function (...a) { return nm.apply(this, [orig && orig.bind(this), ...a]); }; });
  },
};
mods.set('core:common/Component', Component);
mods.set('core:common/components/Button', () => ({}));
mods.set('core:common/components/LoadingIndicator', () => ({}));
mods.set('core:common/extend', extendFns);
mods.set('core:forum/app', app);

const m = (tag, attrs, ...children) => ({ tag, attrs, children }); m.redraw = () => {}; m.fragment = (a, c) => c;
const fetchStub = async () => ({ ok: true, status: 200, headers: { get: () => null },
  json: async () => ({ challenge: 'v1.0123456789abcdef.1793536081.3.l.0.1e9efe6190457d83609610b4576711a3', difficulty: 3 }) });
const ctx = vm.createContext({ flarum: { reg, extensions: {}, debug: false }, m, fetch: fetchStub, console: { warn, log: () => {} },
  window: {}, self: {}, setTimeout, clearTimeout, Date, Math, Number, Error, Promise, MessageChannel, parseInt, encodeURIComponent, Array, Object, String, Symbol, TypeError, RangeError, Uint8Array, Uint32Array, ArrayBuffer, Reflect, JSON });
ctx.window = ctx;

let step = '';
(async () => {
  try {
    step = 'carico forum.js'; vm.runInContext(code, ctx);
    step = 'eseguo gli initializer'; initializers.forEach((f) => f());
    console.log(`1) bundle caricato e initializer eseguiti senza errori. Aggancio per percorso in attesa dei modali lazy: ${pending.length} hook`);
    console.log('   moduli core non trovati:', log.filter((l) => l.startsWith('WARN')).length ? log.filter((l) => l.startsWith('WARN')) : 'nessuno');

    // Simulate the lazy chunks loading afterwards (as Flarum does when the user opens a modal)
    const make = (methods, dataMethod) => { const C = class { constructor() { this.attrs = {}; this.loading = false; } oninit() {} fields() { return { add(k, v, p) { this.k = k; this.v = v; this.p = p; } }; } onsubmit() { this.submitted = true; this.sent = this[dataMethod](); } };
      C.prototype[dataMethod] = function () { return {}; }; methods.forEach((x) => (C.prototype[x] = function () {})); return C; };
    const Login = make(['onerror'], 'loginParams'), SignUp = make([], 'submitData'), Forgot = make(['onerror'], 'requestParams');
    const byId = { 'forum/components/LogInModal': Login, 'forum/components/SignUpModal': SignUp, 'forum/components/ForgotPasswordModal': Forgot };
    step = 'caricamento lazy dei modali'; pending.forEach(({ id, h }) => h(byId[id]));
    console.log('2) modali "caricati" e estesi senza errori (SignUp senza onerror incluso)');

    step = 'apertura modale login'; const inst = new Login(); inst.oninit();
    console.log('3) oninit -> stato captcha creato:', !!inst.powCaptchaState, '| azione:', inst.powCaptchaState.action);
    const items = inst.fields();
    console.log('4) fields -> widget aggiunto:', items.k, '(priorita', items.p + ')');

    step = 'invio del form'; const evt = { preventDefault() {}, stopPropagation() {} }; inst.onsubmit(evt);
    await new Promise((r) => setTimeout(r, 1500));
    const sent = inst.sent || {};
    console.log('5) invio -> il form attende la risoluzione e poi parte:', inst.submitted === true);
    console.log('   captchaToken inviato:', typeof sent.captchaToken === 'string' && sent.captchaToken.startsWith('v1.') ? 'si (' + sent.captchaToken.slice(0, 28) + '...)' : 'NO', '| pow_website:', JSON.stringify(sent.pow_website));

    // OAuth sign-up: no captcha at all
    const s2 = new SignUp(); s2.attrs = { token: 'abc' }; s2.oninit(); console.log('6) registrazione via OAuth -> captcha saltato:', s2.powCaptchaState === undefined);
    console.log('\nESITO: OK'); process.exit(0);
  } catch (e) { console.log('ERRORE durante "' + step + '":', String(e && e.message || e).slice(0, 300)); process.exit(1); }
})();
