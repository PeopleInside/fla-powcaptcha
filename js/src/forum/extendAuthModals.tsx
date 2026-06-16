import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import LogInModal from 'flarum/forum/components/LogInModal';
import SignUpModal from 'flarum/forum/components/SignUpModal';
import ForgotPasswordModal from 'flarum/forum/components/ForgotPasswordModal';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState from './states/PowCaptchaState';

declare const m: any;

type AuthModal = typeof LogInModal | typeof SignUpModal | typeof ForgotPasswordModal;

/**
 * Whether a given form type has CAPTCHA enabled (setting serialised to the
 * forum JSON:API resource by extend.php).
 */
function isEnabled(key: string): boolean {
    return !!app.forum.attribute<boolean>('peopleinside-powcaptcha.' + key);
}

/**
 * Extend an auth modal with the PoW CAPTCHA widget.
 *
 * Uses concrete modal classes so the same bundle works on Flarum 1.8 (extend
 * requires a prototype) and Flarum 2.x (lazy registry paths are optional).
 */
function applyToModal(modal: AuthModal, enabledKey: string, dataMethod: string): void {
    const prototype = modal.prototype;
    const skipCaptcha = modal === SignUpModal
        ? function (this: any) {
              return !!this.attrs?.token;
          }
        : () => false;

    extend(prototype, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        this.powCaptchaState = new PowCaptchaState();
    });

    extend(prototype, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
    });

    extend(prototype, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        if (!this.powCaptchaState) return;

        items.add(
            'pow-captcha',
            <PowCaptchaWidget state={this.powCaptchaState} key={this.powCaptchaState.id} />,
            -5
         );
     });

    extend(prototype, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        const status = this.powCaptchaState?.getStatus();
        if (status === 'solved' || status === 'error') {
            this.powCaptchaState?.retry();
        }
    });

    const checkAndBlock = function (this: any, e?: Event) {
        if (!isEnabled(enabledKey)) return true;
        if (skipCaptcha.call(this)) return true;

        const status = this.powCaptchaState?.getStatus();
        if (status !== 'solved') {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
                e.stopPropagation();
            }
            this.loading = false;
            m.redraw();

            // Show a top-level alert preventing submission before challenge is resolved
            app.alerts.show(
                { type: 'error' },
                app.translator.trans('peopleinside-powcaptcha.forum.challenge_not_ready') as string
            );
            return false;
        }
        return true;
    };

    override(prototype, 'onsubmit', function (this: any, original: any, e: Event) {
        if (!checkAndBlock.call(this, e)) return;
        return original.call(this, e);
    });

    if (typeof prototype.onSubmit === 'function') {
        override(prototype, 'onSubmit', function (this: any, original: any, e: Event) {
            if (!checkAndBlock.call(this, e)) return;
            return original.call(this, e);
        });
    }

    if (typeof prototype.submit === 'function') {
        override(prototype, 'submit', function (this: any, original: any, e: Event) {
            if (!checkAndBlock.call(this, e)) return;
            return original.call(this, e);
        });
    }
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(LogInModal, 'enabledLogin', 'loginParams');
    applyToModal(SignUpModal, 'enabledSignup', 'submitData');
    applyToModal(ForgotPasswordModal, 'enabledForgot', 'requestParams');
}
