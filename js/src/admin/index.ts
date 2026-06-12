import app from 'flarum/admin/app';
import registerLegacySettings from './legacySettings';

export { default as extend } from './extend';

app.initializers.add('peopleinside-fla-powcaptcha', () => {
    // Flarum 2.x registers settings through the Extend.Admin extender above.
    if (typeof flarum !== 'undefined' && flarum.reg) {
        return;
    }

    registerLegacySettings();
});
