# PoW CAPTCHA for Flarum

[![Packagist](https://img.shields.io/packagist/v/peopleinside/fla-powcaptcha.svg)](https://packagist.org/packages/peopleinside/fla-powcaptcha)
[![License](https://img.shields.io/github/license/PeopleInside/fla-powcaptcha.svg)](LICENSE)

A **local Proof-of-Work CAPTCHA** extension for [Flarum](https://flarum.org/) that protects login, registration and password-reset forms without relying on any external service (no Cloudflare, no Google reCAPTCHA, no cookies).

## How it works

1. When an auth modal opens, the browser silently fetches a one-time **challenge** from the Flarum API.
2. The browser solves a SHA-256 hash puzzle (finds a nonce *N* such that `SHA-256(challenge:N)` starts with *D* hex zeros, where *D* is the configured difficulty).
3. The solution token `challenge:nonce` is appended to the form submission.
4. The server verifies the solution and rejects the request if the check fails.

Bots must solve the same puzzle for every request; legitimate users complete it invisibly in the background (< 100 ms at the default difficulty).

## Features

- 🔒 **No external services** – fully self-hosted
- ⚡ **Invisible to users** – solved automatically while they fill the form
- ⚙️ **Configurable difficulty** – 5 levels (< 1 ms → ~10 s)
- 🌓 **Dark / light mode** – widget adapts to Flarum's current theme
- 🌍 **Italian & English** – auto-detected; add more locales in `locale/`
- 🔁 **Replay-proof** – each challenge is single-use (stored in Flarum's cache)
- ✅ **Flarum 1.x and 2.x** compatible

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | ≥ 8.1   |
| Flarum     | ^1.0 or ^2.0 |

## Installation

```bash
composer require peopleinside/fla-powcaptcha
```

Then enable the extension in the Flarum admin panel.

## Configuration

Go to **Admin → Extensions → PoW CAPTCHA** and choose:

| Setting | Default | Description |
|---------|---------|-------------|
| Enable on Login | ✓ | Protect the login form |
| Enable on Registration | ✓ | Protect the sign-up form |
| Enable on Password Reset | ✓ | Protect the forgot-password form |
| Difficulty | 3 – Standard (~100 ms) | SHA-256 leading-zero count (1–5) |

## Development

```bash
# Install JS dependencies
cd js && npm install

# Watch for changes (development)
npm run dev

# Production build
npm run build
```

## Security Notes

- Each challenge is valid for **5 minutes** and is **single-use** (deleted after successful verification).
- The challenge is a 128-bit cryptographically random value; it cannot be guessed or forged.
- The server independently re-computes the SHA-256 hash to verify the solution.

## License

[Apache-2.0](LICENSE)
