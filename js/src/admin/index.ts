import app from 'flarum/admin/app';
import LogsPanel from './components/LogsPanel';

const PREFIX = 'peopleinside-powcaptcha.';
const t = (key: string) => app.translator.trans('peopleinside-powcaptcha.admin.settings.' + key);

app.initializers.add('peopleinside-fla-powcaptcha', () => {
  // Flarum 1.x exposes `app.extensionData`, 2.x `app.registry`.
  const host: any = [(app as any).registry, (app as any).extensionData].find((x: any) => Boolean(x && typeof x.for === 'function'));
  if (!host) return;

  const ext = host.for('peopleinside-fla-powcaptcha');

  for (const key of ['enabled_login', 'enabled_signup', 'enabled_forgot']) {
    ext.registerSetting({ setting: PREFIX + key, type: 'bool', label: t(key + '_label'), help: t(key + '_help') });
  }

  ext.registerSetting({
    setting: PREFIX + 'level',
    type: 'select',
    options: { 1: t('level_1'), 2: t('level_2'), 3: t('level_3'), 4: t('level_4'), 5: t('level_5') },
    default: '3',
    label: t('level_label'),
    help: t('level_help'),
  });

  for (const key of ['adaptive', 'behavior_checks', 'protect_token_endpoint', 'log_enabled']) {
    ext.registerSetting({ setting: PREFIX + key, type: 'bool', label: t(key + '_label'), help: t(key + '_help') });
  }

  // Entries kept in the event log, per outcome (server clamps to 10-1000).
  ext.registerSetting({ setting: PREFIX + 'log_keep', type: 'number', min: 10, max: 1000, default: 100, label: t('log_keep_label'), help: t('log_keep_help') });

  ext.registerSetting({ setting: PREFIX + 'log_ip', type: 'bool', label: t('log_ip_label'), help: t('log_ip_help') });

  const defaults: Record<string, number> = { signup_limit: 2, login_limit: 10, forgot_limit: 3 };
  for (const key of ['signup_limit', 'login_limit', 'forgot_limit']) {
    ext.registerSetting({ setting: PREFIX + key, type: 'number', min: 0, default: defaults[key], label: t(key + '_label'), help: t(key + '_help') });
  }

  ext.registerSetting(() => m(LogsPanel, null));
});
