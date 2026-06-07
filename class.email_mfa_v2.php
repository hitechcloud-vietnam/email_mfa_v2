<?php

if (!defined('MAINDIR')) {
    die('Access denied');
}

use Other\email_mfa_v2\ORM\ORM_Email_Mfa_V2_Code;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'code_manager.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'class.orm_email_mfa_v2_code.php';

/**
 * Email MFA V2 — HostBill module.
 *
 * Email-delivered multi-factor authentication with multi-code reuse
 * window. Differences from HostBill's built-in email_mfa:
 *
 *   - Multiple codes may be active for the same user at the same time.
 *     Each code is single-use but the others stay valid until they
 *     individually expire. Solves the "code didn't arrive, resend
 *     invalidated the first one" friction.
 *
 *   - TTL is configurable (default 20 minutes = 1200 s).
 *
 *   - Max concurrent codes per user is configurable (default 5) with
 *     LRU eviction to keep the working set bounded.
 *
 *   - Storage is the hb_email_mfa_codes table (Eloquent ORM) backed by an
 *     HBCache array. Cache is the read fast-path; DB is the source
 *     of truth and survives cache eviction.
 *
 *   - Plaintext codes are never persisted. Only SHA-256 hashes go
 *     into both the DB row and the cache value.
 *
 * Base class contract: MultiFactorAuthModule (OtherModule descendant).
 *
 * @see MultiFactorAuthModule
 * @see ORM_Email_Mfa_V2_Code
 * @see Email_Mfa_V2_Code_Manager
 */
class Email_Mfa_V2 extends MultiFactorAuthModule implements Observer
{
    use Components\Traits\LoggerTrait;

    // ----- Constants -----------------------------------------------------

    const EMAIL_TPL_LOGIN  = 'MFA:Email V2:Verify Login';
    const EMAIL_TPL_SETUP  = 'MFA:Email V2:Verify Setup';
    const EMAIL_TPL_ACTION = 'MFA:Email V2:Verify Action';

    const PURPOSE_LOGIN  = 'login';
    const PURPOSE_SETUP  = 'setup';
    const PURPOSE_ACTION = 'action';

    const CACHE_TTL_BUFFER = 30;
    const DB_ONLY          = 'db_only';
    const CACHE_AUTO       = 'auto';

    // ----- Module metadata ---------------------------------------------

    /** Bump $version on every change that requires upgrade(). */
    protected $version = '2.0.2026-06-06';

    protected $modname = 'Email MFA V2';

    protected $description = 'Email-delivered MFA with multi-code reuse window. Multiple valid codes may coexist for the same user until they individually expire.';

    /**
     * Explicit info flags. The base class already sets '2famodule' => true,
     * but we add the observer/admin/user flags so HostBill's auto-detect
     * is unambiguous across installs.
     */
    protected $info = [
        '2famodule' => true,
        'isobserver' => true,
        'haveadmin' => true,
        'haveuser' => true,
    ];

    /**
     * Admin-configurable settings. Read via _getConfiguration() and
     * resolved through Email_Mfa_V2_Code_Manager::resolveConfig().
     */
    protected $configuration = [
        'Code Length' => [
            'value'       => '6',
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Code Length',
            'description' => 'OTP code length (4–15 chars). Default 6.',
        ],
        'Code TTL (seconds)' => [
            'value'       => '1200',
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Code TTL (seconds)',
            'description' => 'How long each code stays valid. Default 1200 (20 min).',
        ],
        'Max Active Codes per User' => [
            'value'       => '5',
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Max Active Codes per User',
            'description' => 'Max concurrent valid codes per user. Oldest auto-revoked when exceeded.',
        ],
        'Auto-send code after login' => [
            'value'       => '1',
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Auto-send code after login',
            'description' => 'When enabled, HostBill auto-sends a code right after password login.',
        ],
        'Cache Backend' => [
            'value'       => 'auto',
            'type'        => self::CONFIG_FIELD_SELECT,
            'options'     => [
                'auto'    => 'Auto (HBCache)',
                'db_only' => 'Database only (no cache)',
            ],
            'label'       => 'Cache Backend',
            'description' => 'Switch to db_only if HBCache is unavailable.',
        ],
    ];

