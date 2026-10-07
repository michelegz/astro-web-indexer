<?php
$currentLang = getBestLanguage();
$availableLangs = getAvailableLanguages();
$queryParams = $_GET;
unset($queryParams['lang']);
$queryString = http_build_query($queryParams);
?>

<div class="relative inline-block text-left">
<?php // The query string is http_build_query($_GET), so it is percent-encoded and the
// current markup happened to be safe. That is a property of the encoder, not of the
// template: interpolating raw input into a JS string inside an attribute is one
// refactor away from being injectable. It travels in a data- attribute instead, where
// the HTML layer escapes it and the browser decodes it exactly once, instead of a JS
// literal where escaping and encoding would fight each other (ENT_QUOTES would be
// decoded straight back into a quote before the JS parser sees it).
// breadcrumbs.php does the same sink twice, once escaped and once not: both fixed. ?>
<select
  data-return="<?= htmlspecialchars($queryString, ENT_QUOTES) ?>"
  onchange="var r = this.dataset.return; window.location.href = '?lang=' + encodeURIComponent(this.value) + (r ? '&' + r : '')"
  class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-50">
<?php foreach($availableLangs as $langCode): ?>
<option value="<?= htmlspecialchars($langCode, ENT_QUOTES) ?>" <?= $langCode === $currentLang ? 'selected' : '' ?>>
<?= htmlspecialchars(getLanguageName($langCode)) ?>
</option>
<?php endforeach; ?>
</select>
</div>