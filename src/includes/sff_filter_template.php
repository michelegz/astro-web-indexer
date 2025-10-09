<?php
/**
 * Renders a single filter row for the Smart Frame Finder modal.
 *
 * @param array $filterConfig Configuration for the filter.
 *     'id'         => (string) Unique ID for the filter (e.g., 'object').
 *     'label'      => (string) Display name (e.g., 'Object').
 *     'type'       => (string) 'toggle', 'slider_percent', 'slider_degrees', 'slider_days'.
 *     'default_on' => (bool)   Whether the filter is enabled by default.
 *     'min'        => (int)    Min value for sliders.
 *     'max'        => (int)    Max value for sliders.
 *     'step'       => (int)    Step for sliders.
 *     'unit'       => (string) Unit to display next to the slider value (e.g., '%', '°').
 * @param mixed $referenceValue The value of this field from the reference LIGHT frame.
 */
function render_sff_filter(array $filterConfig, $referenceValue): void
{
    // Do not render if the reference value is missing
    if ($referenceValue === null || $referenceValue === '') {
        return;
    }

    $id = htmlspecialchars($filterConfig['id']);
    $label = htmlspecialchars($filterConfig['label']);
    $type = $filterConfig['type'];
    $defaultOn = $filterConfig['default_on'] ? 'checked' : '';
    $isDisabled = !$filterConfig['default_on'] ? 'disabled' : '';

    $initialTolerance = $filterConfig['default_tolerance'] ?? 0;
?>
<div class="sff-filter-row mb-4 p-3 bg-gray-700 rounded-md" data-filter-id="<?= $id ?>">
    <div class="flex items-center justify-between">
        <label for="filter-toggle-<?= $id ?>" class="flex items-center cursor-pointer">
            <input type="checkbox" id="filter-toggle-<?= $id ?>" class="sff-filter-toggle form-checkbox h-5 w-5 text-blue-500 rounded bg-gray-800 border-gray-600 focus:ring-blue-600" <?= $defaultOn ?>>
            <span class="ml-3 font-medium text-white"><?= $label ?></span>
        </label>
        <span class="text-sm font-mono text-gray-300"><?= htmlspecialchars((string)$referenceValue) ?></span>
        <input type="hidden" class="sff-reference-value" value="<?= htmlspecialchars((string)$referenceValue) ?>">
    </div>

    <?php if ($type !== 'toggle'): ?>
        <div class="sff-slider-container mt-3 <?= $isDisabled ? 'opacity-50' : '' ?>" data-type="<?= htmlspecialchars($type) ?>">
            <div class="flex items-center justify-between gap-4">
                <input type="range" 
                       class="sff-filter-slider w-full" 
                       min="<?= $filterConfig['min'] ?>" 
                       max="<?= $filterConfig['max'] ?>" 
                       step="<?= $filterConfig['step'] ?>" 
                       value="<?= $initialTolerance ?>"
                       <?= $isDisabled ?>>
                <span class="sff-slider-value text-sm font-semibold text-cyan-400 w-20 text-center" data-unit="<?= htmlspecialchars($filterConfig['unit']) ?>">
                    &pm;<?= $initialTolerance ?><?= htmlspecialchars($filterConfig['unit']) ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
}