    /**
     * User-facing strings. EN + VI cover the entire UI surface.
     */
    protected $lang = [
        'english' => [
            'email_mfa_v2_module_name'      => 'Email MFA V2',
            'email_mfa_v2_login_desc'       => 'You must first provide the one-time code sent to your email.',
            'email_mfa_v2_send'             => 'Send one-time code via Email',
            'email_mfa_v2_resend'           => 'Resend one-time code via Email',
            'email_mfa_v2_onetimecode'      => 'One-time code',
            'email_mfa_v2_code_required'    => 'Please enter the one-time code.',
            'email_mfa_v2_code_invalid'     => 'One-time code is not valid or expired. Try again or generate a new one.',
            'email_mfa_v2_code_expired'     => 'Your one-time code has expired. Request a new one.',
            'email_mfa_v2_setup_ok'         => 'MFA enabled. You can now log in with your email code.',
            'email_mfa_v2_enable_ok'        => 'Two-factor authentication is now active.',
            'email_mfa_v2_disable_ok'       => 'Two-factor authentication has been disabled.',
            'email_mfa_v2_resend_ok'        => 'A new one-time code has been sent.',
            'email_mfa_v2_send_failed'      => 'MFA error. One-time code can not be sent.',
            'email_mfa_v2_rate_limited'     => 'Too many attempts. Please wait a few minutes.',
            'email_mfa_v2_active_codes'     => 'You have %d active code(s) right now; the earliest expires at %s.',
            'email_mfa_v2_manage_title'     => 'Manage Email MFA V2',
            'email_mfa_v2_manage_empty'     => 'No active codes for your account.',
            'email_mfa_v2_manage_revoke'    => 'Revoke all active codes',
            'email_mfa_v2_manage_revoke_ok' => 'All active codes have been revoked.',
        ],
        'vietnamese' => [
            'email_mfa_v2_module_name'      => 'Email MFA V2',
            'email_mfa_v2_login_desc'       => 'Bạn cần nhập mã xác thực đã được gửi qua email.',
            'email_mfa_v2_send'             => 'Gửi mã xác thực qua Email',
            'email_mfa_v2_resend'           => 'Gửi lại mã xác thực qua Email',
            'email_mfa_v2_onetimecode'      => 'Mã xác thực một lần',
            'email_mfa_v2_code_required'    => 'Vui lòng nhập mã xác thực.',
            'email_mfa_v2_code_invalid'     => 'Mã xác thực không đúng hoặc đã hết hạn. Vui lòng thử lại hoặc yêu cầu mã mới.',
            'email_mfa_v2_code_expired'     => 'Mã xác thực đã hết hạn. Vui lòng yêu cầu mã mới.',
            'email_mfa_v2_setup_ok'         => 'Đã bật MFA. Bạn có thể đăng nhập bằng mã email từ lần sau.',
            'email_mfa_v2_enable_ok'        => 'Xác thực hai yếu tố đã được kích hoạt.',
            'email_mfa_v2_disable_ok'       => 'Xác thực hai yếu tố đã được tắt.',
            'email_mfa_v2_resend_ok'        => 'Mã xác thực mới đã được gửi.',
            'email_mfa_v2_send_failed'      => 'Lỗi MFA. Không thể gửi mã xác thực.',
            'email_mfa_v2_rate_limited'     => 'Quá nhiều lần thử. Vui lòng đợi vài phút.',
            'email_mfa_v2_active_codes'     => 'Bạn đang có %d mã còn hiệu lực; mã sớm nhất hết hạn lúc %s.',
            'email_mfa_v2_manage_title'     => 'Quản lý Email MFA V2',
            'email_mfa_v2_manage_empty'     => 'Tài khoản của bạn không có mã nào đang hoạt động.',
            'email_mfa_v2_manage_revoke'    => 'Thu hồi tất cả mã đang hoạt động',
            'email_mfa_v2_manage_revoke_ok' => 'Tất cả mã đang hoạt động đã được thu hồi.',
        ],
    ];

    // ----- Lifecycle ----------------------------------------------------

    public function install()
    {
        $this->setupTable();
        $this->setupEmails();
        $this->setupLanguages();
        return true;
    }

    public function upgrade($old)
    {
        // Idempotent — install() can be re-run safely.
        $this->setupTable();
        $this->setupEmails();
        $this->setupLanguages();
        return true;
    }

    public function uninstall()
    {
        // We deliberately keep the table so admins can audit old codes
        // for the 24h cleanup window. A drop happens via a separate
        // hostbill-cli tool if the admin wants it.
        return true;
    }

    // ----- MFA required methods ----------------------------------------

