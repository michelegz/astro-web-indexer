<?php
// Right-side projects canvas, symmetric to the left folders sidebar.
// Server-rendered like the folders menu: project list + compact create form +
// delete with confirm. Forms POST to /projects.php (same handlers + CSRF as
// the projects page) with a `return` field so the user lands back home.
// Expects $conn from init.php. Auto-opens when ?panel=projects is present.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$panelCsrf = $_SESSION['csrf_token'];
$panelProjects = isset($conn) ? getProjects($conn) : [];
$panelReturn = $_SERVER['REQUEST_URI'] ?? '/';
$panelOpen = ($_GET['panel'] ?? '') === 'projects';
?>
<div class="sidebar fixed inset-y-0 right-0 w-60 bg-gray-800 p-4 overflow-y-auto z-30 transition-transform duration-300 ease-in-out transform <?= $panelOpen ? '' : 'translate-x-full' ?>" id="projects-panel">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold text-white"><?php echo __('projects') ?></h2>
        <button class="text-gray-400 hover:text-white" onclick="toggleProjectsPanel()">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <form method="POST" action="/projects.php" class="flex flex-col gap-2 mb-4 p-2 bg-gray-700/40 rounded-md">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($panelCsrf) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= htmlspecialchars($panelReturn) ?>">
        <input type="text" name="name" maxlength="255" required
               placeholder="<?= __('projects_name') ?>"
               class="px-2 py-1 text-sm bg-gray-700 border border-gray-600 rounded text-gray-100">
        <textarea name="notes" rows="1"
                  placeholder="<?= __('projects_notes') ?>"
                  class="px-2 py-1 text-sm bg-gray-700 border border-gray-600 rounded text-gray-100"></textarea>
        <button type="submit" class="px-3 py-1 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors">
            <?= __('projects_create') ?>
        </button>
    </form>

    <?php if (empty($panelProjects)): ?>
        <p class="text-gray-500 text-sm p-2"><?= __('projects_no_projects') ?></p>
    <?php else: ?>
        <nav class="flex flex-col gap-2">
            <?php foreach ($panelProjects as $pp): ?>
                <a href="/projects.php?id=<?= (int)$pp['id'] ?>"
                   class="group block bg-gray-700/40 hover:bg-gray-600 border border-transparent hover:border-blue-500 rounded-md p-2 transition-colors" title="<?= htmlspecialchars($pp['name']) ?>">
                    <div class="text-gray-100 group-hover:text-blue-300 text-sm font-medium truncate transition-colors">
                        <?= htmlspecialchars($pp['name']) ?>
                    </div>
                    <?php if (!empty($pp['notes'])): ?>
                        <div class="text-gray-400 text-xs truncate" title="<?= htmlspecialchars((string)$pp['notes']) ?>">
                            <?= htmlspecialchars((string)$pp['notes']) ?>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>
<?php if ($panelOpen): ?>
<script>if (window.innerWidth >= 768) document.getElementById('content-area')?.classList.add('md:mr-60');</script>
<?php endif; ?>
