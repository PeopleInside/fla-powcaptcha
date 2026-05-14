import app from 'flarum/compat/app';
import registerSettings from './extend';

app.initializers.add('peopleinside-powcaptcha', () => {
    registerSettings();
});
