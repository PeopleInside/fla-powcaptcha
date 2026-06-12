import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import LogInModal from 'flarum/forum/components/LogInModal';
import SignUpModal from 'flarum/forum/components/SignUpModal';
import ForgotPasswordModal from 'flarum/forum/components/ForgotPasswordModal';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState from './states/PowCaptchaState';

type AuthModal = typeof LogInModal | typeof SignUpModal | typeof ForgotPasswordModal;
type ExtendTarget = object | string;

/**
 * Flarum 2.x turns `flarum/*` imports into lazy registry paths (strings).
 * Flarum 1.8 resolves them to class constructors and extend() needs `.prototype`.
 */
function resolveExtendTarget(modal: AuthModal | string): ExtendTarget {
    if (typeof modal === 'string') {
        return modal;
    }

    return modal.prototype;
}

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
 */
function applyToModal(
    modal: AuthModal | string,
    enabledKey: string,
    dataMethod: string,
    isSignUp: boolean
): void {
    const target = resolveExtendTarget(modal);
    const skipCaptcha = isSignUp
        ? function (this: any) {
              return !!this.attrs?.token;
          }
        : () => false;

    extend(target, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        this.powCaptchaState = new PowCaptchaState();
    });

    extend(target, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
    });

    extend(target, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (skipCaptcha.call(this)) return;
        if (!this.powCaptchaState) return;

        items.add(
            'pow-captcha',
            <PowCaptchaWidget state={this.powCaptchaState} />,
            -5
        );
    });

    extend(target, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState?.retry();
    });

    override(target, 'onsubmit', function (this: any, original: (e: SubmitEvent) => void, e: SubmitEvent) {
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
    applyToModal(LogInModal, 'enabledLogin', 'loginParams', false);
    applyToModal(SignUpModal, 'enabledSignup', 'submitData', true);
    applyToModal(ForgotPasswordModal, 'enabledForgot', 'requestParams', false);
}
