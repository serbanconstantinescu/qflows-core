<?php
$this->pageTitle = 'My tasks';
//CLD: 2026-11-28T10:00:00Z: loaded again when a task is created, completed, expires or is cancelled (app.live: tasks::changed); the page's element stays (its handler with it)
\V::app()->cs->addPageScript(<<<'JS'
(() => {
    const page = document.getElementById('task-list-page');
    app.live.on(page, 'tasks::changed live::resync', () => {
	fetch('/task/list', { credentials: 'same-origin', headers: { 'X-PJAX': 'true', 'X-Requested-With': 'XMLHttpRequest' } })
	    .then((r) => r.ok ? r.json() : null)
	    .then((data) => {
		const fresh = data && typeof data.content === 'string' ? new DOMParser().parseFromString(data.content, 'text/html').getElementById('task-list-page') : null;
		if (fresh && page.isConnected)
		    page.innerHTML = fresh.innerHTML;
	    })
	    .catch(() => {});
    });
})();
JS);
echo "<div class='p-3' id='task-list-page'><h4 class='mb-3'><i class='bi bi-person-check me-2'></i>My tasks</h4>";
if (!$tasks) {
    echo "<div class='text-muted'>No open tasks for you.</div></div>";
    return;
}
$rowTpl = '
<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="/task/{:id}">
    <i class="bi bi-person-check text-primary"></i>
    <span class="flex-fill">{:title}<span class="text-muted small ms-2">{:where}</span></span>
    {:due}{:created}
</a>
';
echo "<div class='list-group'>";
foreach($tasks as $t) {
    $created = \util\Format::datetime($t->created_at ?? null);
    $due = \util\Format::datetime($t->due_at);
    echo \util\VString::insert($rowTpl, [
	'id' => \V::app()->html->escape((string)$t->_id),
	'title' => \V::app()->html->escape((string)$t->title),
	'where' => \V::app()->html->escape(trim(($projects[(string)$t->project] ?? '') . ' / ' . ($names[(string)$t->wf] ?? ''), ' /')),
	'due' => $due !== '' ? \util\VString::insert('<span class="badge text-bg-warning">due {:v}</span>', [ 'v' => \V::app()->html->escape($due) ]) : '',
	'created' => $created !== '' ? \util\VString::insert('<span class="text-muted small">{:v}</span>', [ 'v' => \V::app()->html->escape($created) ]) : '',
    ]);
}
echo "</div></div>";
