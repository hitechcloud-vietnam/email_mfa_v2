<!-- markdownlint-disable MD024 -->

# Changelog — Email MFA V2

All notable changes to this module are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.2.2026-06-06] — 2026-06-06

### Fixed

- **Critical: `Class "HBorm" not found` on install/activate.** The
  original ORM file extended a `HBorm` base class that does not
  exist in HostBill core. The class was refactored to extend
  `Illuminate\Database\Eloquent\Model` directly, matching the
  pattern used by every other HostBill module in the repo
  (e.g. `Core_examp_hostbill/OtherModule/locationsv2/orm/`,
  `module_dev_hostbill/Other/hitechsearch/orm/`). All call sites
  in the main class were updated to use Eloquent object access
  (`$row->code_hash`) instead of array access (`$row['code_hash']`).
- Replaced the in-PHP `createTable()` method with `install.sql`
  (next to the main class), following the locations_v2 convention:
  statements separated by `######`, executed via `$this->db->exec()`
  in `setupTable()`.
- **Critical: `Call to undefined method PDO::escape()` in
  `setupLanguages()`.** The hand-rolled `REPLACE INTO hb_language_locales`
  SQL builder called `$this->db->escape()`, but `$this->db` is a
  raw PDO instance which has no `escape()` method. Rewrote the
  function to use the HostBill `LangEdit` helper — `LangEdit::addTranslation($key, $value)`
  for the English strings and `LangEdit::addTranslations([...], 'vietnamese')`
  for the Vietnamese overrides, exactly the same pattern used by
  `Core_examp_hostbill/DNS/powerdns/class.powerdns.php` lines 88-96.
- **Critical: `Undefined constant "ADMIN"` in `code_manager.php:31` (and
  the same constant was referenced 18+ times across the main class,
  ORM, and 2 API controllers).** HostBill's `ADMIN` constant is
  conditionally defined in `hbf/settings/constants.php`
  (`if (!defined("ADMIN")) define("ADMIN", ...)`), so it may not
  exist when module code runs; `CLIENT` is never defined as a
  global alias at all (only as the namespaced `HBC\TYPE\CLIENT`).
  Replaced every `$x === ADMIN` / `$x === CLIENT` check with a
  new helper `Email_Mfa_V2_Code_Manager::normalizeUserType($x)`
  that compares against the literal string `'admin'` (case-insensitive)
  and returns `'Admin'` or `'Client'`. Same call site: any
  comparison in `class.email_mfa_v2.php`, `orm/class.orm_email_mfa_v2_code.php`,
  `lib/code_manager.php`, `api/class.email_mfa_v2_controller.php`,
  and `api/class.email_mfa_v2_apiroutes.php`.
- **Removed 10 call sites of `UserApi::error()` from
  `api/class.email_mfa_v2_apiroutes.php`.** That helper does not
  exist on the real `UserApi` class
  (`Core_examp_hostbill/Other/userapi/class.userapi.php` only has
  `dispatch`, `route`, `view`, `module`, etc.). Replaced with an
  inline `errorResponse($reason, $extra)` private method that
  returns `['success' => false, 'reason' => ..., ...]`.

### Added

- New `api/email_mfa_v2_apiroutes.json` manifest plus matching
  `api/class.email_mfa_v2_apiroutes.php` handler, mirroring the
  pattern used by HostBill's built-in `locations_v2` module. This
  registers the six MFA endpoints with HostBill's UserApi dispatcher
  so they appear in the API browser alongside the core API surface.
- New `default.json` at the module root, matching the locations v2
  convention: a small JSON file carrying config defaults, the
  purpose enum, email template names, and rate-limit constants.
  Admins can drop in an override file before install.

### Notes

- The original `api/class.email_mfa_v2_controller.php` (used via
  `?api=email_mfa_v2/<method>` and `Controller::isApi()`) is kept
  unchanged — both API surfaces (HBController and UserApi) coexist
  and call the same `api*` helper methods on the main module class.

### Improved

