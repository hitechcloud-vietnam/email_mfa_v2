<?php

if (!defined('MAINDIR')) {
    die('Access denied');
}

/**
 * Email MFA V2 — HostBill UserApi route handler.
 *
 * This class backs the JSON manifest at
 * `api/email_mfa_v2_apiroutes.json` and is wired into HostBill's
 * UserApi system. The dispatcher reads the manifest, resolves a
 * method on this class per route, and serializes the returned array
 * to JSON.
 *
 * Routes (full URL pattern shown):
 *
 *   GET  /email_mfa_v2/status/{user_type}/{user_id}
 *   POST /email_mfa_v2/send
 *   POST /email_mfa_v2/verify
 *   GET  /email_mfa_v2/list/{user_type}/{user_id}
 *   POST /email_mfa_v2/revokeall
 *   POST /email_mfa_v2/disable
 *
 * Method names below match the `handle` field in the manifest.
 * Arguments come from URL path placeholders (inurl: true) in the
 * order declared in the manifest; POST body fields are merged in by
 * UserApi::dispatch as the second argument.
 *
 * Authorization: all routes set `auth: true` in the manifest, so
 * HostBill's UserApi layer enforces API key + admin session before
 * this class is invoked. The class itself does not re-implement
 * RBAC — it trusts the caller is allowed to manage the target
 * user's MFA.
 *
 * Output: every method returns a plain associative array with
 * `{success: bool, reason?: string, ...}`. UserApi serializes it
 * to JSON. No plaintext code_hash is ever serialized.
 *
 * Error responses: there is no `UserApi::error()` helper in the
 * real UserApi class (see Core_examp_hostbill/Other/userapi/
 * class.userapi.php — only `dispatch`, `route`, `view`, `module`,
 * etc. exist). So we return inline arrays with `success: false`
 * and a `reason` field; the caller's HTTP layer reads that field.
 *
 * @see \UserApi
 * @see Email_Mfa_V2
 */
class email_mfa_v2_apiroutes
{
    /**
     * Build a structured error response array.
     *
     * @param string $reason
     * @param array  $extra
     * @return array
     */
    private function errorResponse($reason, array $extra = [])
    {
        return ['success' => false, 'reason' => (string) $reason] + $extra;
    }

    /**
     * Resolve the Email MFA V2 module instance lazily and uniformly.
     */
    private function module()
    {
        return HBLoader::LoadModule('Other/email_mfa_v2');
    }

    /**
     * GET /email_mfa_v2/status/{user_type}/{user_id}
     *
     * @param string $userType
     * @param int    $userId
     * @return array
     */
    public function getStatus($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }

        $module = $this->module();
        return [
            'success'      => true,
            'user_type'    => $userType,
            'user_id'      => $userId,
            'enrolled'     => (bool) $module->apiGetEnrolled($userType, $userId),
            'active_codes' => (int) $module->apiCountActiveCodes($userType, $userId),
            'purposes'     => [
                Email_Mfa_V2::PURPOSE_LOGIN  => (int) $module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_LOGIN),
                Email_Mfa_V2::PURPOSE_SETUP  => (int) $module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_SETUP),
                Email_Mfa_V2::PURPOSE_ACTION => (int) $module->apiCountActiveCodes($userType, $userId, Email_Mfa_V2::PURPOSE_ACTION),
            ],
        ];
    }

    /**
     * POST /email_mfa_v2/send
     * Second argument is the merged POST body (URL placeholders + body).
     *
     * @param string|null $userType
     * @param int|null    $userId
     * @param string|null $purpose
     * @return array
     */
    public function sendCode($userType = null, $userId = null, $purpose = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }

        $result = $this->module()->apiSendCode($userType, $userId, (string) $purpose);
        if (!empty($result['success'])) {
            return [
                'success'   => true,
                'user_type' => $userType,
                'user_id'   => $userId,
                'purpose'   => (string) $purpose,
            ];
        }
        return $this->errorResponse(
            isset($result['message']) ? $result['message'] : 'send_failed',
            ['user_type' => $userType, 'user_id' => $userId, 'purpose' => (string) $purpose]
        );
    }

    /**
     * POST /email_mfa_v2/verify
     *
     * @param string|null $userType
     * @param int|null    $userId
     * @param string|null $code
     * @param string|null $purpose
     * @return array
     */
    public function verifyCode($userType = null, $userId = null, $code = null, $purpose = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }
        if (!is_string($code) || trim($code) === '') {
            return $this->errorResponse('missing_code');
        }

        $result = $this->module()->apiVerify($userType, $userId, (string) $code, (string) $purpose);
        if (!empty($result['success'])) {
            return [
                'success'   => true,
                'user_type' => $userType,
                'user_id'   => $userId,
                'purpose'   => (string) $purpose,
            ];
        }
        return $this->errorResponse(
            isset($result['reason']) ? $result['reason'] : 'invalid_code',
            ['user_type' => $userType, 'user_id' => $userId]
        );
    }

    /**
     * GET /email_mfa_v2/list/{user_type}/{user_id}
     *
     * @param string $userType
     * @param int    $userId
     * @return array
     */
    public function listActive($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }

        return [
            'success'   => true,
            'user_type' => $userType,
            'user_id'   => $userId,
            'codes'     => $this->module()->apiListActiveCodes($userType, $userId),
        ];
    }

    /**
     * POST /email_mfa_v2/revokeall
     *
     * @param string|null $userType
     * @param int|null    $userId
     * @return array
     */
    public function revokeAll($userType = null, $userId = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }

        $revoked = (int) $this->module()->apiRevokeAllCodes($userType, $userId);
        return [
            'success'   => true,
            'user_type' => $userType,
            'user_id'   => $userId,
            'revoked'   => $revoked,
        ];
    }

    /**
     * POST /email_mfa_v2/disable
     *
     * @param string|null $userType
     * @param int|null    $userId
     * @return array
     */
    public function disableForUser($userType = null, $userId = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        if ($userId <= 0) {
            return $this->errorResponse('missing_user_id');
        }

        $ok = (bool) $this->module()->apiDisableForUser($userType, $userId);
        if (!$ok) {
            return $this->errorResponse('disable_failed', ['user_type' => $userType, 'user_id' => $userId]);
        }
        return [
            'success'   => true,
            'user_type' => $userType,
            'user_id'   => $userId,
        ];
    }
}
