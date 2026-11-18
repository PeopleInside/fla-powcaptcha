import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState from './states/PowCaptchaState';
import behaviorSummary from './behavior';

const SIGNUP_MODAL = 'flarum/forum/components/SignUpModal';

const enabled = (key: string): boolean => !!app.forum.attribute('peopleinside-powcaptcha.' + key);

/** Runs `callback` with the modal's prototype once its (lazy-loaded) module exists. */
function whenLoaded(path: string, callback: (proto: any) => void) {
  const [namespace, id] = (flarum as any).reg.namespaceAndIdFromPath(path);
  (flarum as any).reg.onLoad(namespace, id, (Modal: any) => {
    if (Modal && Modal.prototype) callback(Modal.prototype);
  });
}

function protectModal(path: string, flag: string, paramsMethod: string, action: string) {
  // A sign-up that arrives with a social-login token (OAuth) skips the CAPTCHA.
  const skip =
    path === SIGNUP_MODAL
      ? function (this: any) { return !!this.attrs?.token; }
      : () => false;

  extend(path, 'oninit', function (this: any) {
    if (enabled(flag) && !skip.call(this)) this.powCaptchaState = new PowCaptchaState(action);
  });

  extend(path, paramsMethod, function (this: any, data: any) {
    if (!enabled(flag) || skip.call(this)) return;
    data.captchaToken = this.powCaptchaState?.getResponse() ?? '';
    data.pow_website = this.powCaptchaState?.getHoneypotValue() ?? '';
    data.pow_behavior = behaviorSummary();
  });

  extend(path, 'fields', function (this: any, items: any) {
    if (!enabled(flag) || skip.call(this) || !this.powCaptchaState) return;

    // Mithril rejects lists that mix keyed and unkeyed children: match the siblings.
    const keyed = (typeof items.toArray === 'function' ? items.toArray() : []).some(
      (v: any) => v && typeof v === 'object' && v.key !== undefined && v.key !== null
    );
    items.add('pow-captcha', keyed ? m(PowCaptchaWidget, { key: 'pow-captcha', state: this.powCaptchaState }) : m(PowCaptchaWidget, { state: this.powCaptchaState }), -5);
  });

  extend(path, 'onerror', function (this: any) {
    if (!enabled(flag)) return;
    const status = this.powCaptchaState?.getStatus();
    if (status === 'solved' || status === 'error') this.powCaptchaState?.retry();
  });

  /** Resolves to true when the form may be submitted. */
  const ready = async function (this: any, event?: Event): Promise<boolean> {
    if (!enabled(flag) || skip.call(this)) return true;
    const state: PowCaptchaState | undefined = this.powCaptchaState;
    if (!state) return true;

    if (state.isStale()) state.reset();
    if (state.getStatus() === 'idle') state.start();

    let status = state.getStatus();
    if (status === 'loading' || status === 'solving') {
      this.loading = true;
      m.redraw();
      status = await state.waitUntilSettled(180000);
    }

    if (status === 'solved' && state.getResponse()) return true;

    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
      event.stopPropagation();
    }
    this.loading = false;
    m.redraw();
    if (status === 'error' || status === 'idle') state.retry();
    app.alerts.show({ type: 'error' }, app.translator.trans('peopleinside-powcaptcha.forum.challenge_not_ready'));
    return false;
  };

  const guard = function (this: any, original: Function, event?: Event) {
    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
      event.stopPropagation();
    }
    ready.call(this, event).then((ok) => {
      if (ok) original.call(this, event);
    });
  };

  override(path, 'onsubmit', function (this: any, original: Function, event?: Event) {
    return guard.call(this, original, event);
  });

  // Flarum 2.x loads the modals lazily: wrap the prototype methods when they appear.
  whenLoaded(path, (proto) => {
    for (const method of ['onSubmit', 'submit']) {
      if (typeof proto[method] === 'function') {
        override(proto, method, function (this: any, original: Function, event?: Event) {
          return guard.call(this, original, event);
        });
      }
    }
  });
}

app.initializers.add('peopleinside-fla-powcaptcha', () => {
  protectModal('flarum/forum/components/LogInModal', 'enabledLogin', 'loginParams', 'login');
  protectModal(SIGNUP_MODAL, 'enabledSignup', 'submitData', 'signup');
  protectModal('flarum/forum/components/ForgotPasswordModal', 'enabledForgot', 'requestParams', 'forgot');
});
