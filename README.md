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

## Screenshot
<img width="1642" height="720" alt="Screenshot 2026-05-14 205357" src="https://github.com/user-attachments/assets/1576655b-0d83-46a8-b35b-0cecee923faf" />
<img width="376" height="468" alt="1778785001-133386-screenshot-2026-05-14-205627" src="https://github.com/user-attachments/assets/ad1bd396-e5de-4acf-9b7b-4c5ea817c89d" />


## Disclaimer

This software is provided **"AS IS"**, without any warranty. While it has been tested and reasonable efforts are made to ensure security and reliability, no guarantees are provided. As an open project, anyone may contribute or report issues, but this does not imply endorsement or liability from the maintainers.

**You use this software entirely at your own risk.** The authors and contributors are not liable for any damages, data loss, or unexpected behavior resulting from its use, modification, or distribution. Always review and test the code independently before deploying it in critical or production environments.

## Installation

```bash
composer require peopleinside/fla-powcaptcha
```

## Update

```bash
composer update peopleinside/fla-powcaptcha
```

## How to remove

```bash
composer remove peopleinside/fla-powcaptcha
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

## Development (for contributors only)

The extension is distributed with pre-compiled frontend assets (`js/dist/*`), so **no JS build step is required** to install or use it.

## Security Notes

- Each challenge is valid for **5 minutes** and is **single-use** (deleted after successful verification).
- The challenge is a 128-bit cryptographically random value; it cannot be guessed or forged.
- The server independently re-computes the SHA-256 hash to verify the solution.

## License

[Apache-2.0](LICENSE)
