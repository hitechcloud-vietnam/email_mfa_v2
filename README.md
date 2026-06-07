<!-- markdownlint-disable MD060 -->

# Email MFA V2 — HostBill Module

> **Module:** `email_mfa_v2`
> **Class:** `Email_Mfa_V2` extends `MultiFactorAuthModule`
> **Version:** 2.2.2026-06-06
> **Owner:** Pho Tue SoftWare And Technology Solutions JSC

Email-delivered MFA with a **multi-code reuse window**. Up to N codes may
coexist for the same user; each is single-use, but the others stay valid
until they individually expire. Solves the "code didn't arrive, resend
invalidated the first one" friction in HostBill's built-in `email_mfa`.

---

## What it does

| Feature                         | Built-in `email_mfa`                    | This module (V2)                                       |
|---------------------------------|-----------------------------------------|--------------------------------------------------------|
| Multiple valid codes concurrent | No                                      | Yes (default 5, configurable)                          |
| TTL                             | 3600 s hardcoded                        | 1200 s default, configurable (60 s to infinity)        |
| Code length                     | 6 hardcoded                             | 4 to 15, configurable                                  |
| Plaintext storage               | `HBCache::set($key, $token, ...)`       | SHA-256 hash only (DB + cache)                         |
| Audit trail                     | No                                      | Yes: `ip_address`, `user_agent`, `created_at`          |
| Per-purpose channels            | `login`, `setup`, `action` mixed        | Separate cache keys + DB rows                         |
| Auto-cleanup                    | None                                    | Hourly cron purges used/expired older than 24 h        |
| Manage active codes UI          | No                                      | Yes: `manage.tpl` lists + revokes                      |
| LRU eviction                    | n/a                                     | Drops oldest if `max_active` exceeded                  |
| REST API                        | No                                      | Six JSON endpoints under `?api=email_mfa_v2/...`       |

---

## Install

1. Copy the entire `email_mfa_v2/` folder into
   `includes/modules/Other/email_mfa_v2/` of your HostBill install.
2. Log in to **Admin → Apps & Integrations → Modules**.
3. Find **Email MFA V2** and click **Install**.
   - The `install()` method creates the `hb_email_mfa_codes` table.
   - Creates 3 email templates (`MFA:Email V2:Verify Login|Setup|Action`).
   - Inserts 18 language keys into `hb_language_locales` (EN + VI).
4. Activate the module and set it as the user's default MFA method
   (Admin → Apps & Integrations → MFA → Set default).

## Configuration

| Field                        | Type   | Default | Notes                                       |
|------------------------------|--------|---------|---------------------------------------------|
| `Code Length`                | input  | `6`     | 4 to 15 chars (HostBill legacy limit)       |
| `Code TTL (seconds)`         | input  | `1200`  | 20 min; min 60                              |
| `Max Active Codes per User`  | input  | `5`     | LRU eviction when exceeded                   |
| `Auto-send code after login` | check  | `1`     | Mirrors V1's behavior                       |
| `Cache Backend`              | select | `auto`  | `db_only` fallback if HBCache unhealthy    |

## How it works

1. **Issue:** `sendCode($purpose)` does the following:
   - generate N-digit code (default 6)
   - `code_hash = hash('sha256', $code)`
   - `INSERT hb_email_mfa_codes` with `expires_at = NOW() + ttl`
   - append to `HBCache::get('emfa:u:Client:42:login')` array
   - LRU-evict oldest if `count > max_active`
   - send via `Mailer::loadFromTemplate`
2. **Verify:** `verify($data)` does the following:
   - `rateLimitAction()` (8 attempts / 5 min)
   - read `HBCache`; on miss → `SELECT hb_email_mfa_codes WHERE used_at IS NULL AND expires_at > NOW()`
   - iterate, `hash_equals()` against `hash('sha256', $code)`
   - on match: `UPDATE ... SET used_at = NOW()`, flip `u:true` in cache, return `true`
3. **Disable:** drop the mfaManager row + `revokeAllForUser` (DB) +
   invalidate cache for all 3 purposes.
4. **Cleanup:** `cron/call_Hourly` deletes used codes older than 24 h and
   expired codes older than 24 h.

## Database

