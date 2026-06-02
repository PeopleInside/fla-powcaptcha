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
 * @param modalClass  Flarum modal class to extend.
 * @param enabledKey  Forum-attribute key that controls whether CAPTCHA is active.
 * @param dataMethod  Name of the method that builds the POST body.
 */
type ModalPrototype = {
    oninit?: (...args: any[]) => void;
    fields?: (...args: any[]) => void;
    onerror?: (...args: any[]) => void;
    [key: string]: any;
};

type ModalClass = { prototype: ModalPrototype };

function applyToModal(modalClass: ModalClass, enabledKey: string, dataMethod: string): void {
    const prototype = modalClass.prototype;

    // Create the state object when the modal is initialised.
    extend(prototype, 'oninit', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState = new PowCaptchaState();
    });

    // Inject the token into the request payload.
    extend(prototype, dataMethod, function (this: any, data: Record<string, unknown>) {
        if (!isEnabled(enabledKey)) return;
        data['captchaToken'] = this.powCaptchaState?.getResponse() ?? '';
    });

    // Add the widget to the form's field list (just above the submit button).
    extend(prototype, 'fields', function (this: any, items: any) {
        if (!isEnabled(enabledKey)) return;
        if (!this.powCaptchaState) return;

        items.add(
            'pow-captcha',
            <PowCaptchaWidget state={this.powCaptchaState} />,
            -5
        );
    });

    // Restart the widget when a submission error occurs.
    extend(prototype, 'onerror', function (this: any) {
        if (!isEnabled(enabledKey)) return;
        this.powCaptchaState?.retry();
    });
}

/**
 * Wire up the PoW CAPTCHA to all three auth modals.
 */
export default function extendAuthModals(): void {
    applyToModal(
        LogInModal,
        'enabledLogin',
        'loginParams'
    );

    applyToModal(
        SignUpModal,
        'enabledSignup',
        'submitData'
    );

    applyToModal(
        ForgotPasswordModal,
        'enabledForgot',
        'requestParams'
    );
}
