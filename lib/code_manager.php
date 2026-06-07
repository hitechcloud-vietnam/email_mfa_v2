<?php

if (!defined('MAINDIR')) {
    die('Access denied');
}

/**
 * Helper functions for the Email MFA V2 module.
 *
 * Centralizes:
 *   - Cache key construction
 *   - Plaintext code generation (delegates to HostBill Utilities)
 *   - SHA-256 hashing with timing-attack-safe compare
 *   - Cache serialization helpers (JSON encoding for HBCache::set)
 *
 * None of these methods touch the DB directly; the main module class
 * owns DB writes via ORM_Email_Mfa_V2_Code.
 */
class Email_Mfa_V2_Code_Manager
{
    /**
     * Cache key for a user's active codes of a given purpose.
     *
     * @param string $userType  'Admin' or 'Client'
     * @param int    $userId
     * @param string $purpose   'login' | 'setup' | 'action'
     * @return string
     */
    public static function cacheKey($userType, $userId, $purpose)
    {
        $userType = self::normalizeUserType($userType);
        $userId   = (int) $userId;
        $purpose  = in_array($purpose, ['login', 'setup', 'action'], true) ? $purpose : 'login';

        return 'emfa:u:' . $userType . ':' . $userId . ':' . $purpose;
    }

    /**
     * Normalize a user_type string into 'Admin' or 'Client'.
     *
     * Note: we do NOT rely on HostBill's `ADMIN` / `CLIENT` global
     * constants. The `ADMIN` constant is conditionally defined in
     * hbf/settings/constants.php and only when that file has been
     * loaded ahead of ours — which is not guaranteed for module
     * code that runs inside the request lifecycle. `CLIENT` isn't
     * defined as a global alias at all (only as the namespaced
     * `HBC\TYPE\CLIENT`). Comparing against the literal string
     * 'Admin' avoids both problems.
     *
     * @param string|null $userType
     * @return string  'Admin' or 'Client'
     */
    public static function normalizeUserType($userType)
    {
        if (is_string($userType)) {
            $lower = strtolower($userType);
            if ($lower === 'admin') {
                return 'Admin';
            }
        }
        return 'Client';
    }

    /**
     * Generate a fresh numeric OTP of $length digits. Clamped to [4, 15]
     * to match HostBill's historical email-verification limit.
     *
     * @param int $length
     * @return string
     */
    public static function generatePlaintextCode($length = 6)
    {
        $length = max(4, min(15, (int) $length));

        // Utilities::generatePassword($length, $useLowercase, $useNumbers, $useSpecial, $useUppercase)
        // We want digits only → useNumbers = true, rest false.
        return Utilities::generatePassword($length, false, true, false, false);
    }

    /**
     * SHA-256 hex digest of the plaintext code. Always 64 chars.
     *
     * @param string $plaintext
     * @return string
     */
    public static function hashCode($plaintext)
    {
        return hash('sha256', (string) $plaintext);
    }

    /**
     * Timing-attack-safe comparison. Always pass the user-supplied code
     * second so the constant-time compare is the one that "leaks" a hint.
     *
     * @param string $expected  hash from storage
     * @param string $supplied  plaintext from the user, hashed by caller
     * @return bool
     */
    public static function hashEquals($expected, $supplied)
    {
        return hash_equals((string) $expected, (string) $supplied);
    }

    /**
     * Serialize an array of cache entries to a JSON string.
     * Each entry: {h: 64hex, e: unix_ts, u: bool}.
     *
     * @param array<int, array{h:string, e:int, u:bool}> $entries
     * @return string
     */
    public static function encodeEntries(array $entries)
    {
        $clean = [];
        foreach ($entries as $entry) {
            if (!isset($entry['h'], $entry['e'], $entry['u'])) {
                continue;
            }
            $clean[] = [
                'h' => (string) $entry['h'],
                'e' => (int) $entry['e'],
                'u' => (bool) $entry['u'],
            ];
        }
        return json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode HBCache payload back into the entries array. Returns [] on
     * any decode failure rather than throwing — cache misses must not
     * break the login flow.
     *
     * @param mixed $raw
     * @return array<int, array{h:string, e:int, u:bool}>
     */
    public static function decodeEntries($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (!isset($entry['h'], $entry['e'], $entry['u'])) {
                continue;
            }
            $out[] = [
                'h' => (string) $entry['h'],
                'e' => (int) $entry['e'],
                'u' => (bool) $entry['u'],
            ];
        }
        return $out;
    }

    /**
     * Resolve module config with safe defaults.
     *
     * @param Email_Mfa_V2 $module
     * @return array{code_length:int, ttl:int, max_active:int, auto_send:int, cache_backend:string}
     */
    public static function resolveConfig(Email_Mfa_V2 $module)
    {
        $codeLen = (int) $module->_getConfiguration('Code Length');
        if ($codeLen < 4 || $codeLen > 15) {
            $codeLen = 6;
        }

        $ttl = (int) $module->_getConfiguration('Code TTL (seconds)');
        if ($ttl < 60) {
            $ttl = 1200;
        }

        $max = (int) $module->_getConfiguration('Max Active Codes per User');
        if ($max < 1) {
            $max = 5;
        }
        if ($max > 50) {
            $max = 50; // hard cap to prevent abuse
        }

        $auto = (int) $module->_getConfiguration('Auto-send code after login') === 1 ? 1 : 0;

        $backend = (string) $module->_getConfiguration('Cache Backend');
        if ($backend !== 'db_only') {
            $backend = 'auto';
        }

        return [
            'code_length'   => $codeLen,
            'ttl'           => $ttl,
            'max_active'    => $max,
            'auto_send'     => $auto,
            'cache_backend' => $backend,
        ];
    }

    /**
     * Decide whether the active-codes cache should be used at all.
     * Returning false here forces the module to read straight from DB,
     * which is slower but works on installs where HBCache is unhealthy.
     *
     * @param string $backend   'auto' | 'db_only'
     * @return bool
     */
    public static function isCacheEnabled($backend)
    {
        return $backend === 'auto';
    }
}
