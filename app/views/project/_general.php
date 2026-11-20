<?php
//CLD: 2026-11-21T09:00:00+02:00: General: left the icon and name (saved here) and the project's facts (owner, created, personal); right what the project holds (linked to
//their lists when it is the current project: the lists show the current one) and what it shares with other projects or they with it; below, Delete project (Project::delete,
//not personal; only an empty project: ProjectController::delete)
$om = \V::app()->om;
$inProject = [ 'aid' => $project->aid, 'project' => $project->_id ];
$isCurrent = (string)\V::app()->user->crtProject === (string)$project->_id;
$owner = $project->owner ? $om->findById('User', $project->owner) : null;
$projectNames = [];
foreach($om->find('Project', [ 'aid' => $project->aid ]) as $p)
    $projectNames[(string)$p->_id] = (string)$p->name;

//what it holds: [ label, count, list url ]; the first five keep it from being deleted
$contents = [
    [ 'workflows', $om->count('Workflow', $inProject), null ],
    [ 'credentials and variables', $om->count('Credential', $inProject), '/credential/list' ],
    [ 'data tables', $om->count('DataTableDef', $inProject), '/datatable/list' ],
    [ 'knowledge bases', $om->count('KnowledgeBase', $inProject), '/knowledge/list' ],
    [ 'files', $om->count('File', $inProject), '/file/list' ],
];
$blocking = array_filter($contents, fn($c) => $c[1] > 0);

//shared: by this project (its items with shares) and with it (other projects' items shared with this one)
$sharedBy = [];
$sharedWith = [];
foreach([ 'Workflow' => 'bi-diagram-3', 'Credential' => 'bi-key', 'DataTableDef' => 'bi-table' ] as $object => $icon) {
    foreach($om->find($object, $inProject + [ 'shares.0' => [ '$exists' => true ] ], [ 'sort' => [ 'name' => 1 ] ]) as $o)
	$sharedBy[] = [ $icon, (string)$o->name, implode(', ', array_map(fn($s) => $projectNames[(string)$s['project']] ?? '?', (array)$o->shares)) ];
    foreach($om->find($object, [ 'aid' => $project->aid, 'shares.project' => $project->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $o)
	$sharedWith[] = [ $icon, (string)$o->name, $projectNames[(string)$o->project] ?? '?' ];
}
?>

<div class="row g-4 pt-4">
    <div class="col-12 col-xl-6">
	<?= \V::app()->form->create($project, [ 'action' => \V::app()->createUrl('', [ 'id' => $project->_id, '__tab' => $tab ]) ]) ?>
	<div class="mb-2">
	    <label class="form-label">Icon and project name</label>
	    <div class="d-flex gap-2">
		<?= $project->schema['icon']->input([ 'bare' => true ]); ?>
		<?= $project->schema['name']->input([ 'bare' => true ]); ?>
	    </div>
	</div>
	<button class="btn btn-primary mt-2" type="submit">Save</button>
	<?= \V::app()->form->end(); ?>

	<?php
	$factTpl = '<dt class="col-4 fw-normal text-body-secondary">{:label}</dt><dd class="col-8 mb-1">{:value}</dd>';
	$facts = \util\VString::insert($factTpl, [ 'label' => 'Owner', 'value' => $owner ? \V::app()->html->escape((string)$owner) : '&mdash;' ]);
	if ($project->created_at)
	    $facts .= \util\VString::insert($factTpl, [ 'label' => 'Created', 'value' => \V::app()->html->escape(\util\Format::datetime($project->created_at)) ]);
	$facts .= \util\VString::insert($factTpl, [ 'label' => 'Kind', 'value' => $project->personal ? 'Personal: only you' : 'Shared with its members' ]);
	echo \util\VString::insert('<dl class="row small mt-4 mb-0">{:facts}</dl>', [ 'facts' => $facts ]);
	?>
    </div>

    <div class="col-12 col-xl-6">
	<?php
	$rowTpl = '<li class="list-group-item d-flex justify-content-between align-items-center py-1">{:label}<span class="badge rounded-pill text-bg-secondary">{:count}</span></li>';
	$linkTpl = '<a href="{:href}">{:label}</a>';
	$rows = '';
	foreach($contents as [ $label, $count, $url ])
	    $rows .= \util\VString::insert($rowTpl, [
		'label' => $url && $isCurrent ? \util\VString::insert($linkTpl, [ 'href' => $url, 'label' => ucfirst($label) ]) : ucfirst($label),
		'count' => $count,
	    ]);
	$rows .= \util\VString::insert($rowTpl, [
	    'label' => \util\VString::insert($linkTpl, [ 'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'members' ]), 'label' => 'Members' ]),
	    'count' => count((array)$project->members),
	]);
	echo \util\VString::insert('<h6 class="mb-2">In this project</h6><ul class="list-group small mb-4">{:rows}</ul>', [ 'rows' => $rows ]);

	if (!$project->personal) {
	    $shareTpl = '<li class="list-group-item py-1"><i class="bi {:icon} me-2 text-body-secondary"></i>{:name} <span class="text-body-secondary">{:where}</span></li>';
	    $list = '';
	    foreach($sharedBy as [ $icon, $name, $to ])
		$list .= \util\VString::insert($shareTpl, [ 'icon' => $icon, 'name' => \V::app()->html->escape($name), 'where' => '&rarr; ' . \V::app()->html->escape($to) ]);
	    foreach($sharedWith as [ $icon, $name, $from ])
		$list .= \util\VString::insert($shareTpl, [ 'icon' => $icon, 'name' => \V::app()->html->escape($name), 'where' => 'from ' . \V::app()->html->escape($from) ]);
	    echo \util\VString::insert('<h6 class="mb-2">Shared</h6>{:list}', [
		'list' => $list ? '<ul class="list-group small">' . $list . '</ul>'
		    : '<p class="small text-body-secondary mb-0">Nothing is shared with other projects, or by them with this one.</p>',
	    ]);
	}
	?>
    </div>
</div>

<?php
if (!$project->personal && \V::app()->user->can('Project::delete', [ 'object' => $project ])) {
    $deleteTpl = '
<div class="border border-danger-subtle rounded p-3 mt-4">
    <h6 class="text-danger mb-1"><i class="bi bi-trash me-2"></i>Delete project</h6>
    {:body}
</div>
';
    if ($blocking) {
	$body = \util\VString::insert('<p class="small mb-0">Only an empty project can be deleted. This one still has {:what}: move workflows, credentials and data tables to another project (Move to project), and delete the rest.</p>', [
	    'what' => implode(', ', array_map(fn($c) => $c[1] . ' ' . $c[0], $blocking)),
	]);
    } else {
	$body = \util\VString::insert('
    <p class="small mb-2">The project is empty. Deleting it also removes its MCP tokens and pending invitations, and ends what other projects share with it. Its members move to their personal project. This cannot be undone.</p>
    <form method="post" action="{:action}" class="d-flex flex-wrap gap-2 align-items-end">
	<div>
	    <label class="form-label small mb-1" for="project-delete-confirm">Type the project\'s name, <b>{:name}</b>, to confirm</label>
	    <input type="text" id="project-delete-confirm" name="confirm" class="form-control form-control-sm" required autocomplete="off" style="min-width: 16rem">
	</div>
	<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Delete project</button>
    </form>', [
	    'action' => \V::app()->createUrl('/project/delete', [ 'id' => $project->_id ]),
	    'name' => \V::app()->html->escape((string)$project->name),
	]);
    }
    echo \util\VString::insert($deleteTpl, [ 'body' => $body ]);
}
