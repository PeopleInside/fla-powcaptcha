import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Switch from 'flarum/common/components/Switch';

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
 * IP addresses are recorded and shown only if explicitly enabled via the switch.
 */
export default class LogsPanel extends Component {
    private rows: LogRow[] | null = null;
    private loading = false;
    private failed = false;
    private filter: Filter = 'all';
    private showIp = false;
    private savingIpSetting = false;

    oninit(vnode: any): void {
        super.oninit(vnode);
        this.load();
    }

    private get isIpLoggingEnabled(): boolean {
        const settingVal = (app.data?.settings as Record<string, unknown> | undefined)?.['peopleinside-powcaptcha.log_ip'];
        if (settingVal !== undefined && settingVal !== null) {
            return settingVal === '1' || settingVal === 'true' || settingVal === true;
        }
        return this.showIp;
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

    private toggleLogIp(val: boolean): void {
        this.savingIpSetting = true;
        this.showIp = val;
        m.redraw();

        app.request({
            method: 'POST',
            url: app.forum.attribute<string>('apiUrl').replace(/\/$/, '') + '/settings',
            body: {
                'peopleinside-powcaptcha.log_ip': val ? '1' : '0',
            },
        })
            .then(() => {
                if (app.data?.settings) {
                    (app.data.settings as Record<string, unknown>)['peopleinside-powcaptcha.log_ip'] = val ? '1' : '0';
                }
                this.load();
            })
            .catch(() => {
                app.alerts.show({ type: 'error' }, 'Could not save setting.');
            })
            .then(() => {
                this.savingIpSetting = false;
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
        const ipActive = this.showIp || this.isIpLoggingEnabled;

        return (
            <div className="Form-group PowLogs">
                <label>{t('title')}</label>
                <div className="helpText">{t('help')}</div>

                {/* INTERRUTTORE DIRETTO PER REGISTRARE E MOSTRARE GLI INDIRIZZI IP */}
                <div
                    className="PowLogs-ipSettingBox"
                    style="margin: 14px 0 16px; padding: 14px 16px; background: rgba(127, 127, 127, 0.08); border-radius: 8px; border: 1px solid rgba(127, 127, 127, 0.2);"
                >
                    <Switch
                        state={ipActive}
                        loading={this.savingIpSetting}
                        onchange={(val: boolean) => this.toggleLogIp(val)}
                    >
                        {app.translator.trans('peopleinside-powcaptcha.admin.settings.log_ip_label')}
                    </Switch>
                    <div className="helpText" style="margin-top: 6px; font-size: 12px; line-height: 1.5;">
                        {app.translator.trans('peopleinside-powcaptcha.admin.settings.log_ip_help')}
                    </div>
                </div>

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
                    <span className="helpText" style="margin:0 8px;font-size:12px;">
                        <i className={ipActive ? "fas fa-eye text-success" : "fas fa-eye-slash text-muted"} style="margin-right:4px;"></i>
                        {ipActive ? t('ip_status_active') : t('ip_status_disabled')}
                    </span>
                    <Button className="Button" icon="fas fa-sync-alt" onclick={() => this.load()} disabled={this.loading}>
                        {t('refresh')}
                    </Button>
                    <Button className="Button Button--danger" icon="fas fa-trash-alt" onclick={() => this.clear()} disabled={this.loading}>
                        {t('clear')}
                    </Button>
                </div>

                {!ipActive ? (
                    <p className="helpText" style="margin:4px 0 12px;font-size:12px;opacity:0.85;">
                        <i className="fas fa-info-circle" style="margin-right:4px;"></i>
                        {t('ip_banner_disabled')}
                    </p>
                ) : null}

                {this.loading && !this.rows ? <LoadingIndicator /> : null}
                {this.failed ? <p className="PowLogs-error">{t('load_error')}</p> : null}

                {this.rows && !rows.length && !this.failed ? <p className="PowLogs-empty">{t('empty')}</p> : null}

                {rows.length ? (
                    <div className="PowLogs-tableWrap">
                        <table className="PowLogs-table">
                            <thead>
                                <tr>
                                    <th>{t('col_time')}</th>
                                    {ipActive ? <th>{t('col_ip')}</th> : null}
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
                                        {ipActive ? <td><code>{r.ip || '–'}</code></td> : null}
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
