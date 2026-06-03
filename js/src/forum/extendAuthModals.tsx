import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import LogInModal from 'flarum/forum/components/LogInModal';
import SignUpModal from 'flarum/forum/components/SignUpModal';
import ForgotPasswordModal from 'flarum/forum/components/ForgotPasswordModal';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState from './states/PowCaptchaState';

type AuthModal = typeof LogInModal | typeof SignUpModal | typeof ForgotPasswordModal;

/**
 * Whether a given form type has CAPTCHA enabled (setting serialised to the
 * forum JSON:API resource by extend.php).
 */
function isEnabled(key: string): boolean {
    return !!app.forum.attribute<boolean>('peopleinside-powcaptcha.' + key);
}

function captchaNotSolved(state: PowCaptchaState | undefined): boolean {
    return !!state && state.getStatus() !== 'solved';
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
            <PowCaptchaWidget state={this.powCaptchaState} />,
            -5
        );
    });

    extend(prototype, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState?.retry();
    });

    override(prototype, 'onsubmit', function (this: any, original: (e: SubmitEvent) => void, e: SubmitEvent) {
        if (skipCaptcha.call(this)) {
            return original.call(this, e);
        }

        if (isEnabled(enabledKey) && captchaNotSolved(this.powCaptchaState)) {
            e.preventDefault();
            void this.powCaptchaState?.start();
            return;
        }

        return original.call(this, e);
    });
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(LogInModal, 'enabledLogin', 'loginParams');
    applyToModal(SignUpModal, 'enabledSignup', 'submitData');
    applyToModal(ForgotPasswordModal, 'enabledForgot', 'requestParams');
}
