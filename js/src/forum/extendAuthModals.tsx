import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
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
function applyToModal(modalPath: string, enabledKey: string, dataMethod: string): void {
    // Create the state object when the modal is initialised.
    extend(modalPath, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState = new PowCaptchaState();
    });

    // Inject the token into the request payload.
    extend(modalPath, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
    });

    // Add the widget to the form's field list (just above the submit button).
    extend(modalPath, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (!this.powCaptchaState) return;

        items.add(
            'pow-captcha',
            <PowCaptchaWidget state={this.powCaptchaState} />,
            -5
        );
    });

    // Restart the widget when a submission error occurs.
    extend(modalPath, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState?.retry();
    });
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(
        'flarum/forum/components/LogInModal',
        'enabledLogin',
        'loginParams'
    );

    applyToModal(
        'flarum/forum/components/SignUpModal',
        'enabledSignup',
        'submitData'
    );

    applyToModal(
        'flarum/forum/components/ForgotPasswordModal',
        'enabledForgot',
        'requestParams'
    );
}
