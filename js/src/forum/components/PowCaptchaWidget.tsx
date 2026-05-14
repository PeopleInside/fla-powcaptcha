import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import type PowCaptchaState from '../states/PowCaptchaState';

interface PowCaptchaWidgetAttrs extends ComponentAttrs {
    state: PowCaptchaState;
}

/**
 * Inline widget shown inside the login / sign-up / forgot-password modals.
 *
 * Visual states
 * ─────────────
 *  solving  → spinner + "Verifying security…"
 *  solved   → ✓ checkmark + "Security check passed"
 *  error    → ✗ icon + error message + Retry button
 */
export default class PowCaptchaWidget extends Component<PowCaptchaWidgetAttrs> {
    oncreate(vnode: any) {
        super.oncreate(vnode);
        // Start solving as soon as the widget is mounted.
        this.attrs.state.start();
    }

    view() {
        const { state } = this.attrs;
        const status = state.getStatus();

        return (
            <div className={`PowCaptchaWidget PowCaptchaWidget--${status}`}>
                {(status === 'loading' || status === 'solving') && (
                    <div className="PowCaptchaWidget-solving">
                        <LoadingIndicator size="small" />
                        <span className="PowCaptchaWidget-label">
                            {app.translator.trans('peopleinside-powcaptcha.forum.solving')}
                        </span>
                    </div>
                )}

                {status === 'solved' && (
                    <div className="PowCaptchaWidget-solved">
                        <span className="icon fas fa-check-circle" aria-hidden="true" />
                        <span className="PowCaptchaWidget-label">
                            {app.translator.trans('peopleinside-powcaptcha.forum.verified')}
                        </span>
                    </div>
                )}

                {status === 'error' && (
                    <div className="PowCaptchaWidget-error">
                        <span className="icon fas fa-times-circle" aria-hidden="true" />
                        <span className="PowCaptchaWidget-label">
                            {app.translator.trans('peopleinside-powcaptcha.forum.error')}
                        </span>
                        <Button
                            className="Button Button--text"
                            onclick={() => state.retry()}
                        >
                            {app.translator.trans('peopleinside-powcaptcha.forum.retry')}
                        </Button>
                    </div>
                )}
            </div>
        );
    }
}
