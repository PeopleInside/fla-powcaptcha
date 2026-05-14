import app from 'flarum/admin/app';

export default function registerSettings(): void {
    const settings = app.extensionData.for('peopleinside-fla-powcaptcha');

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
