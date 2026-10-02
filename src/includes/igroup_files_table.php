<?php
// Integration-group files table (projects page, under "show files").
// Same markup, order, labels and formatting as the main file table via
// renderFileTableHeaders()/renderFileTableCells() in file_cells.php.
// Expects $grp (integration group) and $gi (group index) from the caller.
// No median footer: medians stay visible on the charts (reject panel
// placeholders still show them as input hints).
?>
<div class="overflow-x-auto">
    <table class="w-full text-left igroup-table" data-group="<?= (int)$gi ?>">
        <thead class="bg-gray-700 text-gray-200">
            <tr>
                <?php renderFileTableHeaders('project'); ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grp['lights'] as $li): ?>
                <?php $liOff = empty($li['enabled']); ?>
                <?php $liAuto = !$liOff && !empty($li['auto_off']); ?>
                <?php
                $liSuffix = '';
                if ($liOff) {
                    $liSuffix = ' <span class="text-gray-500">(' . __('projects_link_off') . ')</span>';
                } elseif ($liAuto) {
                    $liSuffix = ' <span class="text-gray-500">(' . __('projects_auto_off') . ')</span>';
                }
                ?>
                <tr class="border-b border-gray-700 hover:bg-gray-700<?= ($liOff || $liAuto) ? ' opacity-60' : '' ?>" data-enabled="<?= $liOff ? '0' : '1' ?>" data-auto="<?= $liAuto ? '1' : '0' ?>" data-linkkey="<?= htmlspecialchars((string)($li['link_key'] ?? '')) ?>">
                    <?php renderFileTableCells($li, 'project', $liSuffix); ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