Table `hb_email_mfa_codes` is created by `install()`. Schema:

```text
id          BIGINT(20) PK AUTO_INCREMENT
user_type   ENUM('Admin','Client')
user_id     INT(10) UNSIGNED
module_id   INT(10) UNSIGNED
code_hash   CHAR(64)                  -- SHA-256 hex; never plaintext
purpose     ENUM('login','setup','action') DEFAULT 'login'
expires_at  DATETIME
used_at     DATETIME NULL
ip_address  VARCHAR(45)
user_agent  VARCHAR(255)
created_at  DATETIME DEFAULT CURRENT_TIMESTAMP

INDEX idx_user_active (user_type, user_id, purpose, expires_at, used_at)
INDEX idx_hash        (code_hash)
INDEX idx_expires     (expires_at)
```

## Cron

`call_Hourly` is the only scheduled job. Recommended schedule: `0 * * * *`.

---

## API surfaces

This module exposes its six endpoints via **two parallel API surfaces**,
mirroring HostBill's built-in `locations_v2` module:

| Surface     | URL pattern                          | Auth                              | File                                                                  |
|-------------|--------------------------------------|-----------------------------------|-----------------------------------------------------------------------|
| HBController| `?api=email_mfa_v2/<method>`        | `Controller::isApi()`             | `api/class.email_mfa_v2_controller.php`                               |
| UserApi     | `/email_mfa_v2/<method>/<args>`     | `auth: true` in manifest          | `api/class.email_mfa_v2_apiroutes.php` (reads `email_mfa_v2_apiroutes.json`) |

Both surfaces call the same `api*` helper methods on the main module
class, so behavior, security guarantees, and rate limits are identical.
Pick the one that matches the calling client's auth pattern.

### Common endpoints (both surfaces)

- `status`     — enrollment + active-codes summary
- `send`       — issue and email a new code
- `verify`     — verify a submitted code
- `list`       — list active codes (8-char hash_prefix only)
- `revokeall`  — revoke all active codes
- `disable`    — disable MFA for a user

### `GET ?api=email_mfa_v2/status` (HBController)

Read-only summary for one user. Required: `user_type` (`Admin` or
`Client`), `user_id`.

Response:

```json
{
  "success": true,
  "user_type": "Client",
  "user_id": 42,
  "enrolled": true,
  "active_codes": 2,
  "purposes": { "login": 2, "setup": 0, "action": 0 }
}
```

### `POST ?api=email_mfa_v2/send`

Issue and email a new code. Required: `user_type`, `user_id`. Optional:
`purpose` (`login`, `setup`, or `action`, default `login`).

Returns `{success: true, ...}` on accept; `{success: false, message:
"send_failed"}` (HTTP 502) if the SMTP/email layer rejects.

### `POST ?api=email_mfa_v2/verify`

Verify a code. Required: `user_type`, `user_id`, `code`. Optional:
`purpose`. Returns 200 on success, 401 on invalid/expired.

### `GET ?api=email_mfa_v2/listactive`

List active codes for one user. Returns only an 8-char `hash_prefix` —
never the full SHA-256 hash, never the plaintext code.

```json
{
  "success": true,
  "user_type": "Client",
  "user_id": 42,
  "codes": [
    {
      "id": 17,
      "purpose": "login",
      "created_at": "2026-06-06 05:38:11",
      "expires_at": "2026-06-06 05:58:11",
      "ip_address": "203.0.113.42",
      "hash_prefix": "5e884898"
    }
  ]
}
```

### `POST ?api=email_mfa_v2/revokeall`

Revoke all active codes for the target user. Enrollment is kept; the user
can still log in via MFA but with no pre-existing valid codes.

```json
{ "success": true, "user_type": "Client", "user_id": 42, "revoked": 3 }
```

### `POST ?api=email_mfa_v2/disable`

Disable MFA entirely for the target user (drops the mfaManager row AND
purges all active codes AND invalidates cache for all 3 purposes).

### Authentication and authorization

All endpoints:

- Require a valid HostBill API call (`Controller::isApi()` must return true).
- Accept both `user_id` and `client_id` (alias for the Client type).
- Reject `user_id <= 0` with HTTP 400 `missing_user_id`.
- Do **not** themselves log the user in; `verify` returns success/failure
  so the calling client can drive its own session flow.

