import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type PowCaptchaState from '../states/PowCaptchaState';

interface Attrs { state: PowCaptchaState }

export default class PowCaptchaWidget extends Component<Attrs> {
  oncreate(vnode: any) {
    super.oncreate(vnode);
    this.attrs.state.start();
  }

  onremove(vnode: any) {
    super.onremove(vnode);
    this.attrs.state.reset();
  }

  view() {
    const { state } = this.attrs;
    const status = state.getStatus();
    const busy = status === 'loading' || status === 'solving';

    let label: any = '';
    if (busy) label = app.translator.trans('peopleinside-powcaptcha.forum.solving');
    else if (status === 'solved') label = app.translator.trans('peopleinside-powcaptcha.forum.verified');
    else if (status === 'error') label = state.getErrorMessage();

    return m(
      'div',
      { className: `PowCaptchaWidget PowCaptchaWidget--${status}` },
      // Honeypot: real users never see or fill it.
      m('div', { className: 'PowCaptchaWidget-hp', 'aria-hidden': 'true' },
        m('input', {
          type: 'text',
          name: 'pow_website',
          tabindex: '-1',
          autocomplete: 'off',
          inert: true,
          'data-lpignore': 'true',
          'data-1p-ignore': 'true',
          'data-form-type': 'other',
          value: state.getHoneypotValue(),
          oninput: (e: any) => state.setHoneypotValue(e.target.value),
        })
      ),
      m('div', { className: 'PowCaptchaWidget-status', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' },
        busy ? m(LoadingIndicator, { size: 'small', display: 'inline' }) : null,
        status === 'solved' ? m('i', { className: 'icon fas fa-check-circle', 'aria-hidden': 'true' }) : null,
        status === 'error' ? m('i', { className: 'icon fas fa-exclamation-circle', 'aria-hidden': 'true' }) : null,
        m('span', { className: 'PowCaptchaWidget-label' }, label),
        status === 'error'
          ? m(Button, { className: 'Button Button--link', onclick: () => state.retry() }, app.translator.trans('peopleinside-powcaptcha.forum.retry'))
          : null
      )
    );
  }
}
