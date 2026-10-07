<?php

declare(strict_types=1);

/**
 * JSON responses for the API endpoints.
 *
 * json_encode() returns false on malformed UTF-8 and `echo false` prints nothing,
 * so the client gets 200 with an empty body and an opaque "Unexpected end of JSON
 * input". File names come straight off the filesystem (files.name is a plain
 * VARCHAR on a utf8mb4 connection, and a non-strict insert can store bytes MySQL
 * does not re-encode), so this is reachable in practice.
 *
 * Two layers:
 *  - JSON_INVALID_UTF8_SUBSTITUTE keeps the response usable instead of blank;
 *  - the false check still catches the rest and turns it into a clean 500 rather
 *    than an empty body.
 */

// Emitted payloads are UTF-8 JSON; the flag keeps a stray byte from blanking them.
if (!defined('AWI_JSON_FLAGS')) {
    define('AWI_JSON_FLAGS', JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES);
}

/**
 * Encode and print a JSON response, then stop.
 *
 * @param mixed $payload
 */
function awiJson($payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    $body = json_encode($payload, AWI_JSON_FLAGS);
    if ($body === false) {
        // Still unencodable: say so plainly instead of emitting an empty body.
        error_log('awiJson: json_encode failed: ' . json_last_error_msg());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '{"error":"Response could not be encoded."}';
        exit;
    }
    echo $body;
    exit;
}

/**
 * Encode for a value stored inside an artifact (the ZIP manifest), where a failure
 * must not abort the whole download. Returns a JSON string, never false.
 */
function awiJsonString($payload, int $flags = 0): string
{
    $body = json_encode($payload, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) {
        error_log('awiJsonString: json_encode failed: ' . json_last_error_msg());
        return '{"error":"manifest could not be encoded"}';
    }
    return $body;
}

/**
 * Read a list of file ids from the request, from either a GET query string
 * (?ids=1,2,3) or a JSON body ({ids: [1,2,3]} / {"ids": "1,2,3"}).
 *
 * The ids were only ever read from the query string, and a project selection or a
 * table selection runs into thousands of them: a few digits each, so a few thousand
 * files put tens of kilobytes on the request line, past the 8 KB the server accepts,
 * and the call failed with 414 before any application code ran. The body has no such
 * limit, so the UI posts instead. GET stays supported: a CSV URL is worth keeping.
 *
 * Returns a list of positive integers, deduplicated and in order.
 *
 * @return list<int>
 */
function awiReadFileIds(): array
{
    $raw = [];
    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (isset($data['ids']) && is_array($data['ids'])) {
                $raw = $data['ids'];
            } elseif (isset($data['ids']) && is_string($data['ids'])) {
                $raw = explode(',', $data['ids']);
            }
        }
    }
    if ($raw === [] && isset($_GET['ids'])) {
        $raw = is_array($_GET['ids']) ? $_GET['ids'] : explode(',', (string)$_GET['ids']);
    }
    $out = [];
    foreach ($raw as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $id = (int)$v;
        // Only positive integers: the cast would otherwise turn "abc" into 0 and any
        // junk into a plausible id.
        if ($id > 0 && (string)$id === trim((string)$v)) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}