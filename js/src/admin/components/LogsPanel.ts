import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

interface LogRow {
  id: number;
  ts: number;
  action: string;
  result: 'ok' | 'fail';
  reason: string;
  difficulty: number;
  ip: string | null;
}

const tr = (key: string, params: Record<string, unknown> = {}) => app.translator.trans('peopleinside-powcaptcha.admin.logs.' + key, params);

/** Anonymous CAPTCHA event log shown in the extension settings. */
export default class LogsPanel extends Component<any> {
  rows: LogRow[] | null = null;
  loading = false;
  failed = false;
  filter: 'all' | 'ok' | 'fail' = 'all';
  showIp = false;
  keep = 100;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  endpoint(): string {
    return (app.forum.attribute('apiUrl') as string).replace(/\/$/, '') + '/powcaptcha/logs';
  }

  load() {
    this.loading = true;
    this.failed = false;
    m.redraw();

    app
      .request<any>({ method: 'GET', url: this.endpoint() })
      .then((res) => {
        this.rows = res?.data ?? [];
        this.showIp = Boolean(res?.show_ip);
        this.keep = Number(res?.keep) || 100;
      })
      .catch(() => {
        this.failed = true;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  clear() {
    if (!window.confirm(tr('confirm_clear') as string)) return;

    this.loading = true;
    m.redraw();

    app
      .request({ method: 'DELETE', url: this.endpoint() })
      .then(() => {
        this.rows = [];
        app.alerts.show({ type: 'success' }, tr('cleared'));
      })
      .catch(() => {
        this.failed = true;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const rows = (this.rows ?? []).filter((r) => this.filter === 'all' || r.result === this.filter);
    const showIp = this.showIp;

    return m(
      'div',
      { className: 'Form-group PowLogs' },
      m('label', null, tr('title')),
      m('div', { className: 'helpText' }, tr('help', { count: this.keep })),
      m(
        'div',
        { className: 'PowLogs-toolbar' },
        ['all', 'ok', 'fail'].map((f) =>
          m(
            Button,
            {
              className: 'Button Button--text' + (this.filter === f ? ' active' : ''),
              onclick: () => (this.filter = f as any),
              'aria-pressed': this.filter === f ? 'true' : 'false',
            },
            tr('filter_' + f)
          )
        ),
        m('span', { className: 'PowLogs-spacer' }),
        m(Button, { className: 'Button', icon: 'fas fa-sync-alt', onclick: () => this.load(), disabled: this.loading }, tr('refresh')),
        m(Button, { className: 'Button Button--danger', icon: 'fas fa-trash-alt', onclick: () => this.clear(), disabled: this.loading }, tr('clear'))
      ),
      this.rows && !showIp ? m('p', { className: 'helpText' }, tr('ip_banner_disabled')) : null,
      this.rows && showIp
        ? m('p', { className: 'helpText PowLogs-gdpr' }, m('i', { className: 'fas fa-exclamation-triangle', style: 'margin-right:4px;' }), tr('ip_banner_enabled'))
        : null,
      this.loading && !this.rows ? m(LoadingIndicator, null) : null,
      this.failed ? m('p', { className: 'PowLogs-error' }, tr('load_error')) : null,
      !this.rows || rows.length || this.failed ? null : m('p', { className: 'PowLogs-empty' }, tr('empty')),
      rows.length
        ? m(
            'div',
            { className: 'PowLogs-tableWrap' },
            m(
              'table',
              { className: 'PowLogs-table' },
              m(
                'thead',
                null,
                m(
                  'tr',
                  null,
                  m('th', null, tr('col_time')),
                  showIp ? m('th', null, tr('col_ip')) : null,
                  m('th', null, tr('col_form')),
                  m('th', null, tr('col_result')),
                  m('th', null, tr('col_reason')),
                  m('th', null, tr('col_difficulty'))
                )
              ),
              m(
                'tbody',
                null,
                rows.map((r) =>
                  m(
                    'tr',
                    { key: r.id },
                    m('td', null, new Date(r.ts * 1000).toLocaleString()),
                    showIp ? m('td', null, m('code', null, r.ip || '–')) : null,
                    m('td', null, tr('actions.' + r.action)),
                    m('td', null, m('span', { className: `PowLogs-badge PowLogs-badge--${r.result}` }, r.result === 'ok' ? tr('result_ok') : tr('result_fail'))),
                    m('td', null, tr('reasons.' + r.reason)),
                    m('td', null, r.difficulty || '–')
                  )
                )
              )
            )
          )
        : null
    );
  }
}
