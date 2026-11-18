# Changelog

## 3.10.2

### Added
- **Age-based log retention, on by default**: log entries older than **30 days** are now deleted automatically, even when the log has not reached its size limit. Two new admin settings: *Also delete log entries older than a number of days* (`peopleinside-powcaptcha.log_prune_age`, default on) and *Delete log entries older than (days)* (`peopleinside-powcaptcha.log_max_age_days`, default 30, range 1-365; invalid or empty values fall back to 30).
- The age limit is enforced on every log write and also when the admin opens the log, so a quiet site (no new events) does not keep old rows - and IP addresses, if recording is enabled - indefinitely.
- The log panel shows the configured number of days.

- **Privacy notice under the CAPTCHA** (login, sign-up, password reset), on by default: a *Privacy* button opens a short popover explaining that the check runs in the browser without cookies or third-party services and which technical data is processed. The text adapts to the admin settings (behaviour checks, IP logging, retention days). New settings: *Show a privacy notice under the CAPTCHA* (`privacy_notice`, default on) and *Privacy policy link* (`privacy_url`, optional; when set, the popover links to it). Only `http(s)://` addresses and site paths (`/privacy`) are accepted; anything else is dropped server-side. The popover is a real button: it opens with tap, Enter or Space, closes with Esc, an outside click or when focus leaves, and has `aria-expanded` / `aria-controls`. Texts are in the language files (EN, IT), so they can be reworded with a language pack.

### Notes
- `js/dist/forum.js` was patched directly as well (no `js/src` in the archive).
- No migration needed: existing installations get the new defaults automatically (on, 30 days). Entries older than 30 days disappear the first time the log is written or opened after the update; if you want to keep them longer, raise the number of days (or turn the option off) beforehand.
- `js/dist/admin.js` was patched directly (the `js/src` folder mentioned in 3.10.1 is not in the archive). Mirror the change in `js/src` if you rebuild from source.

## 3.10.1

### Added
- **Configurable event-log size**: new admin setting *Log entries to keep (per outcome)* (`peopleinside-powcaptcha.log_keep`). The default is now **100** passed + **100** failed checks (was a fixed 30 + 30). Allowed range 10-1000; invalid or empty values fall back to 100. Pruning now trims both outcomes on every write, so lowering the value shrinks the log right away.
- The log help text in the admin panel shows the configured number.

### Changed
- The frontend source is now part of the archive: `js/src` (TypeScript), `js/webpack.config.js` (with `devtool: 'source-map'`) and `js/tsconfig.json`. `npm ci && npm run build` also emits `js/dist/*.js.map` - commit them together with the dist files.
- The build id exposed as `flarum.extensions['peopleinside-fla-powcaptcha'].build` is now the extension version (3.10.1) and is added by the webpack config instead of a manual patch.

### Notes
- `js/src` was reconstructed from the shipped bundles (including the 3.9.0 / 3.10.0 hand patches). Run `npm ci && npm run build` once and diff `js/dist` before relying on it; the source maps could not be generated in the environment where this release was prepared.
- No migration needed. IP addresses already stored stay subject to the new limit.

## 3.10.0

### Fixed
- **Critical:** the compiled `js/dist/forum.js` was still the 3.8.x solver (hex zeros, difficulty capped at 8), while the server sends difficulties in bits (12-24). Every challenge was rejected as "unavailable". The solver and the challenge sanity check now work in bits.
- Admin: the difficulty select only offered levels 1-3 and defaulted to 2 (server default is 3); levels 4-5 added.
- Removed the unused `IpDetector` (it trusted spoofable proxy headers) and the stale source maps.

### Added
- **Registration limit per IP**: max 2 per rolling 24 h (configurable, 0 = off). Persistent table `powcaptcha_signups` (keyed hash only, purged after 48 h). Run `php flarum migrate`.
- **Login / password-reset / API-token / sign-up attempt limits** per IP with lockout (login 10 per 10 min -> 15 min; reset 3 per hour -> 1 h; sign-up attempts 10 per 10 min -> 15 min).
- **Behaviour checks**: header heuristics (missing or bot User-Agent, missing Accept-Language, cross-site Origin / Sec-Fetch-Site) and client interaction summary (trusted pointer / key / focus events, time on page). Strong signals reject, weak ones raise the adaptive difficulty. Time trap now on login (1 s) and reset (2 s), sign-up 3 s.
- New log reasons: `rate_limited`, `signup_limit`, `suspicious`.

## 3.9.0

### Changed
- **Default level is now 3** (was 2). A migration moves installations still on level 2 to 3; any other level an administrator picked is left untouched.
- **Difficulty is now measured in bits** instead of hex zeros, so levels can be finer than 16x. Levels 1-3 are unchanged (12 / 16 / 20 bits). **New levels 4 (22 bits) and 5 (24 bits).** Each bit doubles the work. Challenges issued by 3.8.x (single digit) are still accepted for their 5-minute lifetime.
- Adaptive bump is unchanged in effect (+4 bits per step, up to +8, ceiling 24 bits) and never goes below the configured level.

### Fixed
- **Sign-ups sent without any token** (bots posting directly to the API) were rejected by the `required` rule before the PoW rule ran, so they were never written to the event log and never counted by the adaptive difficulty. They are now logged as `missing`/`honeypot` and feed the adaptive counters (new middleware `RecordMissingSignupToken`, Flarum 2.x).
- If `php flarum migrate` had not been run after 3.8.1, the missing `ip` column made **every** log insert fail silently (empty log). The logger now detects the missing column and writes without it.
- Event-log `difficulty` is no longer clamped to 9 (it is now a bit count).

### Notes
- `js/dist` was patched directly (solver and admin select); `js/src` is not part of this archive, so the same change must be applied to `js/src/forum/solver.ts` and `js/src/admin` before the next rebuild. The stale source maps were removed.

## 3.8.1

### Added
- **Optional IP address logging in the CAPTCHA event log** (passed and failed checks):
  - New `ip` column on `powcaptcha_logs` (migration `2026_11_11_000000_add_ip_to_powcaptcha_logs_table`).
  - New setting *Record and display IP addresses in CAPTCHA logs* (`peopleinside-powcaptcha.log_ip`).
  - **Off by default** (IP addresses are personal data under GDPR). When off, no IP is stored and none is returned by the API; IPs recorded earlier stay hidden but remain in the table until the log is cleared or pruned.

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
