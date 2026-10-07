<?php
require_once __DIR__ . '/includes/init.php';

// Filter mapping is global (equipment-level): admins, or anyone when auth is disabled.
if (isAuthEnabled() && !isAdmin()) {
    http_response_code(403);
    header('Location: /');
    exit;
}

$conn = connectDB();
$message = '';
$messageType = '';

// Handle bulk save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = __('admin_error_csrf');
        $messageType = 'error';
    } else {
        try {
            $rows = $_POST['rows'] ?? [];
            $upsert = $conn->prepare(
                "INSERT INTO astrobin_filter_map (filter_name, astrobin_id, label) VALUES (:name, :id, :label)
                 ON DUPLICATE KEY UPDATE astrobin_id = VALUES(astrobin_id), label = VALUES(label)"
            );
            $delete = $conn->prepare("DELETE FROM astrobin_filter_map WHERE filter_name = :name");
            $saved = 0;
            foreach ($rows as $row) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $idRaw = trim((string)($row['id'] ?? ''));
                $label = trim((string)($row['label'] ?? ''));
                if ($idRaw === '') {
                    $delete->execute([':name' => $name]);
                    continue;
                }
                if (!ctype_digit($idRaw) || (int)$idRaw <= 0) {
                    throw new InvalidArgumentException(__('filter_mapping_invalid_id', ['name' => $name]));
                }
                $upsert->execute([':name' => $name, ':id' => (int)$idRaw, ':label' => $label !== '' ? $label : null]);
                $saved++;
            }
            $message = __('filter_mapping_saved', ['count' => $saved]);
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Generate CSRF token only if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Distinct non-empty FILTER values with usage counts + current mapping
$stmt = $conn->query(
    "SELECT f.filter AS fname, COUNT(*) AS cnt, m.astrobin_id, m.label
     FROM files f
     LEFT JOIN astrobin_filter_map m ON LOWER(TRIM(f.filter)) = LOWER(m.filter_name)
     WHERE f.filter IS NOT NULL AND TRIM(f.filter) != '' AND f.deleted_at IS NULL
     GROUP BY f.filter
     ORDER BY f.filter"
);
$filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('filter_mapping') ?> - <?= __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css?v=<?= @filemtime(__DIR__ . '/assets/css/output.css') ?: 0 ?>" rel="stylesheet">
</head>
<body class="flex flex-col min-h-screen bg-gray-900 text-gray-100 font-sans">
    <header class="bg-gray-700 shadow-md">
        <div class="px-4 py-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-wide"><?= __('filter_mapping') ?></h1>
            <div class="flex items-center gap-4">
                <?php include __DIR__ . '/includes/language_selector.php'; ?>
                <a href="/" class="text-sm text-gray-300 hover:text-white">&larr; <?= __('back') ?></a>
            </div>
        </div>
    </header>

    <main class="flex-grow px-4 py-8 max-w-4xl mx-auto w-full">

        <?php if ($message): ?>
            <div class="mb-6 p-3 rounded text-sm <?= $messageType === 'success' ? 'bg-green-900/50 border border-green-700 text-green-300' : 'bg-red-900/50 border border-red-700 text-red-300' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <p class="text-sm text-gray-300">
                <?= __('filter_mapping_intro') ?>
                <a href="https://app.astrobin.com/equipment/explorer/filter/" target="_blank" rel="noopener" class="text-blue-400 hover:text-blue-300 underline"><?= __('filter_mapping_explorer') ?></a>.
            </p>
        </section>

        <section class="bg-gray-800 rounded-lg p-6">
            <?php if (empty($filters)): ?>
                <p class="text-gray-500"><?= __('filter_mapping_no_filters') ?></p>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="py-2 px-3"><?= __('filter') ?></th>
                                <th class="py-2 px-3 text-right"><?= __('filter_mapping_frames') ?></th>
                                <th class="py-2 px-3"><?= __('filter_mapping_astrobin_id') ?></th>
                                <th class="py-2 px-3"><?= __('filter_mapping_label') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filters as $i => $f): ?>
                                <tr class="border-b border-gray-700/50 <?= empty($f['astrobin_id']) ? 'bg-yellow-900/20' : '' ?>">
                                    <td class="py-2 px-3 font-medium"><?= htmlspecialchars($f['fname']) ?></td>
                                    <td class="py-2 px-3 text-right text-gray-400"><?= (int)$f['cnt'] ?></td>
                                    <td class="py-2 px-3">
                                        <input type="hidden" name="rows[<?= $i ?>][name]" value="<?= htmlspecialchars($f['fname']) ?>">
                                        <input type="text" name="rows[<?= $i ?>][id]" inputmode="numeric" pattern="[0-9]*"
                                               value="<?= htmlspecialchars((string)($f['astrobin_id'] ?? '')) ?>"
                                               placeholder="<?= __('filter_mapping_id_placeholder') ?>"
                                               class="w-28 px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100">
                                    </td>
                                    <td class="py-2 px-3">
                                        <input type="text" name="rows[<?= $i ?>][label]"
                                               value="<?= htmlspecialchars((string)($f['label'] ?? '')) ?>"
                                               class="w-full px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('filter_mapping_save') ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
