<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo admin_url('mcp_connector'); ?>" class="btn btn-default btn-sm pull-right"><?php echo _l('mcp_connector_back_to_keys'); ?></a>
                <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('mcp_connector_audit_log'); ?></h4>
                <p class="tw-text-neutral-500"><?php echo _l('mcp_connector_audit_intro'); ?></p>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <?php echo form_open(admin_url('mcp_connector/audit'), ['method' => 'get', 'class' => 'form-inline tw-mb-3']); ?>
                            <div class="form-group">
                                <input type="text" name="tool" class="form-control input-sm" placeholder="<?php echo _l('mcp_connector_filter_tool'); ?>" value="<?php echo html_escape($filter_tool); ?>">
                            </div>
                            <div class="form-group">
                                <select name="status" class="form-control input-sm">
                                    <option value=""><?php echo _l('mcp_connector_any_status'); ?></option>
                                    <?php foreach (['success', 'error', 'denied'] as $st) { ?>
                                    <option value="<?php echo $st; ?>"<?php echo $filter_status === $st ? ' selected' : ''; ?>><?php echo $st; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-default btn-sm"><?php echo _l('mcp_connector_filter'); ?></button>
                        <?php echo form_close(); ?>

                        <table class="table dt-table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('mcp_connector_when'); ?></th>
                                    <th><?php echo _l('mcp_connector_tool'); ?></th>
                                    <th><?php echo _l('mcp_connector_status'); ?></th>
                                    <th><?php echo _l('mcp_connector_acts_as'); ?></th>
                                    <th><?php echo _l('mcp_connector_label'); ?></th>
                                    <th><?php echo _l('mcp_connector_arguments'); ?></th>
                                    <th>ms</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row) {
                                    $badge = $row->result_status === 'success' ? 'label-success' : ($row->result_status === 'denied' ? 'label-warning' : 'label-danger'); ?>
                                <tr>
                                    <td class="tw-whitespace-nowrap"><?php echo _dt($row->created_at); ?></td>
                                    <td><code><?php echo html_escape($row->tool); ?></code></td>
                                    <td><span class="label <?php echo $badge; ?>"><?php echo html_escape($row->result_status); ?></span></td>
                                    <td><?php echo html_escape($row->staff_name ?: ('#' . $row->staff_id)); ?></td>
                                    <td><?php echo html_escape($row->key_label ?: '-'); ?></td>
                                    <td><small><?php echo html_escape(mb_strimwidth((string) ($row->error_message ?: $row->arguments), 0, 120, '...')); ?></small></td>
                                    <td><?php echo (int) $row->duration_ms; ?></td>
                                    <td><small><?php echo html_escape((string) $row->ip); ?></small></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($rows)) { ?>
                                <tr><td colspan="8" class="text-muted"><?php echo _l('mcp_connector_no_audit'); ?></td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
