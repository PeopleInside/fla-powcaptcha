import app from 'flarum/forum/app';
import extendAuthModals from './extendAuthModals';

app.initializers.add('peopleinside-fla-powcaptcha', () => {
    extendAuthModals();
});
