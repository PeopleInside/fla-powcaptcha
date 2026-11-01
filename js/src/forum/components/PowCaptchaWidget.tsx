import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import icon from 'flarum/common/helpers/icon';
import app from 'flarum/forum/app';
import type PowCaptchaState from '../states/PowCaptchaState';

declare const m: any;

interface Attrs {
    state: PowCaptchaState;
}

/**
 * Accessible PoW status line.
 *
 *  - One polite live region announces progress and result to screen readers; the
 *    state labels are real text, not colour or icon only.
 *  - The honeypot lives in a wrapper that is hidden from assistive technology
 *    (aria-hidden + inert), not focusable and told to password managers to ignore.
 *  - Fixed height, so the modal never jumps between states.
 */
export default class PowCaptchaWidget extends Component<Attrs> {
    oncreate(vnode: any): void {
        super.oncreate(vnode);
        this.attrs.state.start();
    }

    onremove(vnode: any): void {
        super.onremove(vnode);
        this.attrs.state.reset();
    }

    view() {
        const { state } = this.attrs;
        const status = state.getStatus();
        const busy = status === 'loading' || status === 'solving';

        let label = '';
        if (busy) label = app.translator.trans('peopleinside-powcaptcha.forum.solving') as string;
        else if (status === 'solved') label = app.translator.trans('peopleinside-powcaptcha.forum.verified') as string;
        else if (status === 'error') label = state.getErrorMessage();

        return (
            <div className={`PowCaptchaWidget PowCaptchaWidget--${status}`}>
                <div className="PowCaptchaWidget-hp" aria-hidden="true">
                    <input
                        type="text"
                        name="pow_website"
                        tabindex="-1"
                        autocomplete="off"
                        inert={true}
                        data-lpignore="true"
                        data-1p-ignore="true"
                        data-form-type="other"
                        value={state.getHoneypotValue()}
                        oninput={(e: Event) => state.setHoneypotValue((e.target as HTMLInputElement).value)}
                    />
                </div>

                <div className="PowCaptchaWidget-status" role="status" aria-live="polite" aria-atomic="true">
                    {busy ? <LoadingIndicator size="small" display="inline" /> : null}
                    {status === 'solved' ? icon('fas fa-check-circle', { 'aria-hidden': 'true' }) : null}
                    {status === 'error' ? icon('fas fa-exclamation-circle', { 'aria-hidden': 'true' }) : null}
                    <span className="PowCaptchaWidget-label">{label}</span>
                    {status === 'error' ? (
                        <Button className="Button Button--link" onclick={() => state.retry()}>
                            {app.translator.trans('peopleinside-powcaptcha.forum.retry')}
                        </Button>
                    ) : null}
                </div>
            </div>
        );
    }
}
