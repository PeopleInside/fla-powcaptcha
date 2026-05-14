import app from 'flarum/common/app';
import extendAuthModals from './extendAuthModals';

export default function () {
    app.initializers.add('peopleinside-powcaptcha', () => {
        extendAuthModals();
    });
}
