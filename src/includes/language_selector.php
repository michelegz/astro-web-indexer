<?php
$currentLang = getBestLanguage();
$availableLangs = getAvailableLanguages();
?>

<div class="relative inline-block text-left">
    <select 
        onchange="window.location.href='?lang=' + this.value + '<?= isset($_GET['dir']) ? '&dir=' . urlencode($_GET['dir']) : '' ?>'"
        class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-2.5">
        <?php foreach($availableLangs as $langCode): ?>
            <option value="<?= $langCode ?>" <?= $langCode === $currentLang ? 'selected' : '' ?>>
                <?= getLanguageName($langCode) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>