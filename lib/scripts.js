/*
 * Email MFA V2 — client-side helpers.
 *
 * Mirrors HostBill's built-in email_mfa/lib/scripts.js:
 *   - The "Send / Resend" button is the only interactive element.
 *   - It POSTs the current URL with &resend=1; the server decides
 *     whether to actually send (rate-limited).
 *   - On success the button flips into a 10-second disabled state
 *     so the user doesn't spam the SMTP server.
 *
 * No business logic lives here — the server is the source of truth.
 */

var MFAEMAILV2 = {
    send: function (btn, url) {
        var button = $(btn);
        var originalHtml = button.html();
        var resend = button.attr('data-resend') || '0';

        url += '&resend=' + resend;

        var timeout = 10000; // 10s
        var btnId = '#mfa-verify-btn';
        var targetButton = button;

        targetButton.prop('disabled', true);

        $.ajax({
            url: url,
            type: 'POST',
            data: {},
            success: function (response) {
                var userType = $('#user_type').val();

                if (userType === 'Client') {
                    if (typeof parse_response === 'function') {
                        parse_response(response);
                    }
                }

                if (response && response.indexOf('"INFO":[]') === -1) {
                    if (resend !== '1') {
                        targetButton
                            .attr('data-resend', '1')
                            .html(originalHtml)
                            .find('[data-state="visible"]').hide().end()
                            .find('[data-state="hidden"]').show();
                    }
                    startButtonCountdown(btnId, timeout / 1000);
                }
            },
            error: function () {
                targetButton.prop('disabled', false);
            }
        });
    }
};

$(document).ready(function () {
    var button = $('#mfa-verify-btn');
    if (button.length === 0) {
        return;
    }
    var resend = button.attr('data-resend') || '0';

    if (resend === '1') {
        button.prop('disabled', true);
        setTimeout(function () {
            button.prop('disabled', false);
        }, 10000);
        startButtonCountdown('#mfa-verify-btn', 10);
    }

    $(document).on('click', '#mfa-verify-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $('#2faform').show();
        MFAEMAILV2.send(this, window.location.href);
    });

    $(document).on('click', '#mfa-enable-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var moduleId = $(this).attr('data-module-id');
        if (!moduleId) {
            return;
        }
        MFAEMAILV2.send(this, '?cmd=mfa&action=enable&id=' + moduleId);
    });
});

function startButtonCountdown(buttonSelector, durationSeconds) {
    var btn = $(buttonSelector);
    if (btn.length === 0) {
        return;
    }
    var timer = btn.find('.btn-timer');

    var seconds = durationSeconds;
    btn.prop('disabled', true);
    timer.text('(' + seconds + ')');

    var interval = setInterval(function () {
        seconds--;
        timer.text('(' + seconds + ')');
        if (seconds <= 0) {
            clearInterval(interval);
            timer.text('');
            btn.prop('disabled', false);
        }
    }, 1000);
}
