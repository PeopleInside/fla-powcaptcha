import app from 'flarum/admin/app';

/**
 * Flarum 1.x admin settings registration via app.extensionData.
 * Flarum 2.x uses the Extend.Admin extender exported from extend.tsx.
 */
export default function registerLegacySettings(): void {
    const appWithSettingsApi = app as unknown as {
        extensionData?: { for: (extensionId: string) => { registerSetting: (setting: unknown) => void } };
    };

    const settingsRegistry = appWithSettingsApi.extensionData;

    if (!settingsRegistry || typeof settingsRegistry.for !== 'function') {
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
            '1': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_1') as string,
            '2': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_2') as string,
            '3': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_3') as string,
            '4': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_4') as string,
            '5': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_5') as string,
        },
        label: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_label'),
        help: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_help'),
    });
}
