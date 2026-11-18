import app from 'flarum/admin/app';

app.initializers.add('peopleinside/fla-powcaptcha', () => {
    // Inizializzazione estensione PoW CAPTCHA lato admin
    console.log('PoW CAPTCHA admin loaded');
});
