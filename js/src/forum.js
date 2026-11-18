import app from 'flarum/frontend/app';

app.initializers.add('peopleinside/fla-powcaptcha', () => {
    // Inizializzazione estensione PoW CAPTCHA lato frontend
    console.log('PoW CAPTCHA frontend loaded');
});
