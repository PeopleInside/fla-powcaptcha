import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

export default [
    new Extend.Admin()
        .setting(
            () => ({
                setting: 'peopleinside-powcaptcha.enabled_login',
                type: 'boolean',
                label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_login_label'),
                help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_login_help'),
            }),
            100
        )
        .setting(
            () => ({
                setting: 'peopleinside-powcaptcha.enabled_signup',
                type: 'boolean',
                label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_signup_label'),
                help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_signup_help'),
            }),
            90
        )
        .setting(
            () => ({
                setting: 'peopleinside-powcaptcha.enabled_forgot',
                type: 'boolean',
                label: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_forgot_label'),
                help: app.translator.trans('peopleinside-powcaptcha.admin.settings.enabled_forgot_help'),
            }),
            80
        )
        .setting(
            () => ({
                setting: 'peopleinside-powcaptcha.difficulty',
                type: 'select',
                options: {
                    '1': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_1') as string,
                    '2': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_2') as string,
                    '3': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_3') as string,
                    '4': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_4') as string,
                    '5': app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_5') as string,
                },
                default: '3',
                label: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_label'),
                help: app.translator.trans('peopleinside-powcaptcha.admin.settings.difficulty_help'),
            }),
            70
        ),
];
