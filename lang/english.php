<?php
/**
 * English language overrides for Email MFA V2.
 *
 * The primary strings live in $lang['english'] inside class.email_mfa_v2.php
 * so the module is self-contained — this file is loaded by HostBill's
 * lang system only if an admin wants to override specific keys.
 *
 * To override, copy this file into your HostBill admin → Settings →
 * Email templates / Translations, and edit the values.
 */
$lang['email_mfa_v2'] = [
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
];
