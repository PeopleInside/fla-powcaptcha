import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: any;

interface LogRow {
    id: number;
    ts: number;
    action: string;
    result: 'ok' | 'fail';
    reason: string;
    difficulty: number;
    ip?: string | null;
}

type Filter = 'all' | 'ok' | 'fail';

const t = (key: string, params: Record<string, unknown> = {}) =>
    app.translator.trans('peopleinside-powcaptcha.admin.logs.' + key, params) as any;

/**
 * Event log of the latest CAPTCHA outcomes. The server keeps the latest 30 events
 * per outcome and prunes the rest on its own; this panel can also clear it manually.
 * IP addresses are recorded and shown only if explicitly enabled in the settings.
 */
export default class LogsPanel extends Component {
    private rows: LogRow[] | null = null;
    private loading = false;
    private failed = false;
    private filter: Filter = 'all';
    private showIp = false;

    oninit(vnode: any): void {
        super.oninit(vnode);
        this.load();
    }

    private endpoint(): string {
        return app.forum.attribute<string>('apiUrl').replace(/\/$/, '') + '/powcaptcha/logs';
    }

    private load(): void {
        this.loading = true;
        this.failed = false;
        m.redraw();

        app.request<{ data: LogRow[]; show_ip?: boolean }>({ method: 'GET', url: this.endpoint() })
            .then((response) => {
                this.rows = response?.data ?? [];
                this.showIp = Boolean(response?.show_ip);
            })
            .catch(() => {
                this.failed = true;
            })
            .then(() => {
                this.loading = false;
                m.redraw();
            });
    }

    private clear(): void {
        if (!window.confirm(t('confirm_clear') as string)) return;

        this.loading = true;
        m.redraw();

        app.request({ method: 'DELETE', url: this.endpoint() })
            .then(() => {
                this.rows = [];
                app.alerts.show({ type: 'success' }, t('cleared') as string);
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

        return (
            <div className="Form-group PowLogs">
                <label>{t('title')}</label>
                <div className="helpText">{t('help')}</div>

                <div className="PowLogs-toolbar">
                    {(['all', 'ok', 'fail'] as Filter[]).map((f) => (
                        <Button
                            className={'Button Button--text' + (this.filter === f ? ' active' : '')}
                            onclick={() => (this.filter = f)}
                            aria-pressed={this.filter === f ? 'true' : 'false'}
                        >
                            {t('filter_' + f)}
                        </Button>
                    ))}
                    <span className="PowLogs-spacer" />
                    <Button className="Button" icon="fas fa-sync-alt" onclick={() => this.load()} disabled={this.loading}>
                        {t('refresh')}
                    </Button>
                    <Button className="Button Button--danger" icon="fas fa-trash-alt" onclick={() => this.clear()} disabled={this.loading}>
                        {t('clear')}
                    </Button>
                </div>

                {this.loading && !this.rows ? <LoadingIndicator /> : null}
                {this.failed ? <p className="PowLogs-error">{t('load_error')}</p> : null}

                {this.rows && !rows.length && !this.failed ? <p className="PowLogs-empty">{t('empty')}</p> : null}

                {rows.length ? (
                    <div className="PowLogs-tableWrap">
                        <table className="PowLogs-table">
                            <thead>
                                <tr>
                                    <th>{t('col_time')}</th>
                                    {this.showIp ? <th>{t('col_ip')}</th> : null}
                                    <th>{t('col_form')}</th>
                                    <th>{t('col_result')}</th>
                                    <th>{t('col_reason')}</th>
                                    <th>{t('col_difficulty')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.id}>
                                        <td>{new Date(r.ts * 1000).toLocaleString()}</td>
                                        {this.showIp ? <td><code>{r.ip || '–'}</code></td> : null}
                                        <td>{t('actions.' + r.action)}</td>
                                        <td>
                                            <span className={`PowLogs-badge PowLogs-badge--${r.result}`}>
                                                {r.result === 'ok' ? t('result_ok') : t('result_fail')}
                                            </span>
                                        </td>
                                        <td>{t('reasons.' + r.reason)}</td>
                                        <td>{r.difficulty || '–'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : null}
            </div>
        );
    }
}
