<?php

if (!defined('MAINDIR')) {
    die('Access denied');
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'code_manager.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'class.email_mfa_v2.php';

/**
 * Email MFA V2 — REST/JSON API controller.
 *
 * HostBill auto-discovers this controller via the `api/` directory and
 * routes requests to its public methods. Each method is one HTTP
 * endpoint; the URL pattern is `?api=email_mfa_v2/<method>`.
 *
 * Endpoints:
 *
 *   GET  ?api=email_mfa_v2/status&user_type=Client&user_id=42
 *     → { success, enrolled, active_codes, purposes: {login, setup, action} }
 *
 *   POST ?api=email_mfa_v2/send
 *        user_type, user_id, purpose=login|setup|action (default login)
 *     → { success: true } | { success: false, message }
 *
 *   POST ?api=email_mfa_v2/verify
 *        user_type, user_id, code, purpose
 *     → { success: true } | { success: false, reason }
 *
 *   GET  ?api=email_mfa_v2/list&user_type=...&user_id=...
 *     → { success, codes: [ {id, purpose, created_at, expires_at, ip_address, hash_prefix} ] }
 *
 *   POST ?api=email_mfa_v2/revokeall
 *        user_type, user_id
 *     → { success, revoked: N }
 *
 *   POST ?api=email_mfa_v2/disable
 *        user_type, user_id
 *     → { success: true }
 *
 * Authorization:
 *   - All endpoints require a valid HostBill API call. HostBill's
 *     API auth layer (API key / IP allowlist / admin session) is
 *     enforced before this controller is invoked, so we only need
 *     to make sure `Controller::isApi()` is true.
 *   - The actual "can this admin manage this client's MFA?" check
 *     is delegated to the calling admin's role — we do not
 *     re-implement RBAC here.
 *
 * @see Email_Mfa_V2
 * @see HBController
 */
class email_mfa_v2_controller extends HBController
{
    /**
     * @var Email_Mfa_V2 The loaded module instance
     */
    public $module;

    public function __construct()
    {
        parent::__construct();
        $this->module = HBLoader::LoadModule('Other/email_mfa_v2');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Refuse requests that didn't come through the API layer.
     * Mirrors the passkeyv2_controller guard pattern.
     */
    private function requireApi()
    {
        if (!Controller::isApi()) {
            return $this->fail('api_only', 400);
        }
        return null;
    }

    /**
     * Read user_type / user_id from $params with safe defaults.
     * Returns [userType, userId] or null if user_id is invalid.
     *
     * @param array $params
     * @return array{0:string,1:int}|null
     */
    private function resolveTargetUser($params)
    {
        $userType = isset($params['user_type']) ? (string) $params['user_type'] : '';
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);

        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $clientId = isset($params['client_id']) ? (int) $params['client_id'] : 0;
        if ($userId <= 0 && $clientId > 0) {
            $userId = $clientId;
        }
        if ($userId <= 0) {
            return null;
        }
        return [$userType, $userId];
    }

    private function resolvePurpose($params, $default = Email_Mfa_V2::PURPOSE_LOGIN)
    {
        $purpose = isset($params['purpose']) ? (string) $params['purpose'] : $default;
        if (!in_array($purpose, [Email_Mfa_V2::PURPOSE_LOGIN, Email_Mfa_V2::PURPOSE_SETUP, Email_Mfa_V2::PURPOSE_ACTION], true)) {
            $purpose = $default;
        }
        return $purpose;
    }

    /**
     * Standard success response.
     *
     * @param array $data
     * @param int   $httpStatus
     * @return array
     */
    private function ok(array $data = [], $httpStatus = 200)
    {
        return $this->json([
            'success' => true,
        ] + $data, $httpStatus);
    }

    /**
     * Standard failure response.
     *
     * @param string $reason
     * @param int    $httpStatus
     * @param array  $extra
     * @return array
     */
    private function fail($reason, $httpStatus = 400, array $extra = [])
    {
        return $this->json([
            'success' => false,
            'reason'  => (string) $reason,
        ] + $extra, $httpStatus);
    }

    /**
     * JSON output helper. Sends Content-Type + status, echoes body,
     * returns the array for symmetry with controller style.
     */
    private function json(array $data, $httpStatus = 200)
    {
        if (!headers_sent()) {
            http_response_code((int) $httpStatus);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $data;
    }

    // ----------------------------------------------------------------
    // Endpoints
    // ----------------------------------------------------------------

    /**
     * GET ?api=email_mfa_v2/status
     * Read-only enrollment + active-codes summary for one user.
     */
    public function status($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;

        return $this->ok([
            'user_type'    => $userType,
            'user_id'      => $userId,
            'enrolled'     => (bool) $this->module->apiGetEnrolled($userType, $userId),
            'active_codes' => (int) $this->module->apiCountActiveCodes($userType, $userId),
            'purposes'     => [
                Email_Mfa_V2::PURPOSE_LOGIN  => (int) $this->module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_LOGIN),
                Email_Mfa_V2::PURPOSE_SETUP  => (int) $this->module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_SETUP),
                Email_Mfa_V2::PURPOSE_ACTION => (int) $this->module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_ACTION),
            ],
        ]);
    }

    /**
     * POST ?api=email_mfa_v2/send
     * Issue and email a new code to the target user.
     */
    public function send($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;
        $purpose = $this->resolvePurpose($params);

        $result = $this->module->apiSendCode($userType, $userId, $purpose);
        if (!empty($result['success'])) {
            return $this->ok([
                'user_type' => $userType,
                'user_id'   => $userId,
                'purpose'   => $purpose,
            ]);
        }
        return $this->fail(isset($result['message']) ? $result['message'] : 'send_failed', 502, [
            'user_type' => $userType,
            'user_id'   => $userId,
            'purpose'   => $purpose,
        ]);
    }

    /**
     * POST ?api=email_mfa_v2/verify
     * Verify a code submitted by the target user.
     * Note: this endpoint does not itself log the user in; it returns
     * success/failure so the calling client can proceed with its own
     * login flow.
     */
    public function verify($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;
        $code = isset($params['code']) ? (string) $params['code'] : '';
        $purpose = $this->resolvePurpose($params);

        $result = $this->module->apiVerify($userType, $userId, $code, $purpose);
        if (!empty($result['success'])) {
            return $this->ok([
                'user_type' => $userType,
                'user_id'   => $userId,
                'purpose'   => $purpose,
            ]);
        }
        return $this->fail(isset($result['reason']) ? $result['reason'] : 'invalid_code', 401, [
            'user_type' => $userType,
            'user_id'   => $userId,
        ]);
    }

    /**
     * GET ?api=email_mfa_v2/list
     * List active codes (read-only, hash-prefix only) for the target user.
     */
    public function listactive($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;

        return $this->ok([
            'user_type' => $userType,
            'user_id'   => $userId,
            'codes'     => $this->module->apiListActiveCodes($userType, $userId),
        ]);
    }

    /**
     * POST ?api=email_mfa_v2/revokeall
     * Revoke all active codes for the target user. Enrollment is kept.
     */
    public function revokeall($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;

        $revoked = (int) $this->module->apiRevokeAllCodes($userType, $userId);
        return $this->ok([
            'user_type' => $userType,
            'user_id'   => $userId,
            'revoked'   => $revoked,
        ]);
    }

    /**
     * POST ?api=email_mfa_v2/disable
     * Disable MFA entirely for the target user.
     */
    public function disable($params)
    {
        if ($err = $this->requireApi()) {
            return $err;
        }
        $target = $this->resolveTargetUser($params);
        if ($target === null) {
            return $this->fail('missing_user_id', 400);
        }
        list($userType, $userId) = $target;

        $ok = (bool) $this->module->apiDisableForUser($userType, $userId);
        if (!$ok) {
            return $this->fail('disable_failed', 500, [
                'user_type' => $userType,
                'user_id'   => $userId,
            ]);
        }
        return $this->ok([
            'user_type' => $userType,
            'user_id'   => $userId,
        ]);
    }
}
