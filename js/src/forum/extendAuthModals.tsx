import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState, { PowAction } from './states/PowCaptchaState';

declare const m: any;
declare const flarum: any;

/** Longest we keep the form waiting for the PoW to finish (ms). */
const SUBMIT_WAIT_MS = 180_000;

/**
 * IMPORTANT: on Flarum 2.x the auth modals are LAZY-LOADED chunks (loaded on demand).
 * They do not exist yet when this extension initialises, so they must be referenced by
 * module PATH, never imported as classes: `extend()` / `override()` accept a path string and
 * apply the change as soon as the modal is loaded. Importing the class here would make the
 * whole forum JavaScript crash at boot.
 */
const LOGIN_MODAL = 'flarum/forum/components/LogInModal';
const SIGNUP_MODAL = 'flarum/forum/components/SignUpModal';
const FORGOT_MODAL = 'flarum/forum/components/ForgotPasswordModal';

/**
 * Whether a given form type has CAPTCHA enabled (setting serialised to the
 * forum JSON:API resource by extend.php).
 */
function isEnabled(key: string): boolean {
    return !!app.forum.attribute<boolean>('peopleinside-powcaptcha.' + key);
}

/** Runs `callback` with the modal's prototype once the (lazy) module is available. */
function whenLoaded(path: string, callback: (prototype: any) => void): void {
    const [namespace, id] = flarum.reg.namespaceAndIdFromPath(path);

    flarum.reg.onLoad(namespace, id, (module: any) => {
        if (module && module.prototype) callback(module.prototype);
    });
}

/**
 * Extend an auth modal with the PoW CAPTCHA widget.
 */
function applyToModal(path: string, enabledKey: string, dataMethod: string, action: PowAction): void {
    const skipCaptcha =
        path === SIGNUP_MODAL
            ? function (this: any) {
                  return !!this.attrs?.token;
              }
            : () => false;

    extend(path, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        this.powCaptchaState = new PowCaptchaState(action);
    });

    extend(path, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
        data['pow_website'] = this.powCaptchaState?.getHoneypotValue() ?? '';
    });

    extend(path, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        if (!this.powCaptchaState) return;

        // Mithril throws "In fragments, vnodes must either all have keys or none have keys" when a
        // list mixes keyed and unkeyed children, which leaves the whole modal body empty. So the
        // widget follows its siblings: it gets a key only if the other fields already have one
        // (core fields have none, but another extension may add keyed ones).
        const siblings: unknown[] = typeof items.toArray === 'function' ? items.toArray() : [];
        const siblingsAreKeyed = siblings.some((v: any) => v && typeof v === 'object' && v.key !== undefined && v.key !== null);

        items.add(
            'pow-captcha',
            siblingsAreKeyed ? (
                <PowCaptchaWidget key="pow-captcha" state={this.powCaptchaState} />
            ) : (
                <PowCaptchaWidget state={this.powCaptchaState} />
            ),
            -5
        );
    });

    // Not every modal has an `onerror` (the core sign-up modal does not): extend() copes.
    extend(path, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        // A challenge is single-use: after any server-side error, get a fresh one.
        const status = this.powCaptchaState?.getStatus();
        if (status === 'solved' || status === 'error') {
            this.powCaptchaState?.retry();
        }
    });

    const checkAndBlock = async function (this: any, e?: Event) {
        if (!isEnabled(enabledKey)) return true;
        if (skipCaptcha.call(this)) return true;

        const state: PowCaptchaState | undefined = this.powCaptchaState;
        if (!state) return true;

        // A solved token is only valid for a few minutes: renew it transparently.
        if (state.isStale()) state.reset();
        if (state.getStatus() === 'idle') state.start();

        let status = state.getStatus();

        // Still working: keep the form in its loading state and wait for the result.
        if (status === 'loading' || status === 'solving') {
            this.loading = true;
            m.redraw();
            status = await state.waitUntilSettled(SUBMIT_WAIT_MS);
        }

        if (status !== 'solved' || !state.getResponse()) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
                e.stopPropagation();
            }
            this.loading = false;
            m.redraw();

            if (status === 'error' || status === 'idle') {
                state.retry();
            }

            app.alerts.show(
                { type: 'error' },
                app.translator.trans('peopleinside-powcaptcha.forum.challenge_not_ready') as string
            );
            return false;
        }
        return true;
    };

    const wrapSubmit = function (this: any, original: any, e: Event) {
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
            e.stopPropagation();
        }

        checkAndBlock.call(this, e).then((canSubmit) => {
            if (canSubmit) {
                original.call(this, e);
            }
        });
    };

    override(path, 'onsubmit', function (this: any, original: any, e: Event) {
        return wrapSubmit.call(this, original, e);
    });

    // Other submit entry points exist only in some Flarum versions: wrap them only when present.
    whenLoaded(path, (prototype) => {
        for (const name of ['onSubmit', 'submit']) {
            if (typeof prototype[name] === 'function') {
                override(prototype, name, function (this: any, original: any, e: Event) {
                    return wrapSubmit.call(this, original, e);
                });
            }
        }
    });
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(LOGIN_MODAL, 'enabledLogin', 'loginParams', 'login');
    applyToModal(SIGNUP_MODAL, 'enabledSignup', 'submitData', 'signup');
    applyToModal(FORGOT_MODAL, 'enabledForgot', 'requestParams', 'forgot');
}
