<?php

namespace Other\email_mfa_v2\ORM;

use Illuminate\Database\Eloquent\Model;
use Email_Mfa_V2_Code_Manager;

/**
 * ORM model for the hb_email_mfa_codes table.
 *
 * Each row is a single OTP issued to a user. The plaintext code is never
 * stored — only the SHA-256 hash. The cron job purges used/expired rows
 * older than 24h.
 *
 * Backed by Eloquent (Illuminate\Database\Eloquent\Model) because that
 * is the actual ORM layer HostBill ships in `Core_examp_hostbill/OtherModule/
 * locationsv2/orm/` and `module_dev_hostbill/Other/hitechsearch/orm/`. There
 * is no `HBorm` class in HostBill core — the earlier draft of this file
 * assumed one and crashed on `Class "HBorm" not found`. The fix is to
 * extend Eloquent directly and use the query builder.
 *
 * The schema is created in `install.sql` (next to this file) and run by
 * the module's `install()` method, mirroring how locations_v2 bootstraps
 * its tables. We do NOT use Eloquent migrations because HostBill's
 * module lifecycle predates Laravel migrations in the module space.
 *
 * @see Email_Mfa_V2
 */
class ORM_Email_Mfa_V2_Code extends Model
{
    /** @var string */
    protected $table = 'hb_email_mfa_codes';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var bool */
    public $timestamps = false;

    /** @var array<string, string> mass-assignable columns */
    protected $fillable = [
        'user_type',
        'user_id',
        'module_id',
        'code_hash',
        'purpose',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * Eloquent casts. Useful so callers can compare DateTime strings
     * to native DateTimeImmutable if they want.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
        'user_id'    => 'integer',
        'module_id'  => 'integer',
    ];

    /**
     * Pull every still-valid code for one (user_type, user_id, purpose).
     * Result is ordered by `created_at ASC` so callers can keep a stable
     * "oldest first" view (for LRU eviction and "X mã còn lại" labels).
     *
     * @param string $userType  'Admin' or 'Client'
     * @param int    $userId
     * @param string $purpose   'login' | 'setup' | 'action'
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getActiveForUser($userType, $userId, $purpose)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;
        $purpose  = in_array($purpose, ['login', 'setup', 'action'], true) ? $purpose : 'login';

        return self::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Insert a new code row. Returns the inserted id.
     *
     * @param string      $userType
     * @param int         $userId
     * @param int         $moduleId
     * @param string      $codeHash   SHA-256 hex of plaintext
     * @param string      $purpose
     * @param int         $ttlSeconds
     * @param string|null $ip
     * @param string|null $ua
     * @return int inserted id (0 on failure)
     */
    public static function issue($userType, $userId, $moduleId, $codeHash, $purpose, $ttlSeconds, $ip = null, $ua = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $purpose  = in_array($purpose, ['login', 'setup', 'action'], true) ? $purpose : 'login';
        $ttl      = max(60, (int) $ttlSeconds);
        $expires  = date('Y-m-d H:i:s', time() + $ttl);

        $model = new self();
        $model->user_type  = $userType;
        $model->user_id    = (int) $userId;
        $model->module_id  = (int) $moduleId;
        $model->code_hash  = (string) $codeHash;
        $model->purpose    = $purpose;
        $model->expires_at = $expires;
        $model->used_at    = null;
        $model->ip_address = $ip !== null ? substr((string) $ip, 0, 45) : null;
        $model->user_agent = $ua !== null ? substr((string) $ua, 0, 255) : null;
        $model->created_at = date('Y-m-d H:i:s');

        $saved = $model->save();
        return $saved ? (int) $model->getKey() : 0;
    }

    /**
     * Stamp `used_at` on a single row. Returns true on success.
     * Returns false if the row was already used (race between two
     * parallel verifiers) — the caller treats that as invalid.
     *
     * @param int $id
     * @return bool
     */
    public static function markUsed($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $affected = self::query()
            ->where('id', $id)
            ->whereNull('used_at')
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        return $affected === 1;
    }

    /**
     * Delete a single code by hash for this user. Used by LRU eviction
     * when the user has more than max_active_codes codes pending.
     *
     * @return int affected rows
     */
    public static function revokeByHash($userType, $userId, $codeHash)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;

        return (int) self::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('code_hash', $codeHash)
            ->whereNull('used_at')
            ->delete();
    }

    /**
     * Bulk-revoke every active code for this user (used by disable()).
     *
     * @return int affected rows
     */
    public static function revokeAllForUser($userType, $userId)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;

        return (int) self::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->delete();
    }

    /**
     * Cron cleanup. Two passes: used-and-old, then expired-and-old.
     * The 24h grace window lets the admin "manage" view still list recent
     * activity for support tickets.
     *
     * @return array{used:int, expired:int}
     */
    public static function cronCleanup()
    {
        $cutoff = date('Y-m-d H:i:s', time() - 86400);

        $used = (int) self::query()
            ->whereNotNull('used_at')
            ->where('used_at', '<', $cutoff)
            ->delete();

        $expired = (int) self::query()
            ->where('expires_at', '<', $cutoff)
            ->delete();

        return ['used' => $used, 'expired' => $expired];
    }

    /**
     * List active codes for the "manage" page (read-only).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function listActiveForAdmin($userType, $userId, $purpose = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;

        $q = self::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'ASC');

        if ($purpose !== null && in_array($purpose, ['login', 'setup', 'action'], true)) {
            $q->where('purpose', $purpose);
        }

        return $q->get();
    }

    /**
     * Count active (un-used, un-expired) codes for the given user,
     * optionally filtered by purpose.
     *
     * @param string $userType
     * @param int    $userId
     * @param string|null $purpose
     * @return int
     */
    public static function countActive($userType, $userId, $purpose = null)
    {
        $userType = Email_Mfa_V2_Code_Manager::normalizeUserType($userType);
        $userId   = (int) $userId;

        $q = self::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'));

        if ($purpose !== null && in_array($purpose, ['login', 'setup', 'action'], true)) {
            $q->where('purpose', $purpose);
        }

        return (int) $q->count();
    }
}
