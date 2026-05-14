import app from 'flarum/common/app';
import extendAdmin from './extend';

export default function () {
    app.initializers.add('peopleinside-powcaptcha', () => {
        extendAdmin();
    });
}
