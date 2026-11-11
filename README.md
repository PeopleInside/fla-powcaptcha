# PoW CAPTCHA for Flarum

[![Packagist](https://img.shields.io/packagist/v/peopleinside/fla-powcaptcha.svg)](https://packagist.org/packages/peopleinside/fla-powcaptcha)
[![License](https://img.shields.io/github/license/PeopleInside/fla-powcaptcha.svg)](LICENSE)

A **local Proof-of-Work CAPTCHA** extension for [Flarum](https://flarum.org/) that protects login, registration and password-reset forms without relying on any external service (no Cloudflare, no Google reCAPTCHA, no cookies).

## How it works

1. When an auth modal opens, the browser silently asks the Flarum API for a **challenge** for that specific form (login, sign-up, password reset).
2. The server returns a signed, stateless challenge. Its difficulty, issue time and form are protected by an HMAC, so the browser cannot alter them and the server stores nothing at this point.
3. The browser solves a SHA-256 puzzle (finds a nonce *N* such that `SHA-256(challenge:N)` starts with *D* hex zeros).
4. The token `challenge:nonce` is sent with the form. The server checks the signature, the form, the age, the work done and that the token has never been used (atomically), and rejects the request otherwise.

Bots must repeat the work for every single request, and the work grows automatically for networks that misbehave (see *Adaptive difficulty*).

## Features

- 🔒 **No external services** – fully self-hosted, no cookies
- ⚡ **Invisible to users** – solved in the background while they fill in the form
- 🧮 **Three difficulty levels** – plus **adaptive difficulty** that raises the work (up to +2 steps) for networks with repeated failures or many sign-ups, and +1 for everyone during a site-wide attack
- ✍️ **Signed stateless challenges** – difficulty and form cannot be forged; a login token cannot be used on the sign-up form
- 🔁 **Atomic single use** – a token can be consumed only once, even with parallel requests (uses a cache lock when available)
- 🪤 **Honeypot and time trap** – invisible traps for form-filling bots (sign-up must take at least 2 s)
- 🛡️ **Optional protection of `/api/token`** – the public API login endpoint that bypasses the login form
- 📋 **Anonymous event log** in the admin panel – last 30 passed / 30 failed checks, **no IP address or personal data**, self-pruning, with a *Clear log* button
- ♿ **Accessible** – polite live-region announcements, no visual puzzle, no dragging, honeypot hidden from assistive technology
- 🌍 **Italian & English**, 🌓 dark / light mode, ✅ Flarum 2.x (the PHP side also keeps a Flarum 1.x code path, but the shipped JavaScript is built for 2.x)

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | ≥ 8.1   |
| Flarum     | ^1.8 or ^2.0 |

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

<details>
<summary>For Flarum 1.8 use the version 2.3</summary>

The future will be Flarum 2.0 that support the latest release.

Install with 

```bash
composer require peopleinside/fla-powcaptcha:2.3
```
</details>

## Update

```bash
composer update peopleinside/fla-powcaptcha
php flarum migrate
php flarum cache:clear
```

`migrate` is required: it creates the log table and converts the old difficulty setting (see *Upgrading from 3.7.x*).

## How to remove

```bash
composer remove peopleinside/fla-powcaptcha
```

Then enable the extension in the Flarum admin panel.

## Configuration

Go to **Admin → Extensions → PoW CAPTCHA**.

| Setting | Default | Description |
|---------|---------|-------------|
| Enable on Login / Registration / Password Reset | on | Which forms are protected |
| Difficulty level | 2 – Balanced | Level 1 = 3 hex zeros (~4k hashes), level 2 = 4 (~65k), level 3 = 5 (~1M). Approximate time on a recent PC: instant / ≈ 0.1 s / ≈ 1–3 s (phones are 3–6× slower) |
| Adaptive difficulty | on | Raises the difficulty for suspicious networks (hard ceiling: 6 zeros) |
| Protect the API login endpoint | on for new installs, **off for existing ones** | Requires a CAPTCHA token on direct `POST /api/token` calls |
| Keep an event log | on | Last 30 passed + 30 failed checks |
| Record and display IP addresses in CAPTCHA logs | **off** (disabled by default) | Stores and displays visitor IP addresses in logs. Disabled by default for privacy / GDPR compliance |

### Upgrading from 3.7.x

The old *difficulty* setting (3/4/5) is converted once, by a migration, to the new level (1/2/3) with **exactly the same real difficulty**: old 1 or 3 → level 1, old 2 or 4 → level 2, old 5 → level 3. The old value is left in the database, so downgrading still works. Run `php flarum migrate` and `php flarum cache:clear` after updating.

### Protecting `/api/token`

Flarum's login form posts to `/login`, which calls `/api/token` internally, so a bot that calls `/api/token` directly never meets the login CAPTCHA. With this option on, direct calls must include `captchaToken` (and no value in `pow_website`) obtained from `GET /api/powcaptcha/challenge?action=token`. Turn it off if mobile apps or integrations log in through the API. It only takes effect on Flarum core versions that mark internal API calls (`RequestUtil::isInternal`); on older cores it is simply inactive, never blocking the normal form. Flarum's own login throttler (5 failed attempts per minute per account and IP) keeps applying in all cases.

## Development (for contributors only)

The extension ships with pre-compiled frontend assets (`js/dist/*`), so no build step is needed to install or use it. **Always rebuild `js/dist` before tagging a release** so the shipped bundle matches `js/src`:

```bash
cd js
npm ci
npm run build
```

The PHP logic is covered by plain-PHP tests (no Flarum installation required): `php tests/run.php`. After every build run `bash tests/js/run.sh` (Node only): it loads the shipped bundles the way Flarum does and catches boot-time crashes before they reach a forum.

## Security Notes

- Challenges are valid for **5 minutes**, signed with HMAC-SHA256 using a per-installation secret (created on install, stored in the settings table), and **single use**.
- The server recomputes the hash itself; difficulty and form come from the signed challenge, never from the client.
- Fail-closed: if the cache is unavailable, verification fails instead of letting requests through.
- Challenges issued to networks that already look abusive are bound to that network, so they cannot be requested from a clean IP and used from a dirty one. Normal visitors are never bound (no problems with dual-stack or mobile networks).
- Challenge requests are rate limited (30 per minute per IPv4 address or IPv6 /64).
- **Limits you should know about**: Proof-of-Work raises the cost of every request but does not tell humans from bots, and it does not stop a determined attacker with a headless browser or a native SHA-256 solver. Combine it with e-mail confirmation, approval of first posts and reputation checks. On busy sites prefer Redis / APCu / Memcached over the `file` cache: the file driver does not delete expired entries on its own.

### Anonymous log and privacy

The log stores only time, form, outcome, a technical reason and difficulty. It never stores IP addresses (not even truncated or hashed), user agents, usernames or e-mail addresses. Abuse counters used for rate limiting and adaptive difficulty live in the cache, are keyed by an HMAC of the network (never the raw IP) and expire within minutes. Even so, mention the CAPTCHA in your privacy policy; this is not legal advice.

## Running Behind a Reverse Proxy / Load Balancer

**Important:** this extension (like Flarum core) uses the connection's `REMOTE_ADDR` as the client address and deliberately ignores `X-Forwarded-For`, `Client-IP` and similar headers, which anyone can forge. Flarum has **no** `proxy_headers` / `proxy_all` config keys (older versions of this README suggested them mistakenly — they have no effect on core and must not be relied on).

If you use a reverse proxy or a CDN, configure the **web server** so `REMOTE_ADDR` carries the real visitor address, for example:

- nginx: `set_real_ip_from <proxy CIDR>; real_ip_header X-Forwarded-For;` (or `CF-Connecting-IP` for Cloudflare) with `real_ip_recursive on;`
- Apache: `mod_remoteip` with `RemoteIPHeader` and `RemoteIPTrustedProxy`

If `REMOTE_ADDR` is a private or reserved address (typical when that is not configured), the per-IP features — rate limit, adaptive difficulty and network binding — are **switched off automatically** instead of treating all visitors as one; the CAPTCHA itself keeps working.

## Troubleshooting

**The forum still seems to run old code after an update** (typical when the version number stays the same, because Composer may serve its cached copy):

1. Replace the extension folder **completely** (delete it, then copy the whole content of the release); do not overlay files onto the old ones.
2. Run `composer clear-cache`, then `composer dump-autoload` and `php flarum cache:clear` from the Flarum root.
3. Check the server side: `ls vendor/peopleinside/fla-powcaptcha/src` must list `Provider`, `Middleware`, `Service`, `Controller`…, and `extend.php` must contain `autoload.php` (`grep -c autoload.php vendor/peopleinside/fla-powcaptcha/extend.php` → `1`).
4. Check the browser side: open the forum in a private window, press F12 and type `flarum.extensions['peopleinside-fla-powcaptcha']`. It must answer `{build: "3.8.0+…"}`; if it does not, the old JavaScript is still being served.

**`Class "PeopleInside\PowCaptcha\…" not found`** in `storage/logs`: the files are incomplete or Composer's classmap is stale. Follow the steps above.

**A form is broken or empty**: in *Admin → Extensions → PoW CAPTCHA* switch off the CAPTCHA for that form. If the problem remains, it is not caused by this extension.

## License

[Apache-2.0](LICENSE)
