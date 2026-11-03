import app from 'flarum/admin/app';
import LogsPanel from './components/LogsPanel';

type SettingsApi = { registerSetting: (setting: unknown, priority?: number) => SettingsApi | void };
type Registry = { for: (extensionId: string) => SettingsApi };

const P = 'peopleinside-powcaptcha.';
const T = 'peopleinside-powcaptcha.admin.settings.';
const tr = (key: string) => app.translator.trans(T + key);

export default function registerSettings(): void {
    const appWithSettingsApi = app as unknown as { registry?: Registry; extensionData?: Registry };

    // Flarum 2.x exposes `app.registry`, Flarum 1.x `app.extensionData`.
    const registry = [appWithSettingsApi.registry, appWithSettingsApi.extensionData].find(
        (candidate): candidate is Registry => Boolean(candidate && typeof candidate.for === 'function')
    );

    if (!registry) return;

    const settings = registry.for('peopleinside-fla-powcaptcha');

    for (const key of ['enabled_login', 'enabled_signup', 'enabled_forgot']) {
        settings.registerSetting({ setting: P + key, type: 'bool', label: tr(key + '_label'), help: tr(key + '_help') });
    }

    // Stored values are the levels 1–3 (the server maps them to the real work required).
    settings.registerSetting({
        setting: P + 'level',
        type: 'select',
        options: {
            '1': tr('level_1') as string,
            '2': tr('level_2') as string,
            '3': tr('level_3') as string,
        },
        default: '2',
        label: tr('level_label'),
        help: tr('level_help'),
    });

    for (const key of ['adaptive', 'protect_token_endpoint', 'log_enabled']) {
        settings.registerSetting({ setting: P + key, type: 'bool', label: tr(key + '_label'), help: tr(key + '_help') });
    }

    settings.registerSetting(() => <LogsPanel />);
}
