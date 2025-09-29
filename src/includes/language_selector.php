<?php
$currentLang = getBestLanguage();
$availableLangs = getAvailableLanguages();
$queryParams = $_GET;
unset($queryParams['lang']);
$queryString = http_build_query($queryParams);
?>

<div class="relative inline-block text-left">
    <select 
        onchange="window.location.href='?lang=' + this.value + '<?= !empty($queryString) ? '&' . $queryString : '' ?>'"
        class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-2.5">
        <?php foreach($availableLangs as $langCode): ?>
            <option value="<?= $langCode ?>" <?= $langCode === $currentLang ? 'selected' : '' ?>>
                <?= getLanguageName($langCode) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>