- **`setupEmails()` now auto-enables existing disabled templates.**
  If the admin previously disabled an `MFA:Email V2:Verify *`
  template and then re-ran install (or the module was upgraded and
  the template survived from a prior install), the `send=0` flag
  is now flipped to `send=1` automatically so the module "just
  works" without manual intervention. Done via a direct PDO
  `UPDATE` because the `Emailtemplates` model has no
  `updateEmailTemplate()` method in current HostBill core.
- **Email template bodies are now richer.** The built-in
  `email_mfa` ships a one-line body ("To confirm your account
  login, please use the following code: ..."). V2 templates now
  include the business name, code validity in minutes, the
  requester's browser fingerprint, and the source IP, with an
  explicit "if you did not initiate this, contact support"
  escape hatch. The subject line is also prefixed with the brand
  (`[HostBill] Your login verification code`) so users can
  triage the email correctly in their inbox.
- **Diagnostic logging added to `verifyEmailTemplatesReady()`
  and `dispatchEmail()`.** When the module refuses to send a code
  because the template is missing or disabled, the warning now
  names the tplname, the user_type, and the `send` flag value
  so admins can fix it without grepping the codebase. Dispatch
  success/failure is also logged at info / error level
  respectively.

## [2.1.2026-06-06] — 2026-06-06

### Added

- New `api/` directory with `class.email_mfa_v2_controller.php` exposing
  six REST/JSON endpoints for programmatic MFA management:
  - `GET  ?api=email_mfa_v2/status`     — enrollment + active-codes summary
  - `POST ?api=email_mfa_v2/send`       — issue and email a new code
  - `POST ?api=email_mfa_v2/verify`     — verify a code
  - `GET  ?api=email_mfa_v2/listactive` — list active codes (hash-prefix only)
  - `POST ?api=email_mfa_v2/revokeall`  — revoke all active codes
  - `POST ?api=email_mfa_v2/disable`    — disable MFA for a user
- Seven new API-helper methods on the main module class
  (`apiGetEnrolled`, `apiCountActiveCodes`, `apiSendCode`, `apiVerify`,
  `apiRevokeAllCodes`, `apiDisableForUser`, `apiListActiveCodes`).
- All API responses use `{success: bool, ...}` envelope and
  `Content-Type: application/json; charset=utf-8`.
- API responses return appropriate HTTP status codes (200, 400, 401, 500, 502).
- The `listactive` endpoint returns only the first 8 chars of `code_hash`
  (a `hash_prefix` field) — never the full hash, never plaintext.

## [2.0.2026-06-06] — 2026-06-06

### Added

- Initial release of Email MFA V2.
- Multi-code reuse window: up to `Max Active Codes per User` (default 5)
  codes may coexist for the same user, each single-use, each valid
  until its individual `expires_at`.
- Configurable `Code Length` (4–15), `Code TTL` (default 1200 s = 20 min),
  `Max Active Codes per User` (1–50), `Auto-send code after login`,
  `Cache Backend` (`auto` or `db_only`).
- New `hb_email_mfa_codes` table (Eloquent-backed) with three indexes
  (`idx_user_active`, `idx_hash`, `idx_expires`) covering verify,
  cross-user fallback, and cron cleanup paths.
- SHA-256 hashing of all issued codes — plaintext is never persisted.
- `HBCache` read fast-path with DB fallback; write-through on both
  issue and verify-used.
- Hourly cron `call_Hourly` purges used/expired rows older than 24 h.
- `manage.tpl` lists active codes (purpose, expires_at, ip) with a
  "Revoke all" action.
- Bilingual UI (English + Vietnamese) bundled in `$lang` array.
- 3 email templates auto-installed: `MFA:Email V2:Verify Login|Setup|Action`.
- LRU eviction of the oldest active code when `max_active` is exceeded.
- `hash_equals()` for timing-attack-safe compare.
- Per-purpose cache keys (`login`, `setup`, `action`) so codes never
  cross-purpose.

### Changed

- N/A (first release).

### Fixed

- N/A (first release).

### Security

- Plaintext OTP codes are never stored — only SHA-256 hashes.
- `hash_equals()` used for compare.
- Rate-limited both `verify` and `sendCode` (8/5 min, base class).
- `{securitytoken}` on every form.
- Audit columns (`ip_address`, `user_agent`) on every issued code.
- LRU eviction prevents unbounded growth from a user spamming "Resend".
