import app from 'flarum/forum/app';
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
        const isSolving = status === 'loading' || status === 'solving';

        // All three inner panels are always rendered so the container keeps a
        // fixed height throughout every state transition.  The active panel is
        // revealed with CSS opacity (see forum.less), which produces a smooth
        // cross-fade without any layout jump in the modal.
        return (
            <div className={`PowCaptchaWidget PowCaptchaWidget--${status}`}>
                <div className={`PowCaptchaWidget-inner PowCaptchaWidget-solving${isSolving ? ' is-visible' : ''}`}>
                    <LoadingIndicator size="small" />
                    <span className="PowCaptchaWidget-label">
                        {state.isSubmitQueued
                            ? app.translator.trans('peopleinside-powcaptcha.forum.submitting')
                            : app.translator.trans('peopleinside-powcaptcha.forum.solving')}
                    </span>
                </div>

                <div className={`PowCaptchaWidget-inner PowCaptchaWidget-solved${status === 'solved' ? ' is-visible' : ''}`}>
                    <span className="icon fas fa-check-circle" aria-hidden="true" />
                    <span className="PowCaptchaWidget-label">
                        {app.translator.trans('peopleinside-powcaptcha.forum.verified')}
                    </span>
                </div>

                <div className={`PowCaptchaWidget-inner PowCaptchaWidget-error${status === 'error' ? ' is-visible' : ''}`}>
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
            </div>
        );
    }
}