    public function setup(array $params)
    {
        // Email MFA has no "shared secret" to store (the code is sent
        // per-issue and verified by hash), so setup() just persists the
        // enrollment flag via the manager and dispatches the first code.
        if (!$this->verifyEmailTemplatesReady()) {
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        $saved = $this->mfaManager()->saveUserMFA(
            $this->user_type,
            $this->user_id,
            $this->getModuleId(),
            ['enrolled_at' => date('Y-m-d H:i:s')]
        );

        if (!$saved) {
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        if (!$this->sendCode(self::PURPOSE_SETUP)) {
            // Roll back the enrollment if we can't even deliver the first
            // code — the user should not see "MFA enabled" while locked
            // out of their account.
            $this->mfaManager()->deleteUserMFA($this->user_type, $this->user_id, $this->getModuleId());
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        $this->addInfo('email_mfa_v2_setup_ok');
        return true;
    }

    public function enable(array $params)
    {
        // Enabling an already-enrolled user is the same as verifying
        // the most recent setup code.
        $result = $this->verify($params, self::PURPOSE_SETUP);
        if ($result) {
            $this->addInfo('email_mfa_v2_enable_ok');
        }
        return $result;
    }

    public function verify($data, $purpose = self::PURPOSE_LOGIN)
    {
        if (!is_string($purpose) || !in_array($purpose, [self::PURPOSE_LOGIN, self::PURPOSE_SETUP, self::PURPOSE_ACTION], true)) {
            $purpose = self::PURPOSE_LOGIN;
        }

        if (!$this->rateLimitAction('Email_Mfa_V2')) {
            return false;
        }

        $code = is_array($data) ? trim((string) ($data['code'] ?? '')) : trim((string) $data);
        if ($code === '') {
            $this->addError('email_mfa_v2_code_required');
            return false;
        }

        $entries = $this->loadActiveCodes($purpose);
        $suppliedHash = Email_Mfa_V2_Code_Manager::hashCode($code);
        $now = time();

        foreach ($entries as $entry) {
            // Skip already-used entries; they linger in the cache until
            // cron prunes them so that the *other* codes stay valid.
            if (!empty($entry['u'])) {
                continue;
            }
            if ((int) $entry['e'] < $now) {
                continue;
            }
            if (!Email_Mfa_V2_Code_Manager::hashEquals($entry['h'], $suppliedHash)) {
                continue;
            }

            // Found a match. Mark used in DB + flip cache entry to u:true.
            $this->markCodeUsed($entry, $purpose);
            $this->clearLoginState();

            return true;
        }

        $this->addError('email_mfa_v2_code_invalid');
        return false;
    }

    public function disable(array $params)
    {
        // Purge every active code for this user, then drop the
        // enrollment row from the MFA manager.
        ORM_Email_Mfa_V2_Code::revokeAllForUser($this->user_type, $this->user_id);

        $deleted = $this->mfaManager()->deleteUserMFA(
            $this->user_type,
            $this->user_id,
            $this->getModuleId()
        );

        $this->invalidateCache(self::PURPOSE_LOGIN);
        $this->invalidateCache(self::PURPOSE_SETUP);
        $this->invalidateCache(self::PURPOSE_ACTION);

        if (!$deleted) {
            // No row existed; treat as success — user is already disabled.
        }

        $this->addInfo('email_mfa_v2_disable_ok');
        return true;
    }

    // ----- API helpers (used by api/class.email_mfa_v2_controller.php) ----
    //
    // Each method here takes an explicit ($userType, $userId) instead of
    // relying on $this->user_type / $this->user_id, because API calls
    // can be made by an admin to manage another user's MFA. The
    // controller is responsible for authorization.

    /**
     * Whether the given user has an active MFA enrollment.
     *
     * @param string $userType  'Admin' or 'Client'
     * @param int    $userId
     * @return bool
     */
    public function apiGetEnrolled($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;

        $row = $this->mfaManager()->getUserMFA($userType, $userId, (int) $this->getModuleId());
        return (bool) $row;
    }

    /**
     * Count active (un-used, un-expired) codes for the given user,
     * optionally filtered by purpose. Used by the manage API endpoint.
     *
     * @param string $userType
     * @param int    $userId
     * @param string|null $purpose
     * @return int
     */
    public function apiCountActiveCodes($userType, $userId, $purpose = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;

        if ($purpose !== null && !in_array($purpose, [self::PURPOSE_LOGIN, self::PURPOSE_SETUP, self::PURPOSE_ACTION], true)) {
            $purpose = null;
        }

        return (int) ORM_Email_Mfa_V2_Code::countActive($userType, $userId, $purpose);
    }

    /**
     * Send a fresh code to the given user. Re-targets the module's
     * $this->user_type / $this->user_id so the internal helpers see the
     * right (user_type, user_id) when (a) caching the entry, (b) addressing
     * the Mailer, and (c) rate-limiting per-user. We also pass the existing
     * `purpose` so `sendCode` picks the right email template.
     *
     * @param string $userType
     * @param int    $userId
     * @param string $purpose   'login' | 'setup' | 'action'
     * @return array{success:bool, message?:string}
     */
    public function apiSendCode($userType, $userId, $purpose = self::PURPOSE_LOGIN)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'invalid_user_id'];
        }
        if (!in_array($purpose, [self::PURPOSE_LOGIN, self::PURPOSE_SETUP, self::PURPOSE_ACTION], true)) {
            $purpose = self::PURPOSE_LOGIN;
        }

        // Re-init the module's user context for the target user so the
        // internal rate-limit + cache + Mailer all see the right scope.
        $this->initUser($userType, $userId);

        $sent = $this->sendCode($purpose);
        if (!$sent) {
            return ['success' => false, 'message' => 'send_failed'];
        }
        return ['success' => true];
    }

