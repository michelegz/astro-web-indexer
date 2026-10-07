<?php
declare(strict_types=1);

// Renders the real language_selector.php with a hostile $_GET and inspects the emitted
// markup. The previous version of this test could not run at all: its header comment
// contained a literal close tag inside a // comment, so PHP left PHP mode at that line
// and printed the rest of the file instead of executing any check.
//
// It also asserted things that are now false. language_selector.php no longer interpolates
// the query string into a JS string inside an attribute; it passes it through
// data-return with htmlspecialchars(..., ENT_QUOTES) and encodes the language with
// encodeURIComponent. A test that greps the source for the pre-fix shape therefore pins a
// template that no longer ships, and its assertion that the template "does not escape"
// would start failing the day someone adds the escaping.
//
// What matters is the output, so that is what is checked: can a hostile query string
// escape the attribute, close the JS string, or add an event handler? And does the
// language switch still carry the rest of the query string intact?

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$failed = [];
$check = function (string $label, bool $cond, string $detail = '') use (&$failed): void {
    printf("  %-52s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $failed[] = $label;
    }
};

$quote = chr(39);
$dquote = chr(34);
$payload = 'x' . $quote . ' onmouseover=alert(1) ' . $dquote . ' y';
$_GET = ['q' => $payload, 'page' => '3'];

$_SESSION = [];
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

ob_start();
include '/var/www/html/includes/language_selector.php';
$html = (string)ob_get_clean();

echo "=== il markup emesso non contiene il payload iniettato ===\n";
printf("  payload: %s\n", $payload);
printf("  byte emessi: %d\n", strlen($html));

$check('il payload grezzo non compare', !str_contains($html, $payload), '');

// Every attribute value must still be closed by the template: the only quotes in the
// markup are the ones the template itself emitted. Parse instead of counting, so a
// legitimate extra quote elsewhere does not make the count a lucky pass or a false alarm.
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
libxml_clear_errors();
$attrs = [];
foreach ($doc->getElementsByTagName('select') as $sel) {
    foreach ($sel->attributes as $a) {
        $attrs[strtolower($a->name)] = $a->value;
    }
}
$check('il select e\' stato parsato', $attrs !== [], count($attrs) . ' attributi');

// Assert on the parsed attribute NAMES, not on substrings. 'onmouseover' does appear in
// the output, but only percent-encoded inside data-return, which is inert data; grepping
// for it made the test fail on a template that is doing the right thing.
$eventAttrs = array_keys(array_filter(
    $attrs,
    static fn(string $v, string $n): bool => str_starts_with($n, 'on'),
    ARRAY_FILTER_USE_BOTH
));
// onchange belongs to the template. The invariant is that it is the ONLY event handler:
// a payload that could add one would show up here as an extra name.
$check('onchange e\' l\'unico gestore eventi', $eventAttrs === ['onchange'],
    implode(',', $eventAttrs));
$check('nessun elemento script iniettato', $doc->getElementsByTagName('script')->length === 0, '');
$check('nessun elemento extra nel select',
    $doc->getElementsByTagName('select')->length === 1
    && $doc->getElementsByTagName('option')->length > 0,
    $doc->getElementsByTagName('select')->length . ' select, '
    . $doc->getElementsByTagName('option')->length . ' option');

echo "\n=== data-return trasporta la query string intatta ===\n";
if (isset($attrs['data-return'])) {
    $decoded = html_entity_decode($attrs['data-return'], ENT_QUOTES, 'UTF-8');
    printf("  data-return grezzo : %s\n", $attrs['data-return']);
    printf("  data-return decodificato: %s\n", $decoded);
    $expected = http_build_query(['q' => $payload, 'page' => '3']);
    $check('il valore decodificato e\' la query string attesa', $decoded === $expected,
        $decoded === $expected ? '' : 'attesa ' . $expected);
    $check('non contiene apici o virgolette', !str_contains($decoded, $quote) && !str_contains($decoded, $dquote), '');
} else {
    echo "  data-return assente: il template non usa questa forma\n";
}

echo "\n=== il template non interpola input grezzo in uno script ===\n";
// Behavioural: the switch must not carry the raw query string into a JS literal. It may
// be absent (data-return) or encoded, but never raw.
$onchange = $attrs['onchange'] ?? '';
$check('onchange non contiene il payload', !str_contains($onchange, $payload), '');
$check('onchange codifica il valore della lingua', str_contains($onchange, 'encodeURIComponent'), '');

echo "\n=== controllo negativo: perche\' la codifica conta ===\n";
// Builds the pre-fix shape directly and shows it WOULD have been injectable had the query
// string not been percent-encoded. This documents that the guarantee is behavioural, not
// incidental, without grepping the source for a template that no longer exists.
$raw = 'q=' . $payload;
$oldShape = 'onchange=' . $dquote . 'window.location.href=' . $quote . '?lang=' . $quote
    . ' + this.value + ' . $quote . $raw . $quote . $dquote;
$check('la forma pre-fix, non codificata, chiuderebbe l\'attributo',
    substr_count($oldShape, $dquote) > 2,
    substr_count($oldShape, $dquote) . ' doppie virgolette');

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'il markup emesso non e\' iniettabile, e la query string resta intatta') . "\n";