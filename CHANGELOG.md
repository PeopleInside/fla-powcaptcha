# Changelog

## 3.8.1

- Added a safety-net autoloader (`autoload.php`): if the extension files are replaced by hand or Composer's optimized classmap is stale, the forum no longer fails to boot with `Class "…\PowServiceProvider" not found`. After a manual update you should still run `composer dump-autoload`.

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
- Anonymous event log in the admin panel (last 30 passed + 30 failed, no IP / UA / personal data, automatic pruning, write cap during floods, *Clear log* button).
- Admin settings: difficulty level, adaptive difficulty, `/api/token` protection, event log.
- PHP test suite (`php tests/run.php`).

### Changed
- Difficulty is now a **level 1–3** stored in `peopleinside-powcaptcha.level`. A migration converts the old `difficulty` (3/4/5) keeping the same real work; the old value is kept for downgrades. Labels now show measured times instead of the previous inaccurate estimates.
- Frontend rebuilt from source: the shipped `js/dist/forum.js` previously did **not** match `js/src` (it used `crypto.subtle` with a 2M-attempt / 15 s cap, which made the highest level fail for ~15 % of visitors). Dynamic attempt/time limits, MessageChannel yielding (works in background tabs), transparent renewal of stale tokens, and waiting for the solve on submit.
- Accessibility: `role="status"` / `aria-live` announcements, only the current state is rendered, honeypot hidden from assistive tech, reduced-motion support.

### Removed
- `IpDetector` (header-trusting IP detection), dead `legacySettings.ts`, stray `js/dist/admin.js.bak`.
- README advice about `proxy_headers` / `proxy_all`: these keys do not exist in Flarum. Configure real-IP at the web server instead.
