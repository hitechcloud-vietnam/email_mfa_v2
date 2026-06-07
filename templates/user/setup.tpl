<script type="text/javascript" src="{$system_url}includes/modules/Other/email_mfa_v2/lib/scripts.js?v={$hb_version}"></script>
<form action="?cmd=mfa&action=setup&id={$modulesetup_id}" method="POST">
    {securitytoken}
    <div class="panel panel-default">
        <div class="panel-heading">{$lang.mfa}</div>
        <div class="panel-body">
            <div class="alert alert-info">{$lang.email_mfa_v2_login_desc}</div>
            <div class="form-group">
                <label for="code" class="styled">{$lang.email_mfa_v2_onetimecode}</label>
                <input name="code" class="styled form-control" size="20" type="text" autofocus autocomplete="off"/>
            </div>
        </div>
        <div class="panel-footer">
            <button type="submit" name="make" value="submit" class="btn btn-primary">{$lang.submit}</button>
            <button type="button" class="btn btn-request btn-primary" id="mfa-enable-btn" data-module-id="{$modulesetup_id}"
                    data-resend="0">
                <i class="fa fa-envelope"></i>
                <span data-state="visible">{$lang.email_mfa_v2_send}</span>
                <span data-state="hidden" style="display: none;">{$lang.email_mfa_v2_resend}</span>
            </button>
        </div>
    </div>
</form>
