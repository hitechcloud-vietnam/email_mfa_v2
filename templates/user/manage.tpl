<script type="text/javascript" src="{$system_url}includes/modules/Other/email_mfa_v2/lib/scripts.js?v={$hb_version}"></script>
<form action="?cmd=mfa&action=manage&id={$modulesetup_id}" method="POST">
    {securitytoken}
    <div class="wbox">
        <div class="wbox_header">{$lang.email_mfa_v2_manage_title}</div>
        <div class="wbox_content">
            {if $active_codes|@count == 0}
                <div class="alert alert-info">{$lang.email_mfa_v2_manage_empty}</div>
            {else}
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{$lang.purpose|default:'Purpose'}</th>
                            <th>{$lang.created|default:'Created'}</th>
                            <th>{$lang.expires|default:'Expires'}</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    {foreach from=$active_codes item=code}
                        <tr>
                            <td>{$code.purpose|escape:'html'}</td>
                            <td>{$code.created_at|escape:'html'}</td>
                            <td>{$code.expires_at|escape:'html'}</td>
                            <td>{$code.ip_address|escape:'html'}</td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
                <button type="submit" name="mfa_action" value="revoke_all" class="btn btn-danger">
                    {$lang.email_mfa_v2_manage_revoke}
                </button>
            {/if}
        </div>
    </div>
</form>
