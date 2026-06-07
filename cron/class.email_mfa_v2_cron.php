<?php

if (!defined('MAINDIR')) {
    die('Access denied');
}

/**
 * Email MFA V2 — hourly cron controller.
 *
 * Two responsibilities:
 *
 *   1. Purge used/expired rows older than 24 h from hb_email_mfa_codes.
 *      Keeps the table bounded and removes any code that an attacker
 *      might have captured while it was still in the window.
 *
 *   2. Optionally warm the cache for users with active codes so the
 *      next verify() call doesn't pay the DB roundtrip.
 *
 * HostBill cron schedule for this controller: 0 * * * * (every hour).
 */
class Email_Mfa_V2_Cron_Controller
{
    /** @var Email_Mfa_V2|null */
    public $module;

    public function __construct()
    {
        try {
            $this->module = HBLoader::LoadModule('Other/email_mfa_v2');
        } catch (\Exception $e) {
            $this->module = null;
        }
    }

    public function call_Hourly()
    {
        $result = ORM_Email_Mfa_V2_Code::cronCleanup();
        $message = sprintf(
            'Email MFA V2 hourly cleanup: %d used, %d expired rows purged.',
            $result['used'],
            $result['expired']
        );

        // Echo so the cron log captures the summary line.
        echo $message;
        return $message;
    }
}
