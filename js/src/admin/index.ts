import app from 'flarum/admin/app';
import registerSettings from './extend';

app.initializers.add('peopleinside-powcaptcha', () => {
    registerSettings();
});
