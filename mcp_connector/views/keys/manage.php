<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo admin_url('mcp_connector/audit'); ?>" class="btn btn-default btn-sm pull-right"><?php echo _l('mcp_connector_view_audit_log'); ?></a>
                <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('mcp_connector'); ?></h4>
                <p class="tw-text-neutral-500"><?php echo _l('mcp_connector_intro'); ?></p>
            </div>
        </div>

        <?php if (! empty($new_token)) { ?>
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-success">
                    <p class="bold"><?php echo _l('mcp_connector_new_token_notice'); ?></p>
                    <pre class="tw-whitespace-pre-wrap tw-select-all"><?php echo html_escape($new_token); ?></pre>
                    <p class="tw-mb-0"><?php echo _l('mcp_connector_connect_with'); ?></p>
                    <pre class="tw-whitespace-pre-wrap tw-select-all">claude mcp add --transport http perfex <?php echo html_escape($endpoint); ?> --header "Authorization: Bearer <?php echo html_escape($new_token); ?>"</pre>
                </div>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <div class="col-md-8">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-mt-0 bold"><?php echo _l('mcp_connector_keys'); ?></h4>
                        <table class="table dt-table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('mcp_connector_label'); ?></th>
                                    <th><?php echo _l('mcp_connector_token_prefix'); ?></th>
                                    <th><?php echo _l('mcp_connector_acts_as'); ?></th>
                                    <th><?php echo _l('mcp_connector_destructive'); ?></th>
                                    <th><?php echo _l('mcp_connector_rate_limit'); ?></th>
                                    <th><?php echo _l('mcp_connector_ip_whitelist'); ?></th>
                                    <th><?php echo _l('mcp_connector_last_used'); ?></th>
                                    <th><?php echo _l('mcp_connector_requests'); ?></th>
                                    <th><?php echo _l('mcp_connector_status'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keys as $key) { ?>
                                <tr>
                                    <td><?php echo html_escape($key->label); ?></td>
                                    <td><code><?php echo html_escape($key->token_prefix); ?>...</code></td>
                                    <td><?php echo html_escape($key->staff_name ?: ('#' . $key->user_id)); ?></td>
                                    <td><?php echo $key->allow_destructive ? '<span class="label label-warning">' . _l('mcp_connector_yes') . '</span>' : _l('mcp_connector_no'); ?></td>
                                    <td><?php echo (int) $key->rate_limit_per_minute; ?>/min</td>
                                    <td>
                                        <?php if (staff_can('create', 'mcp_connector')) { ?>
                                        <?php echo form_open(admin_url('mcp_connector/update_whitelist/' . $key->id), ['class' => 'tw-flex tw-gap-1']); ?>
                                            <input type="text" name="ip_whitelist" class="form-control input-sm" style="min-width:150px"
                                                   value="<?php echo html_escape(str_replace("\n", ', ', (string) ($key->ip_whitelist ?? ''))); ?>"
                                                   placeholder="<?php echo _l('mcp_connector_any_ip'); ?>">
                                            <button type="submit" class="btn btn-default btn-sm" title="<?php echo _l('submit'); ?>"><i class="fa fa-check"></i></button>
                                        <?php echo form_close(); ?>
                                        <?php } else {
                                            echo $key->ip_whitelist ? '<code>' . html_escape(str_replace("\n", ', ', $key->ip_whitelist)) . '</code>' : '<span class="text-muted">' . _l('mcp_connector_any_ip') . '</span>';
                                        } ?>
                                    </td>
                                    <td><?php echo $key->last_used_at ? _dt($key->last_used_at) : '<span class="text-muted">' . _l('mcp_connector_never') . '</span>'; ?></td>
                                    <td><?php echo (int) $key->requests_count; ?></td>
                                    <td>
                                        <?php if ($key->revoked_at) { ?>
                                            <span class="label label-danger"><?php echo _l('mcp_connector_revoked'); ?></span>
                                        <?php } else { ?>
                                            <span class="label label-success"><?php echo _l('mcp_connector_active'); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if (! $key->revoked_at && staff_can('delete', 'mcp_connector')) { ?>
                                        <a href="<?php echo admin_url('mcp_connector/revoke_key/' . $key->id); ?>"
                                           class="btn btn-danger btn-xs _delete"
                                           onclick="return confirm('<?php echo _l('mcp_connector_confirm_revoke'); ?>');">
                                            <?php echo _l('mcp_connector_revoke'); ?>
                                        </a>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($keys)) { ?>
                                <tr><td colspan="10" class="text-muted"><?php echo _l('mcp_connector_no_keys'); ?></td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-mt-0 bold"><?php echo _l('mcp_connector_endpoint'); ?></h4>
                        <p class="tw-mb-1"><?php echo _l('mcp_connector_endpoint_help'); ?></p>
                        <pre class="tw-select-all"><?php echo html_escape($endpoint); ?></pre>

                        <h5 class="bold"><?php echo _l('mcp_connector_connect_claude_code'); ?></h5>
                        <pre class="tw-select-all tw-whitespace-pre-wrap">claude mcp add --transport http perfex <?php echo html_escape($endpoint); ?> --header "Authorization: Bearer YOUR_API_KEY"</pre>

                        <h5 class="bold"><?php echo _l('mcp_connector_connect_claude_ai'); ?></h5>
                        <ol class="tw-pl-4 tw-text-sm">
                            <li><?php echo _l('mcp_connector_step_settings'); ?></li>
                            <li><?php echo sprintf(_l('mcp_connector_step_url'), '<code>' . html_escape($endpoint) . '</code>'); ?></li>
                            <li><?php echo _l('mcp_connector_step_header'); ?> <code>Authorization: Bearer YOUR_API_KEY</code></li>
                        </ol>
                        <p class="tw-text-neutral-500 tw-text-xs"><?php echo _l('mcp_connector_auth_header_note'); ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <?php if (staff_can('create', 'mcp_connector')) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-mt-0 bold"><?php echo _l('mcp_connector_create_key'); ?></h4>
                        <?php echo form_open(admin_url('mcp_connector/create_key')); ?>
                            <div class="form-group">
                                <label for="label"><?php echo _l('mcp_connector_label'); ?></label>
                                <input type="text" name="label" id="label" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="user_id"><?php echo _l('mcp_connector_acts_as'); ?></label>
                                <?php if ($is_admin) { ?>
                                <select name="user_id" id="user_id" class="form-control selectpicker" data-live-search="true">
                                    <?php foreach ($staff as $member) { ?>
                                    <option value="<?php echo $member['staffid']; ?>"<?php echo $member['staffid'] == get_staff_user_id() ? ' selected' : ''; ?>>
                                        <?php echo html_escape($member['firstname'] . ' ' . $member['lastname']); ?>
                                    </option>
                                    <?php } ?>
                                </select>
                                <p class="tw-text-neutral-500 tw-text-xs tw-mt-1"><?php echo _l('mcp_connector_acts_as_help'); ?></p>
                                <?php } else { ?>
                                <input type="hidden" name="user_id" value="<?php echo get_staff_user_id(); ?>">
                                <p class="tw-text-neutral-500"><?php echo _l('mcp_connector_acts_as_self'); ?></p>
                                <?php } ?>
                            </div>
                            <div class="form-group">
                                <label for="rate_limit_per_minute"><?php echo _l('mcp_connector_rate_limit'); ?></label>
                                <input type="number" name="rate_limit_per_minute" id="rate_limit_per_minute" class="form-control" value="<?php echo (int) $options['default_rate_limit']; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="ip_whitelist"><?php echo _l('mcp_connector_ip_whitelist'); ?></label>
                                <textarea name="ip_whitelist" id="ip_whitelist" class="form-control" rows="2" placeholder="203.0.113.5&#10;198.51.100.0/24"></textarea>
                                <p class="tw-text-neutral-500 tw-text-xs tw-mt-1"><?php echo _l('mcp_connector_ip_whitelist_help'); ?></p>
                            </div>
                            <div class="checkbox">
                                <input type="checkbox" name="allow_destructive" id="allow_destructive" value="1">
                                <label for="allow_destructive"><?php echo _l('mcp_connector_allow_destructive'); ?></label>
                            </div>
                            <button type="submit" class="btn btn-primary tw-mt-2"><?php echo _l('mcp_connector_create_key'); ?></button>
                        <?php echo form_close(); ?>
                    </div>
                </div>
                <?php } ?>

                <?php if ($is_admin) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-mt-0 bold"><?php echo _l('mcp_connector_settings'); ?></h4>
                        <?php echo form_open(admin_url('mcp_connector/save_settings')); ?>
                            <div class="checkbox">
                                <input type="checkbox" name="enabled" id="enabled" value="1"<?php echo $options['enabled'] ? ' checked' : ''; ?>>
                                <label for="enabled"><?php echo _l('mcp_connector_opt_enabled'); ?></label>
                            </div>
                            <div class="checkbox">
                                <input type="checkbox" name="writes" id="writes" value="1"<?php echo $options['writes'] ? ' checked' : ''; ?>>
                                <label for="writes"><?php echo _l('mcp_connector_opt_writes'); ?></label>
                            </div>
                            <div class="checkbox">
                                <input type="checkbox" name="destructive" id="destructive" value="1"<?php echo $options['destructive'] ? ' checked' : ''; ?>>
                                <label for="destructive"><?php echo _l('mcp_connector_opt_destructive'); ?></label>
                            </div>
                            <div class="form-group tw-mt-2">
                                <label for="default_rate_limit"><?php echo _l('mcp_connector_opt_default_rate'); ?></label>
                                <input type="number" name="default_rate_limit" id="default_rate_limit" class="form-control" value="<?php echo (int) $options['default_rate_limit']; ?>" min="0">
                            </div>
                            <button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>
                        <?php echo form_close(); ?>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