## Use case examples

```bash
# Read status
curl 'https://billing.example.com/?api=email_mfa_v2/status&user_type=Client&user_id=42'

# Send a login code
curl -X POST 'https://billing.example.com/?api=email_mfa_v2/send' \
  -d 'user_type=Client&user_id=42&purpose=login'

# Verify a code
curl -X POST 'https://billing.example.com/?api=email_mfa_v2/verify' \
  -d 'user_type=Client&user_id=42&purpose=login&code=123456'

# Revoke everything (e.g. support ticket: "I lost my codes")
curl -X POST 'https://billing.example.com/?api=email_mfa_v2/revokeall' \
  -d 'user_type=Client&user_id=42'

# Disable MFA for a user
curl -X POST 'https://billing.example.com/?api=email_mfa_v2/disable' \
  -d 'user_type=Client&user_id=42'
```

---

## File layout

```text
module_dev_hostbill/Other/email_mfa_v2/
├── class.email_mfa_v2.php          ← Main module (extends MultiFactorAuthModule)
├── default.json                    ← Config defaults + purpose enum + rate limits
├── install.sql                     ← Table schema (loaded by install(), locations v2 pattern)
├── orm/
│   └── class.orm_email_mfa_v2_code.php    ← Eloquent Model (NOT HBorm — that class doesn't exist)
├── cron/
│   └── class.email_mfa_v2_cron.php
├── api/
│   ├── class.email_mfa_v2_controller.php        ← HBController route (legacy)
│   ├── class.email_mfa_v2_apiroutes.php         ← UserApi route handler (locations v2 pattern)
│   └── email_mfa_v2_apiroutes.json              ← Route manifest for UserApi dispatcher
├── lib/
│   ├── code_manager.php
│   └── scripts.js
├── templates/
│   ├── admin/{setup,enable,verify,confirm,manage}.tpl
│   └── user/{setup,enable,verify,confirm,manage}.tpl
├── lang/
│   ├── english.php
│   └── vietnamese.php
├── README.md
└── CHANGELOG.md
```

## Security notes

- **Plaintext codes are never stored** — only SHA-256 hashes in both DB
  and cache. A DB leak does not yield usable codes.
- **`hash_equals()`** is used for compare, providing timing-attack safety.
- **Rate limiting** is inherited from the base class
  (`rateLimitAction()`, 8 attempts per 5 min per user+IP).
- **CSRF**: every form includes `{securitytoken}`.
- **LRU eviction** keeps the working set bounded — `max_active_codes` is
  hard-capped at 50.
- **No SMTP over insecure transport** — uses HostBill `Mailer` with
  whatever TLS/SSL settings the admin configured.
- **`ip_address` and `user_agent` audit** are stored for each code
  issuance. Use them for support tickets, not for rate limiting.
- **API responses leak nothing**: `listactive` returns only 8 chars of
  `code_hash`; `code_hash` and plaintext are never serialized.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Code doesn't arrive | Email template disabled in HostBill | Admin → Settings → Email Templates → enable `MFA:Email V2:Verify *` |
| `email_mfa_v2_send_failed` | Same as above; or SMTP credentials missing | Check Admin → Settings → Mail |
| `email_mfa_v2_rate_limited` | 8 attempts / 5 min exceeded | Wait, or raise `RL_ATTEMPTS` constant in `class.multifactorauthmodule.php` |
| Cache miss every time | `Cache Backend` = `db_only` and `HBCache` empty | Switch back to `auto` |
| Codes pile up | Cron not running | Schedule `call_Hourly` at least once a day |
| `verify()` returns `false` for a known code | Code was used; or expired; or LRU-evicted | Check `manage.tpl` for active codes |
| API returns 400 `missing_user_id` | `user_id` and `client_id` both missing or zero | Pass one of them |
| API returns 400 `api_only` | Endpoint called from non-API context | Use `?api=email_mfa_v2/...` with valid HostBill API key |

## License

© 2026 Pho Tue SoftWare And Technology Solutions JSC. All rights reserved.
Dual-license — see `.agents/LICENSING.md` in the project root.
