<script type="text/javascript" src="{$system_url}includes/modules/Other/email_mfa_v2/lib/scripts.js?v={$hb_version}"></script>
<form action="?cmd=mfa&action={$action}{if $smarty.get.redirect}&redirect={$smarty.get.redirect}{/if}" method="POST">
    <input type="hidden" id="user_type" value="{$user_type}"/>

    {securitytoken}
    <div class="wbox">
        <div class="wbox_header">
            {if $confirm}{$lang.confirmaction|sprintf:$confirm.name}{else}{$lang.login}{/if}
        </div>
        <div class="wbox_content">
            <div class="alert alert-info">{$lang.email_mfa_v2_login_desc}</div>
            {if $active_codes}
                <div class="alert alert-warning">
                    {capture name="activeCodesLabel"}{$lang.email_mfa_v2_active_codes|sprintf:$active_codes:$earliest_expiry}{/capture}
                    {$smarty.capture.activeCodesLabel}
                </div>
            {/if}
            <div style="text-align: center;padding:10px;">
                <button type="button" id="mfa-verify-btn" href="#" class="btn btn-request btn-primary" data-resend="{$auto_send_code}">
                    <i class="fa fa-envelope"></i>
                    <span {if $auto_send_code == 0}data-state="visible" {else} data-state="hidden" style="display: none;"{/if}>{$lang.email_mfa_v2_send}</span>
                    <span {if $auto_send_code == 1}data-state="visible" {else} data-state="hidden" style="display: none;"{/if}>{$lang.email_mfa_v2_resend}</span>
                    <span class="btn-timer"></span>
                </button>
            </div>

            <div {if $auto_send_code == 0} style="display: none" {/if}id="2faform">
                <div class="form-group" >
                    <label for="code" class="styled">{$lang.email_mfa_v2_onetimecode}</label>
                    <input name="code" class="styled form-control" type="number" id="code" autofocus autocomplete="off"/>
                </div>
                {if $enabled_remembering}
                    <div class="checkbox" style="margin:10px 0;">
                        <label>
                            <input name="rememberme" value="1" type="checkbox"/>
                            {$lang.gauth_rememberme|sprintf:$remembering_days}
                        </label>
                    </div>
                {/if}
                <button type="submit" name="make" value="submit" class="btn btn-primary">{$lang.submit}</button>
                {if $confirm}
                    <a class="btn btn-light ml-1 " href="{$confirm.cancel_url|default:$ca_url}">{$lang.cancel}</a>
                    <input type="hidden" name="id" value="{$confirm.id}">
                {else}
                    <a class="btn btn-light ml-1 " href="?action=logout&security_token={$security_token}">{$lang.logout}</a>
                {/if}
            </div>
        </div>
    </div>
</form>
