import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LogInModal from 'flarum/forum/components/LogInModal';
import SignUpModal from 'flarum/forum/components/SignUpModal';
import ForgotPasswordModal from 'flarum/forum/components/ForgotPasswordModal';
import PowCaptchaWidget from './components/PowCaptchaWidget';
import PowCaptchaState from './states/PowCaptchaState';

/**
 * Whether a given form type has CAPTCHA enabled (setting serialised to the
 * forum JSON:API resource by extend.php).
 */
function isEnabled(key: string): boolean {
    return !!app.forum.attribute<boolean>('peopleinside-powcaptcha.' + key);
}

/**
 * Extend a modal with the PoW CAPTCHA widget.
 *
 * @param modulePath  Lazy-module path of the Flarum modal component.
 * @param enabledKey  Forum-attribute key that controls whether CAPTCHA is active.
 * @param dataMethod  Name of the method that builds the POST body.
 */
function applyToModal(modalPrototype: any, enabledKey: string, dataMethod: string): void {
    // Create the state object when the modal is initialised.
    extend(modalPrototype, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState = new PowCaptchaState();
    });

    // Inject the token into the request payload.
    extend(modalPrototype, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
    });

    // Add the widget to the form's field list (just above the submit button).
    extend(modalPrototype, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (!this.powCaptchaState) return;

        items.add(
            'pow-captcha',
            <PowCaptchaWidget state={this.powCaptchaState} />,
            -5
        );
    });

    // Restart the widget when a submission error occurs.
    extend(modalPrototype, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState?.retry();
    });
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(
        LogInModal.prototype,
        'enabledLogin',
        'loginParams'
    );

    applyToModal(
        SignUpModal.prototype,
        'enabledSignup',
        'submitData'
    );

    applyToModal(
        ForgotPasswordModal.prototype,
        'enabledForgot',
        'requestParams'
    );
}
