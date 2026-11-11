# Changelog

## 3.8.1

### Added
- **Automated GitHub Actions release packaging**: releases automatically compile production JavaScript bundles and attach a versioned ZIP archive (`fla-powcaptcha-<version>.zip`) to each GitHub release.
- **Optional IP address logging in CAPTCHA event logs**:
  - Added `ip` column to the `powcaptcha_logs` table via database migration.
  - Added setting: *Record and display IP addresses in CAPTCHA logs* (`peopleinside-powcaptcha.log_ip`).
  - **Disabled by default** to preserve privacy and ensure GDPR compliance, with explicit warnings regarding personal data implications.
  - When enabled, records visitor IP addresses and displays them in the admin event log table. When disabled, IP addresses are neither stored nor shown.

## 3.8.0

### Security
- **Stateless, HMAC-signed challenges** (`v1.<rand>.<issuedAt>.<difficulty>.<form>.<bound>.<mac>`): difficulty and form can no longer be altered or taken from the client, and issuing a challenge writes nothing to the cache (no more cache/inode growth from unredeemed challenges).
- **Atomic single use** of tokens (cache lock, `add()` fallback). Previously `Cache::pull()` (get + forget) allowed parallel requests to reuse one solution. Fails closed on cache errors.
- **Challenges are bound to one form** (login / sign-up / password reset / API login).
- **IP handling fixed**: one resolution path (`REMOTE_ADDR` as exposed by Flarum core) for issuing *and* verifying. Forwarded-for headers are no longer trusted (they are client-controlled), and private / proxy-shared addresses no longer cause site-wide rate limiting or failed logins.
- **Adaptive difficulty** (+1/+2 for networks with repeated failures or many sign-ups, +1 site-wide under attack; ceiling 6 zeros) with network-bound challenges for flagged networks.
- **Optional guard for `POST /api/token`**, which bypassed the login CAPTCHA.
- Rate limiting on IPv6 now uses the /64 prefix instead of single addresses.
- Honeypot renamed to `pow_website` (the old `email_confirmation` is a common autofill target and could block real users), plus a 2 s minimum time for sign-ups.
- Missing / empty tokens on login and password reset are now counted and logged (implicit validation rule).

### Added
- Safety-net autoloader (`autoload.php`): a stale Composer classmap can no longer take the forum down with `Class "…\PowServiceProvider" not found`.
- Anonymous event log in the admin panel (last 30 passed + 30 failed, no IP / UA / personal data, automatic pruning, write cap during floods, *Clear log* button).
- Admin settings: difficulty level, adaptive difficulty, `/api/token` protection, event log.
- PHP test suite (`php tests/run.php`) and a JavaScript suite (`bash tests/js/run.sh`, Node only) that loads the shipped bundles the way Flarum does (PHP wrapper, `bootExtensions`, lazy modals, real Mithril and a core-faithful ItemList).

### Changed
- The frontend now extends the auth modals **by module path** (they are lazy-loaded in Flarum 2.x), always leaves `module.exports` set (Flarum registers the extension from it), and adds the widget to the form with the same keyed/unkeyed style as the other fields (Mithril rejects mixed lists). A build id is exposed in the browser console as `flarum.extensions['peopleinside-fla-powcaptcha'].build`.
- Difficulty is now a **level 1–3** stored in `peopleinside-powcaptcha.level`. A migration converts the old `difficulty` (3/4/5) keeping the same real work; the old value is kept for downgrades. Labels now show measured times instead of the previous inaccurate estimates.
- Frontend rebuilt from source: the shipped `js/dist/forum.js` previously did **not** match `js/src` (it used `crypto.subtle` with a 2M-attempt / 15 s cap, which made the highest level fail for ~15 % of visitors). Dynamic attempt/time limits, MessageChannel yielding (works in background tabs), transparent renewal of stale tokens, and waiting for the solve on submit.
- Accessibility: `role="status"` / `aria-live` announcements, only the current state is rendered, honeypot hidden from assistive tech, reduced-motion support.

### Removed
- `IpDetector` (header-trusting IP detection), dead `legacySettings.ts`, stray `js/dist/admin.js.bak`.
- README advice about `proxy_headers` / `proxy_all`: these keys do not exist in Flarum. Configure real-IP at the web server instead.
