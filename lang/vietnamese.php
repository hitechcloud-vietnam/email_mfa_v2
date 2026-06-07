<?php
/**
 * Vietnamese language overrides for Email MFA V2.
 *
 * The primary strings live in $lang['vietnamese'] inside class.email_mfa_v2.php
 * so the module is self-contained — this file is loaded by HostBill's
 * lang system only if an admin wants to override specific keys.
 */
$lang['email_mfa_v2'] = [
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
];
