import app from 'flarum/admin/app';

export default function registerSettings(): void {
    // Migrate deprecated difficulty values to the new 3-level scheme (3/4/5).
    // Old level 1 → new level 3 (Easy), old level 2 → new level 4 (High).
    // Any value outside the valid set {3,4,5} is clamped to the default (4).
    const settingsObj = (app as any).data?.settings || (app as any).settings;
    if (settingsObj) {
        const current = settingsObj['peopleinside-powcaptcha.difficulty'];
        const numVal = typeof current === 'string' ? parseInt(current, 10) : (typeof current === 'number' ? current : NaN);
        const legacyMap: Record<number, string> = { 1: '3', 2: '4' };
        if (!isNaN(numVal) && legacyMap[numVal] !== undefined) {
            settingsObj['peopleinside-powcaptcha.difficulty'] = legacyMap[numVal];
        } else if (!isNaN(numVal) && ![3, 4, 5].includes(numVal)) {
            settingsObj['peopleinside-powcaptcha.difficulty'] = '4';
        }
    }

    const appWithSettingsApi = app as unknown as {
        registry?: { for: (extensionId: string) => { registerSetting: (setting: unknown) => void } };
        extensionData?: { for: (extensionId: string) => { registerSetting: (setting: unknown) => void } };
    };

    const settingsRegistry = [appWithSettingsApi.registry, appWithSettingsApi.extensionData].find(
        (candidate): candidate is { for: (extensionId: string) => { registerSetting: (setting: unknown) => void } } =>
            Boolean(candidate && typeof candidate.for === 'function')
    );

    if (!settingsRegistry) {
        return;
    }

    const settings = settingsRegistry.for('peopleinside-fla-powcaptcha');

    settings.registerSetting({
        setting: 'peopleinside-powcaptcha.enabled_login',
        type: 'bool',
        label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_login_label'),
        help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_login_help'),
    });

    settings.registerSetting({
        setting: 'peopleinside-powcaptcha.enabled_signup',
        type: 'bool',
        label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_signup_label'),
        help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_signup_help'),
    });

    settings.registerSetting({
        setting: 'peopleinside-powcaptcha.enabled_forgot',
        type: 'bool',
        label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_forgot_label'),
        help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_forgot_help'),
    });

    settings.registerSetting({
        setting: 'peopleinside-powcaptcha.difficulty',
        type: 'select',
        options: {
            '3': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_3') as string,
            '4': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_4') as string,
            '5': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_5') as string,
        },
        label: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_label'),
        help: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_help'),
    });
}