    /**
     * Verify a code on behalf of the given user. Returns a structured
     * result so the API layer can decide between 200 (success), 401
     * (invalid), 429 (rate-limited).
     *
     * @param string $userType
     * @param int    $userId
     * @param string $code
     * @param string $purpose
     * @return array{success:bool, reason?:string}
     */
    public function apiVerify($userType, $userId, $code, $purpose = self::PURPOSE_LOGIN)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return ['success' => false, 'reason' => 'invalid_user_id'];
        }
        if (!is_string($code) || trim($code) === '') {
            return ['success' => false, 'reason' => 'missing_code'];
        }
        if (!in_array($purpose, [self::PURPOSE_LOGIN, self::PURPOSE_SETUP, self::PURPOSE_ACTION], true)) {
            $purpose = self::PURPOSE_LOGIN;
        }

        $this->initUser($userType, $userId);

        $ok = $this->verify(['code' => $code], $purpose);
        if ($ok) {
            return ['success' => true];
        }
        return ['success' => false, 'reason' => 'invalid_or_expired_code'];
    }

    /**
     * Revoke all active codes for the given user. Does NOT disable the
     * enrollment — the user can still log in via MFA, just with no
     * pre-existing valid codes.
     *
     * @param string $userType
     * @param int    $userId
     * @return int  Number of rows revoked
     */
    public function apiRevokeAllCodes($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }

        $revoked = (int) ORM_Email_Mfa_V2_Code::revokeAllForUser($userType, $userId);

        $this->initUser($userType, $userId);
        $this->invalidateCache(self::PURPOSE_LOGIN);
        $this->invalidateCache(self::PURPOSE_SETUP);
        $this->invalidateCache(self::PURPOSE_ACTION);

        return $revoked;
    }

    /**
     * Disable MFA enrollment for the given user. Convenience wrapper
     * around disable() that re-targets the user first.
     *
     * @param string $userType
     * @param int    $userId
     * @return bool
     */
    public function apiDisableForUser($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        $this->initUser($userType, $userId);
        return $this->disable([]);
    }

    /**
     * List active codes for a user (for the manage API). Returns plain
     * associative arrays — never includes the plaintext code_hash, only
     * a safe 8-char prefix.
     *
     * @param string $userType
     * @param int    $userId
     * @return array<int, array<string, mixed>>
     */
    public function apiListActiveCodes($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType) === 'Admin' ? 'Admin' : 'Client';
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $rows = ORM_Email_Mfa_V2_Code::listActiveForAdmin($userType, $userId);
        $out = [];
        foreach ($rows as $row) {
            $hash = isset($row->code_hash) ? (string) $row->code_hash : '';
            $out[] = [
                'id'         => isset($row->id) ? (int) $row->id : 0,
                'purpose'    => isset($row->purpose) ? (string) $row->purpose : self::PURPOSE_LOGIN,
                'created_at' => isset($row->created_at) ? (string) $row->created_at : '',
                'expires_at' => isset($row->expires_at) ? (string) $row->expires_at : '',
                'ip_address' => isset($row->ip_address) ? (string) $row->ip_address : '',
                'hash_prefix' => substr($hash, 0, 8),
            ];
        }
        return $out;
    }

    // ----- Form generators --------------------------------------------

    public function getSetupForm($params)
    {
        if (!$this->verifyEmailTemplatesReady()) {
            $this->addError('email_mfa_v2_send_failed');
            return parent::getSetupForm($params);
        }

        // The "resend" parameter lets the AJAX button ask for a fresh
        // setup code without re-rendering the form.
        if (isset($params['resend']) && $this->rateLimitAction('Email_Mfa_V2_resend')) {
            $this->sendCode(self::PURPOSE_SETUP);
        }

        $entries = $this->loadActiveCodes(self::PURPOSE_SETUP);
        return [
            'variables' => [
                'auto_send_code'    => 1,
                'active_codes'      => count($entries),
                'earliest_expiry'   => $this->earliestExpiryLabel($entries),
                'instructions'      => $this->t('email_mfa_v2_login_desc'),
            ],
            'tpl' => $this->getTpl('setup'),
        ];
    }

    public function getEnableForm($params)
    {
        if (isset($params['resend']) && $this->rateLimitAction('Email_Mfa_V2_resend')) {
            $this->sendCode(self::PURPOSE_SETUP);
        }
        return parent::getEnableForm($params);
    }

    public function getVerifyForm($params)
    {
        // Honor the explicit resend button first.
        if (isset($params['resend']) && $this->rateLimitAction('Email_Mfa_V2_resend')) {
            $this->sendCode(self::PURPOSE_LOGIN);
        } elseif ($this->shouldAutoSendOnLogin($params)) {
            $this->sendCode(self::PURPOSE_LOGIN);
        }

        $entries = $this->loadActiveCodes(self::PURPOSE_LOGIN);
        $cfg     = Email_Mfa_V2_Code_Manager::resolveConfig($this);

        return [
            'variables' => [
                'auto_send_code'  => (int) ($cfg['auto_send'] === 1),
                'active_codes'    => count($entries),
                'earliest_expiry' => $this->earliestExpiryLabel($entries),
            ],
            'tpl' => $this->getTpl('verify'),
        ];
    }

    public function getConfirmForm($params)
    {
        if (isset($params['resend']) && $this->rateLimitAction('Email_Mfa_V2_resend')) {
            $this->sendCode(self::PURPOSE_ACTION);
        }

        $entries = $this->loadActiveCodes(self::PURPOSE_ACTION);
        return [
            'variables' => [
                'auto_send_code'  => 1,
                'active_codes'    => count($entries),
                'earliest_expiry' => $this->earliestExpiryLabel($entries),
                'action_name'     => isset($params['confirm']['name'])
                    ? (string) $params['confirm']['name']
                    : '',
            ],
            'tpl' => $this->getTpl('confirm'),
        ];
    }

    public function getManageForm($params)
    {
        $rows = ORM_Email_Mfa_V2_Code::listActiveForAdmin(
            $this->user_type,
            $this->user_id
        );

        // Apply revoke action before rendering so the view reflects it.
        if (!empty($params['mfa_action']) && $params['mfa_action'] === 'revoke_all') {
            ORM_Email_Mfa_V2_Code::revokeAllForUser($this->user_type, $this->user_id);
            $this->invalidateCache(self::PURPOSE_LOGIN);
            $this->invalidateCache(self::PURPOSE_SETUP);
            $this->invalidateCache(self::PURPOSE_ACTION);
            $this->addInfo('email_mfa_v2_manage_revoke_ok');
            $rows = [];
        }

        return [
            'variables' => [
                'active_codes' => $rows,
                'instructions' => $this->t('email_mfa_v2_manage_title'),
            ],
            'tpl' => $this->getTpl('manage'),
        ];
    }

    // ----- Code generation / cache management -------------------------

    /**
     * Issue a fresh code, persist to DB, update cache, send email.
     * Returns true on success.
     *
     * @param string $purpose
     * @return bool
     */
    public function sendCode($purpose)
    {
        if (!$this->rateLimitAction('Email_Mfa_V2_resend')) {
            return false;
        }
        if (!$this->verifyEmailTemplatesReady($purpose)) {
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        $cfg       = Email_Mfa_V2_Code_Manager::resolveConfig($this);
        $code      = Email_Mfa_V2_Code_Manager::generatePlaintextCode($cfg['code_length']);
        $codeHash  = Email_Mfa_V2_Code_Manager::hashCode($code);
        $cacheKey  = Email_Mfa_V2_Code_Manager::cacheKey($this->user_type, $this->user_id, $purpose);

        $entries = $this->loadActiveCodes($purpose);
        $entries[] = [
            'h' => $codeHash,
            'e' => time() + $cfg['ttl'],
            'u' => false,
        ];

        // LRU eviction: if the working set is now over max_active,
        // drop the oldest (head of array) and revoke its DB row.
        $evicted = [];
        while (count($entries) > $cfg['max_active']) {
            $old = array_shift($entries);
            $evicted[] = $old['h'];
        }
        foreach ($evicted as $hash) {
            ORM_Email_Mfa_V2_Code::revokeByHash($this->user_type, $this->user_id, $hash);
        }

        // Persist the new code to DB *after* eviction so we never
        // exceed max_active on disk.
        $rowId = ORM_Email_Mfa_V2_Code::issue(
            $this->user_type,
            $this->user_id,
            (int) $this->getModuleId(),
            $codeHash,
            $purpose,
            $cfg['ttl'],
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
            isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
        );

        if ($rowId <= 0) {
            $this->logger()->error('Email MFA V2: failed to persist code', [
                'user_type' => $this->user_type,
                'user_id'   => $this->user_id,
                'purpose'   => $purpose,
            ]);
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        if (Email_Mfa_V2_Code_Manager::isCacheEnabled($cfg['cache_backend'])) {
            HBCache::set(
                $cacheKey,
                Email_Mfa_V2_Code_Manager::encodeEntries($entries),
                $cfg['ttl'] + self::CACHE_TTL_BUFFER
            );
        }

        $sent = $this->dispatchEmail($code, $purpose);
        if (!$sent) {
            $this->addError('email_mfa_v2_send_failed');
            return false;
        }

        $this->addInfo('message_sent');
        return true;
    }

    /**
     * Cache-first read of the active codes list. Falls back to DB on
     * cache miss and writes the result back into the cache.
     *
     * @param string $purpose
     * @return array<int, array{h:string, e:int, u:bool}>
     */
    public function loadActiveCodes($purpose)
    {
        $cfg = Email_Mfa_V2_Code_Manager::resolveConfig($this);
        $cacheKey = Email_Mfa_V2_Code_Manager::cacheKey($this->user_type, $this->user_id, $purpose);

        if (Email_Mfa_V2_Code_Manager::isCacheEnabled($cfg['cache_backend'])) {
            $raw = HBCache::get($cacheKey);
            if ($raw !== null && $raw !== false && $raw !== '') {
                $decoded = Email_Mfa_V2_Code_Manager::decodeEntries($raw);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }
        }

        // Cache miss → DB.
        $rows = ORM_Email_Mfa_V2_Code::getActiveForUser($this->user_type, $this->user_id, $purpose);
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'h' => (string) $row->code_hash,
                'e' => strtotime((string) $row->expires_at),
                'u' => !empty($row->used_at),
            ];
        }

        if (Email_Mfa_V2_Code_Manager::isCacheEnabled($cfg['cache_backend']) && !empty($entries)) {
            HBCache::set(
                $cacheKey,
                Email_Mfa_V2_Code_Manager::encodeEntries($entries),
                $cfg['ttl'] + self::CACHE_TTL_BUFFER
            );
        }

        return $entries;
    }

    /**
     * Mark a code as used. Updates both DB and the cached entry list.
     *
     * @param array{h:string, e:int, u:bool} $entry
     * @param string $purpose
     */
    private function markCodeUsed(array $entry, $purpose)
    {
        // DB update by hash + user — atomic in MySQL.
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($this->user_type);
        $userId   = (int) $this->user_id;

        $row = ORM_Email_Mfa_V2_Code::query()
            ->where('code_hash', $entry['h'])
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->first();

        if ($row !== null) {
            $row->used_at = date('Y-m-d H:i:s');
            $row->save();
        }

        // Cache: flip u:true for the matched entry.
        $entries = $this->loadActiveCodes($purpose);
        foreach ($entries as &$candidate) {
            if ($candidate['h'] === $entry['h'] && empty($candidate['u'])) {
                $candidate['u'] = true;
                break;
            }
        }
        unset($candidate);

        $cfg = Email_Mfa_V2_Code_Manager::resolveConfig($this);
        if (Email_Mfa_V2_Code_Manager::isCacheEnabled($cfg['cache_backend'])) {
            HBCache::set(
                Email_Mfa_V2_Code_Manager::cacheKey($this->user_type, $this->user_id, $purpose),
                Email_Mfa_V2_Code_Manager::encodeEntries($entries),
                $cfg['ttl'] + self::CACHE_TTL_BUFFER
            );
        }
    }

    /**
     * Drop the cache entry for one (user, purpose).
     */
    private function invalidateCache($purpose)
    {
        $cfg = Email_Mfa_V2_Code_Manager::resolveConfig($this);
        if (!Email_Mfa_V2_Code_Manager::isCacheEnabled($cfg['cache_backend'])) {
            return;
        }
        $key = Email_Mfa_V2_Code_Manager::cacheKey($this->user_type, $this->user_id, $purpose);
        HBCache::delete($key);
    }

    /**
     * Send the email via HostBill's Mailer. Returns true on success.
     */
    private function dispatchEmail($code, $purpose)
    {
        try {
            $tplName = $this->templateNameFor($purpose);

            $mailer = new Mailer();
            $mailer->template->assign('onetimepassword', $code);
            $mailer->template->assign('browser', Utilities::getBrowser());
            $mailer->template->assign('ip_address', Utilities::REMOTE_ADDR());

            if (Email_Mfa_V2_Code_Manager::normalizeUserType($this->user_type) === 'Admin') {
                $mailer->addAdminFromDB($this->user_id);
            } else {
                $mailer->addClientFromDB($this->user_id, [], true);
            }

            if (!$mailer->loadFromTemplate($tplName, $this->user_type)) {
                $this->logger()->error('Email MFA V2: template not found or not active', [
                    'tplname'   => $tplName,
                    'user_type' => $this->user_type,
                    'user_id'   => $this->user_id,
                    'purpose'   => $purpose,
                ]);
                return false;
            }

            $mailer->fetchTpl();
            $sent = (bool) $mailer->Send();
            if (!$sent) {
                $this->logger()->error('Email MFA V2: Mailer::Send returned false', [
                    'tplname'   => $tplName,
                    'user_type' => $this->user_type,
                    'user_id'   => $this->user_id,
                    'purpose'   => $purpose,
                ]);
            } else {
                $this->logger()->info('Email MFA V2: code dispatched', [
                    'tplname'   => $tplName,
                    'user_type' => $this->user_type,
                    'user_id'   => $this->user_id,
                    'purpose'   => $purpose,
                ]);
            }
            return $sent;
        } catch (\Exception $e) {
            $this->logger()->error('Email MFA V2: dispatch failed', [
                'user_type' => $this->user_type,
                'user_id'   => $this->user_id,
                'purpose'   => $purpose,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function templateNameFor($purpose)
    {
        switch ($purpose) {
            case self::PURPOSE_SETUP:
                return self::EMAIL_TPL_SETUP;
            case self::PURPOSE_ACTION:
                return self::EMAIL_TPL_ACTION;
            case self::PURPOSE_LOGIN:
            default:
                return self::EMAIL_TPL_LOGIN;
        }
    }

    /**
     * Make sure the email template for the given purpose exists and is
     * active. If not, refuse to send.
     */
    private function verifyEmailTemplatesReady($purpose = self::PURPOSE_LOGIN)
    {
        try {
            $tplName = $this->templateNameFor($purpose);
            $tpl = HBLoader::LoadModel('Emailtemplates')->getEmailTemplate($tplName, $this->user_type);
            if (empty($tpl) || empty($tpl['send'])) {
                $this->logger()->warning('Email MFA V2: email template not ready', [
                    'tplname'   => $tplName,
                    'user_type' => $this->user_type,
                    'purpose'   => $purpose,
                    'exists'    => !empty($tpl),
                    'send'      => isset($tpl['send']) ? (int) $tpl['send'] : 0,
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->logger()->warning('Email MFA V2: template check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Should we auto-send a code on the verify form load?
     * Mirrors V1's auto-send-on-login behavior with a 2-minute cooldown
     * stored via HBConfig so we don't spam users.
     */
    private function shouldAutoSendOnLogin($params)
    {
        $cfg = Email_Mfa_V2_Code_Manager::resolveConfig($this);
        if ($cfg['auto_send'] !== 1) {
            return false;
        }

        // If the user already has a fresh code, don't pile another on top.
        $entries = $this->loadActiveCodes(self::PURPOSE_LOGIN);
        foreach ($entries as $entry) {
            if (empty($entry['u']) && (int) $entry['e'] > time() + 60) {
                return false;
            }
        }

        $lastSent = HBConfig::getSetting('email_mfa_v2_login_last_sent');
        if (is_array($lastSent) && isset($lastSent[$this->user_type][$this->user_id])) {
            $ts = (int) $lastSent[$this->user_type][$this->user_id];
            if ($ts > 0 && (time() - $ts) < 120) {
                return false;
            }
        }

        return isset($params['redirect']);
    }

    /**
     * Cleanup HostBill's auto-send state on successful verify, just like
     * V1 did with HBConfig::deleteSetting('login_email').
     */
    private function clearLoginState()
    {
        try {
            HBConfig::deleteSetting('email_mfa_v2_login_last_sent');
        } catch (\Exception $e) {
            // Non-fatal — login_state is a hint, not a gate.
        }
    }

    /**
     * Format the earliest expiry for the "X mã còn lại" hint.
     *
     * @param array<int, array{h:string, e:int, u:bool}> $entries
     * @return string
     */
    private function earliestExpiryLabel(array $entries)
    {
        $earliest = null;
        foreach ($entries as $entry) {
            if (!empty($entry['u'])) {
                continue;
            }
            $ts = (int) $entry['e'];
            if ($earliest === null || $ts < $earliest) {
                $earliest = $ts;
            }
        }
        if ($earliest === null) {
            return '';
        }
        return date('H:i', $earliest);
    }

    // ----- install helpers --------------------------------------------

    private function setupTable()
    {
        // Bootstrap the hb_email_mfa_codes table by executing the
        // bundled install.sql — same pattern HostBill's locations_v2
        // uses (see Core_examp_hostbill/OtherModule/locationsv2/
        // class.locations_v2.php:51-62). Statements are separated
        // by the literal token "######" and run via $this->db->exec().
        $sqlFile = __DIR__ . DIRECTORY_SEPARATOR . 'install.sql';
        if (!is_file($sqlFile) || !is_readable($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return;
        }
        try {
            foreach (explode('######', $sql) as $i => $query) {
                $query = trim($query);
                if ($query === '') {
                    continue;
                }
                $this->db->exec($query);
            }
        } catch (\Exception $e) {
            // Swallow and log — admin can re-run install from
            // Apps & Integrations if the schema is already present.
            if (method_exists($this, 'logger')) {
                $this->logger()->warning('Email MFA V2: install.sql failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function setupEmails()
    {
        // Build richer templates than the built-in email_mfa: include
        // brand name, OTP expiry window, browser fingerprint, and IP
        // so the user can confirm the request was theirs before
        // entering the code. Smarty variables match what
        // `dispatchEmail()` assigns at send time.
        $bn        = HBConfig::getConfig('BusinessName');
        $bn        = is_string($bn) && $bn !== '' ? $bn : 'HostBill';
        $codeTtl   = (int) $this->_getConfiguration('Code TTL (seconds)');
        $codeMins  = $codeTtl > 0 ? max(1, (int) round($codeTtl / 60)) : 20;
        $supportUrl = HBConfig::getConfig('InstallURL');

        $mails = [
            self::EMAIL_TPL_LOGIN => [
                'subject'  => '[' . $bn . '] Your login verification code',
                'message'  => "Hello,\n\n"
                    . "We received a login attempt to your {$bn} account.\n\n"
                    . "Your one-time verification code is:\n\n"
                    . "    {$onetimepassword}\n\n"
                    . "This code is valid for {$codeMins} minute(s) and can be used multiple times within that window if you also re-request a code.\n\n"
                    . "Request details:\n"
                    . "    Browser : {$browser}\n"
                    . "    IP      : {$ip_address}\n\n"
                    . "If you did not try to log in, please ignore this email or contact support at {$supportUrl}.\n\n"
                    . "— {$bn} security team",
            ],
            self::EMAIL_TPL_SETUP => [
                'subject'  => '[' . $bn . '] Confirm your MFA setup',
                'message'  => "Hello,\n\n"
                    . "To finish enabling two-factor authentication (email-based) on your {$bn} account, enter the following code on the setup screen:\n\n"
                    . "    {$onetimepassword}\n\n"
                    . "This code is valid for {$codeMins} minute(s).\n\n"
                    . "Request details:\n"
                    . "    Browser : {$browser}\n"
                    . "    IP      : {$ip_address}\n\n"
                    . "If you did not initiate this, please secure your account immediately.\n\n"
                    . "— {$bn} security team",
            ],
            self::EMAIL_TPL_ACTION => [
                'subject'  => '[' . $bn . '] Confirm this sensitive action',
                'message'  => "Hello,\n\n"
                    . "To confirm the following action on your {$bn} account, enter the verification code:\n\n"
                    . "    Action : {$action_name}\n"
                    . "    Code   : {$onetimepassword}\n\n"
                    . "This code is valid for {$codeMins} minute(s).\n\n"
                    . "Request details:\n"
                    . "    Browser : {$browser}\n"
                    . "    IP      : {$ip_address}\n\n"
                    . "If you did not initiate this, please ignore and contact support.\n\n"
                    . "— {$bn} security team",
            ],
        ];

        try {
            $tpl = HBLoader::LoadModel('Emailtemplates');
            foreach (['Admin', 'Client'] as $who) {
                foreach ($mails as $type => $params) {
                    $existing = $tpl->getEmailTemplate($type, $who);
                    if (empty($existing)) {
                        // Brand new — create it.
                        $tpl->addEmail([
                            'subject'    => $params['subject'],
                            'message'    => $params['message'],
                            'altmessage' => $params['message'],
                            'group'      => 'General',
                            'for'        => $who,
                        ], false, $type);
                        $this->logger()->info('Email MFA V2: created email template', [
                            'tplname' => $type,
                            'for'     => $who,
                        ]);
                    } else {
                        // Already exists — auto-enable if admin had
                        // disabled it, so the module "just works"
                        // after a fresh install. Don't overwrite the
                        // body — admins may have customized it.
                        //
                        // The Emailtemplates model doesn't expose an
                        // update() method in current HostBill core,
                        // so we run the UPDATE directly via PDO with
                        // a prepared statement.
                        if (empty($existing['send'])) {
                            try {
                                $stmt = $this->db->prepare(
                                    'UPDATE hb_email_templates SET send = 1 WHERE tplname = :tplname AND `for` = :for'
                                );
                                $stmt->execute([
                                    ':tplname' => $type,
                                    ':for'     => $who,
                                ]);
                                $this->logger()->info('Email MFA V2: auto-enabled disabled template', [
                                    'tplname'   => $type,
                                    'for'       => $who,
                                    'rowcount'  => $stmt->rowCount(),
                                ]);
                            } catch (\Exception $inner) {
                                $this->logger()->warning('Email MFA V2: auto-enable failed', [
                                    'tplname' => $type,
                                    'for'     => $who,
                                    'error'   => $inner->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger()->warning('Email MFA V2: setupEmails failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function setupLanguages()
    {
        // Seed every EN string into the default HostBill language, then
        // overwrite the VI translations on a per-key basis. Using the
        // LangEdit helper (the same path HostBill's built-in powerdns
        // module uses) means we don't have to hand-roll SQL or worry
        // about PDO::escape() — LangEdit handles the encoding and
        // section routing internally.
        try {
            foreach ($this->lang['english'] as $key => $enValue) {
                LangEdit::addTranslation((string) $key, (string) $enValue);
            }
            if (!empty($this->lang['vietnamese']) && is_array($this->lang['vietnamese'])) {
                $viOverrides = [];
                foreach ($this->lang['vietnamese'] as $key => $viValue) {
                    $viOverrides[(string) $key] = (string) $viValue;
                }
                if (!empty($viOverrides)) {
                    LangEdit::addTranslations($viOverrides, 'vietnamese');
                }
            }
        } catch (\Exception $e) {
            $this->logger()->warning('Email MFA V2: setupLanguages failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Translate a key through the module's $lang array. Falls back to
     * the english string, then to the key itself, so callers never
     * see "undefined variable" warnings.
     */
    private function t($key)
    {
        if (isset($this->lang['english'][$key])) {
            return $this->lang['english'][$key];
        }
        return $key;
    }
}
