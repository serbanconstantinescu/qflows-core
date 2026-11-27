<?php
//load current project
$crtProject = \V::app()->om->findById('Project', \V::app()->user->crtProject);
$crtAccount = \V::app()->var('rt.account');

//get other projects
$query = [
    'aid' => $crtAccount->_id,
    '$or' => [
	[ 'owner' => \V::app()->user->_id ],
	[ 'members.' . \V::app()->user->_id => [ '$ne' => null ] ],
    ],
];
$projects = \V::app()->om->find('Project', $query);
$dd_items = [];
foreach($projects as $p) {
    if ($p->_id == $crtProject->_id)
	continue;
    $dd_items[] = \V::app()->html->link(
	$p->schema['icon']->display() . ' ' . $p->schema['name']->display(),
	\V::app()->createUrl('/site/switch_project', [ 'id' => $p->_id ]),
	[ 'no-pjax' => true, 'class' => 'dropdown-item', 'escape' => false ]
    );
}
$dd_items = implode('', $dd_items);

\V::app()->cs->addPageScript("app.sidebar.highlight();");

?>

<!-- header -->
<div class="sidebar-header p-2">
    <div>
	<a href="<?= \V::app()->createUrl('/' . \V::app()->config('default.controller')) ?>" no-pjax class="d-inline text-decoration-none py-1 pe-2">
	    <img src="/img/app-logo.svg" style="height:1rem">
	</a>
	<?php if (empty($dd_items)): ?>
	    <span class="py-1">
		<?= $crtProject->schema['icon']->display() . ' ' . $crtProject->schema['name']->display() ?>
	    </span>
	<?php else: ?>
	<div class="dropdown d-inline">
	    <a href="#" class="py-1 dropdown-toggle text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false">
		<?= $crtProject->schema['icon']->display() . ' ' . $crtProject->schema['name']->display() ?>
	    </a>
	    <div class="dropdown-menu">
		<?= $dd_items ?>
	    </div>
	</div>
	<?php endif; ?>
    </div>
    <div class="sidebar-header-actions">
	<!--
	<a href="#" class="btn-sidebar-search p-1 text-decoration-none" id="sidebarSearchBtn" data-bs-toggle="modal" data-bs-target="#searchModal" title="Search">
	    <i class="bi bi-search"></i>
	</a>
	-->

	<?php
	//current project
	if (\V::app()->user->can('Project::update', [ 'object' => $crtProject ])) {
	    echo \V::app()->html->link(
		'<i class="bi bi-gear"></i>',
		\V::app()->createUrl('/project/settings'),
		//CLD: 2026-11-20T16:00:00+02:00: a tooltip: the gear alone did not say what it opens
		[ 'class' => 'p-1', 'escape' => false, 'title' => 'Project settings' ]
	    );
	}
	?>

	<div class="dropdown">
	    <a href="#" class="p-1" id="newMenuBtn" data-bs-toggle="dropdown" aria-expanded="false" title="New">
		<i class="bi bi-plus-lg"></i>
	    </a>
	    <div class="dropdown-menu py-0 bg-white" aria-labelledby="newMenuBtn">
		<a class="dropdown-item px-2" href="/chat/create"><i class="bi bi-chat-dots"></i> New chat</a>
		<?php if (\V::app()->user->can('Project::create')): ?>
		<a class="dropdown-item px-2" href="/project/create" data-method="post"><i class="bi bi-folder2"></i> New project</a>
		<?php endif; ?>
	    </div>
	</div>

	<a href="#" class="btn-collapse-sidebar p-1" title="Collapse sidebar">
	    <i class="bi bi-layout-sidebar-inset"></i>
	</a>
    </div>
</div>

<!-- body -->
<div class="sidebar-body">
    <!-- PRIMARY VIEW -->
    <div class="sidebar-view sidebar-view-primary">

	<!-- top menu -->
	<div class="flex-shrink-0 px-2">
	    <nav class="nav flex-column d-flex min-height-0 overflow-hidden flex-fill">
		<?php
		echo \V::app()->html->link(
		    '<i class="bi bi-speedometer"></i> ' . \V::t('Overview'),
		    '/site/index',
		    [ 'class' => 'nav-link', 'escape' => false ]
		);
		echo \V::app()->html->link(
		    '<i class="bi bi-ui-checks"></i> Forms',
		    '/form/list',
		    [ 'class' => 'nav-link', 'escape' => false ]
		);
		echo \V::app()->html->link(
		    '<i class="bi bi-chat-dots"></i> Agents',
		    '/agent/list',
		    [ 'class' => 'nav-link', 'escape' => false ]
		);
		$openTasks = count(\models\wf\WfTask::openFor(\V::app()->user, $crtProject->aid, null));
		$badge = $openTasks ? \util\VString::insert('<span class="badge rounded-pill text-bg-primary ms-1">{:n}</span>', [ 'n' => $openTasks ]) : '';
		echo \V::app()->html->link(
		    \util\VString::insert('<i class="bi bi-person-check"></i> My tasks {:badge}', [
			'badge' => $badge,
		    ]),
		    '/task/list',
		    [ 'class' => 'nav-link', 'escape' => false ]
		);
		?>
	    </nav>
	</div>

	<!-- scrollable menu -->
	<div class="d-flex flex-column flex-fill min-height-0">
	    <?php //CLD: 2026-11-16T16:00:00+02:00: as the docs' sidebar: each heading (.sidebar-heading, with an icon) over its links (.sidebar-group: indented, a line on their left) ?>
	    <div class="overflow-y-auto min-height-0 px-2">
		<div class="sidebar-heading"><i class="bi bi-briefcase"></i><span>Project workspace</span></div>
		<ul class="nav flex-column sidebar-group">

		<?php
		if (\V::app()->user->can('Credential::list')) {
		    echo \V::app()->html->link(
			'<i class="bi bi-key"></i> Credentials & variables',
			'/credential/list',
			[ 'class' => 'nav-link', 'escape' => false ]
		    );
		}

		if (\V::app()->user->can('DataTable::list')) {
		    echo \V::app()->html->link(
			'<i class="bi bi-table"></i> Data Tables',
			'/datatable/list',
			[ 'class' => 'nav-link', 'escape' => false ]
		    );
		}

		if (\V::app()->user->can('KnowledgeBase::list')) {
		    echo \V::app()->html->link(
			'<i class="bi bi-journal-bookmark"></i> Knowledge bases',
			'/knowledge/list',
			[ 'class' => 'nav-link', 'escape' => false ]
		    );
		}

		if (\V::app()->user->can('File::list')) {
		    echo \V::app()->html->link(
			'<i class="bi bi-files"></i> Files',
			'/file/list',
			[ 'class' => 'nav-link', 'escape' => false ]
		    );
		}

		//the MCP server link (its tokens), when the project's MCP server is on (Account::mcpEnabled)
		if ($crtAccount->mcpEnabled($crtProject->_id) && \V::app()->user->can('McpToken::list')) {
		    echo \V::app()->html->link(
			'<i class="bi bi-plug"></i> MCP server',
			'/token/list',
			[ 'class' => 'nav-link', 'escape' => false ]
		    );
		}

		\V::app()->hooks->applyHook('layout:aside-project-menu', [ $crtProject ]);
		echo '</ul>';

		$wfCreateUrl = '';
		if (\V::app()->user->can('Workflow::create')) {
		    $wfCreateUrl = \V::app()->html->link(
			'<i class="bi bi-plus-lg"></i>',
			'/workflow/create',
			[ 'class' => 'ms-auto', 'title' => 'New workflow', 'escape' => false, 'data-method' => 'post' ]
		    );
		}
		$canReadWf = \V::app()->user->can('Workflow::read');
		if ($canReadWf || $wfCreateUrl !== '') {
		    echo "
		    <div class='sidebar-heading'><i class='bi bi-diagram-3'></i><span>Workflows</span>{$wfCreateUrl}</div>
		    ";
		}
		$wfs = $canReadWf ? iterator_to_array(\V::app()->om->find('Workflow', [ 'project' => $crtProject->_id]), false) : [];
		//CLD: 2026-11-27T14:00:00+02:00: in the project's order (workflow_order, dragged by the grip: WorkflowController::order, app.js), the others after them as created;
		//who can change the project's workflows (Workflow::update) gets the grips
		$rank = array_flip(array_map('strval', iterator_to_array($crtProject->workflow_order ?? [], false)));
		usort($wfs, fn($a, $b) => [ $rank[(string)$a->_id] ?? PHP_INT_MAX, (string)$a->_id ] <=> [ $rank[(string)$b->_id] ?? PHP_INT_MAX, (string)$b->_id ]);
		$canOrderWf = $canReadWf && count($wfs) > 1 && \V::app()->user->can('Workflow::update', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ]);
		echo $canOrderWf
		    ? \util\VString::insert('<ul class="nav flex-column sidebar-group sidebar-sortable" data-order-url="{:url}">', [ 'url' => \V::app()->createUrl('/workflow/order') ])
		    : '<ul class="nav flex-column sidebar-group">';

		//CLD: 2026-11-11T12:00:00+02:00: Duplicate in each workflow's menu: Workflow::create in this project, as the designer's $canDuplicate
		$canDuplicateWf = \V::app()->user->can('Workflow::create', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ]);
		foreach($wfs as $wf) {
		    $url = \V::app()->createUrl('/workflow/index', [ 'id' => $wf->_id ]);
		    $name = $wf->schema['icon']->display() . ' ' . $wf->schema['name']->display();
		    //published workflows (started by their triggers) get a green dot
		    $published = $wf->status === \models\Workflow::PUBLISHED;
		    if ($published)
			$name .= " <i class='bi bi-circle-fill text-success ms-1' style='font-size: .45rem; vertical-align: middle' title='Published'></i>";

		    //CLD: 2026-11-20T12:00:00+02:00: a menu (three dots) per workflow: Edit (icon and name, the designer's gear dialog), Publish (the designer's Publish dialog: shown when
		    //not published, or the draft changed since), Unpublish (published), Share, Move, Duplicate (the saved draft), Delete; each with its permission (as the designer's
		    //status menu), with the designer's confirmations; none allowed: no menu
		    $items = '';
		    if (\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
			$items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}"><i class="bi bi-pencil me-2"></i>Edit&hellip;</a>', [
			    'href' => \V::app()->createUrl('/workflow/update_name', [ 'id' => $wf->_id ]),
			]);
		    if (\V::app()->user->can('Workflow::publish', [ 'object' => $wf ])) {
			$active = $published ? $wf->publishedVersion() : null;
			if (!$published || !$active || !$wf->draftMatches($active))
			    $items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}"><i class="bi bi-broadcast me-2"></i>Publish&hellip;</a>', [
				'href' => \V::app()->createUrl('/workflow/publish', [ 'id' => $wf->_id ]),
			    ]);
			if ($published)
			    $items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}" data-method="post" data-confirm="Unpublish: its trigger stops starting runs?"><i class="bi bi-pause-circle me-2"></i>Unpublish</a>', [
				'href' => \V::app()->createUrl('/workflow/unpublish', [ 'id' => $wf->_id ]),
			    ]);
		    }

		    //Share (ShareController), not in a personal project
		    if (!$crtProject->personal && \V::app()->user->can('Workflow::share', [ 'object' => $wf ]))
			$items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}"><i class="bi bi-share me-2"></i>Share&hellip;</a>', [
			    'href' => \V::app()->createUrl('/share/manage', [ 'object' => 'Workflow', 'id' => $wf->_id ]),
			]);

		    //Move (ShareController::move), as Delete: it leaves this project
		    if (\V::app()->user->can('Workflow::delete', [ 'object' => $wf ]))
			$items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}"><i class="bi bi-folder-symlink me-2"></i>Move to project&hellip;</a>', [
			    'href' => \V::app()->createUrl('/share/move', [ 'object' => 'Workflow', 'id' => $wf->_id ]),
			]);

		    if ($canDuplicateWf)
			$items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}" data-method="post" data-confirm="Create a new workflow from the saved draft?"><i class="bi bi-copy me-2"></i>Duplicate</a>', [
			    'href' => \V::app()->createUrl('/workflow/duplicate', [ 'id' => $wf->_id ]),
			]);

		    if (\V::app()->user->can('Workflow::delete', [ 'object' => $wf ]))
			$items .= \util\VString::insert('<a class="dropdown-item text-danger px-2" href="{:href}" data-method="post" data-confirm="Delete this workflow with all its versions and executions? This cannot be undone."><i class="bi bi-trash me-2"></i>Delete</a>', [
			    'href' => \V::app()->createUrl('/workflow/delete', [ 'id' => $wf->_id ]),
			]);
		    //the menu opens fixed (popper strategy), so the scrolling list does not clip it
		    $menu = $items === '' ? '' : "
			<div class='dropdown flex-shrink-0 sidebar-item-menu'>
			    <a href='#' class='px-2 py-1 d-block text-body-secondary' data-bs-toggle='dropdown' data-bs-popper-config='{\"strategy\":\"fixed\"}' aria-expanded='false' title='Actions'><i class='bi bi-three-dots-vertical small'></i></a>
			    <ul class='dropdown-menu dropdown-menu-end py-0 bg-white'>{$items}</ul>
			</div>";
		    $grip = $canOrderWf ? "<span class='sidebar-item-grip flex-shrink-0 text-body-secondary' title='Drag to reorder'><i class='bi bi-grip-vertical small'></i></span>" : '';
		    echo "
		    <li class='nav-item d-flex align-items-center' data-id='{$wf->_id}'>
			{$grip}<a href='{$url}' class='nav-link flex-fill text-truncate'>{$name}</a>{$menu}
		    </li>
		    ";
		}

		//the workflows other projects share with this one: what they offer, in a dialog (WorkflowController::shared)
		$sharedWfs = array_filter(iterator_to_array(\V::app()->om->find('Workflow', [ 'aid' => $crtProject->aid, 'shares.project' => $crtProject->_id ], [ 'sort' => [ 'name' => 1 ] ]), false),
		    fn($w) => \V::app()->user->can('Workflow::viewShared', [ 'object' => $w ]));
		echo '</ul>';
		if ($sharedWfs)
		    echo '<div class="sidebar-heading"><i class="bi bi-share"></i><span>Shared workflows</span></div><ul class="nav flex-column sidebar-group">';
		foreach($sharedWfs as $wf)
		    echo \util\VString::insert('<li class="nav-item"><a href="{:href}" class="nav-link text-truncate" title="{:title}"><i class="bi bi-share me-1"></i>{:name}</a></li>', [
			'href' => \V::app()->createUrl('/workflow/shared', [ 'id' => $wf->_id ]),
			'title' => \V::app()->html->escape('From project ' . \V::app()->om->findById('Project', $wf->project)),
			'name' => \V::app()->html->escape((string)$wf->name),
		    ]);
		if ($sharedWfs)
		    echo '</ul>';
		?>
	    </div>
	</div>

	<!-- two-level menu, pinned to the bottom of the sidebar -->
	<div class="sidebar-bottom-menu">
	    <div class="d-flex align-items-center justify-content-between px-2 py-2">
		<span class="small text-muted">v<?= \V::app()->html->escape(\data\Version::NUMBER) ?></span>
		<?php //CLD: 2026-11-21T10:00:00+02:00: the documentation (opens the sidebar's Documentation view, as Settings) and the theme, one button that goes auto > light > dark
		//(app.theme; the icon shown follows the choice, theme _app.scss), on the version line ?>
		<a href="/docs/index" class="btn btn-sm btn-outline-secondary py-0 ms-auto me-1 open-docs-menu" title="Documentation"><i class="bi bi-book" aria-hidden="true"></i><span class="visually-hidden">Documentation</span></a>
		<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-theme-cycle title="Theme: automatic, light or dark (click to change)" aria-label="Color theme (change)">
		    <i class="bi bi-circle-half theme-icon-auto" aria-hidden="true"></i><i class="bi bi-sun theme-icon-light" aria-hidden="true"></i><i class="bi bi-moon-stars theme-icon-dark" aria-hidden="true"></i>
		</button>
	    </div>
	</div>

    </div> <!-- sidebar-primary-view -->

    <!-- SECONDARY VIEW -->
    <div class="sidebar-view sidebar-view-secondary d-none">
	<nav class="nav flex-column px-2">
	    <a href="#" class="nav-link close-secondary-menu" style="border-bottom: var(--bs-border-width) solid var(--bs-border-color)">
		<i class="bi bi-arrow-left"></i> <b>Settings</b>
	    </a>
	    <a href="/user/profile" class="nav-link">
		<i class="bi bi-person-circle"></i> Personal
	    </a>
	    <?php
	    if (\V::app()->auth->getUserRole()->getPermissionScope('User::read') === 'global') {
		echo '<a href="/user/list" class="nav-link"><i class="bi bi-person-vcard"></i> Users</a>';
	    }
	    if (\V::app()->user->can('Role::list')) {
		echo '<a href="/role/list" class="nav-link"><i class="bi bi-person-lines-fill"></i> Roles</a>';
	    }
	    if (\V::app()->user->can('Account::update') || \V::app()->user->can('Account::usage')) {
		echo '<a href="/account/settings" class="nav-link"><i class="bi bi-house-gear"></i> Account settings</a>';
	    }
	    if (\V::app()->user->can('Account::update')) {
		echo '<a href="/assistant/settings" class="nav-link"><i class="bi bi-stars"></i> Assistant</a>';
		echo '<a href="/knowledge/settings" class="nav-link"><i class="bi bi-journal-bookmark"></i> Knowledge bases</a>';
		echo '<a href="/ldap/settings" class="nav-link"><i class="bi bi-diagram-3"></i> LDAP sign-in</a>';
		echo '<a href="/saml/settings" class="nav-link"><i class="bi bi-shield-lock"></i> SAML single sign-on</a>';
	    }
	    //the installation's services (util\Services::allowed: the owners; with the MultiTenant plugin, its super-admins)
	    //only with the MultiTenant plugin (its super-admins' page); without it the services are a tab of the account's settings
	    if (\util\Services::adminPlugin() && \util\Services::allowed()) {
		echo '<a href="/services/index" class="nav-link"><i class="bi bi-gear-wide-connected"></i> Services</a>';
		//the installation's tool servers (util\ToolServers: mcp.json), the super-admins' page; without the plugin a tab of the account's settings
		echo '<a href="/toolservers/index" class="nav-link"><i class="bi bi-plug"></i> Tool servers</a>';
	    }

	    \V::app()->hooks->applyHook('layout:settings-menu');

	    if (\V::app()->user->can('AuditLog::list', [ 'object' => \V::app()->om->create('AuditLog', [ 'aid' => $crtAccount->_id, 'project' => null ]) ]))
		echo "<a href='/audit/list?scope=system' class='nav-link'><i class='bi bi-journal-text'></i> System audit log</a>";
	    if (\V::app()->user->can('ExecutionLog::list', [ 'object' => \V::app()->om->create('ExecutionLog', [ 'aid' => $crtAccount->_id, 'project' => null ]) ]))
		echo "<a href='/audit/list?log=execution&scope=system' class='nav-link'><i class='bi bi-activity'></i> System execution log</a>";
	    ?>
	    <form method="post" action="/site/logout" no-pjax class="m-0">
		<input type="hidden" name="<?= \V::app()->csrf->csrf_key ?>" value="<?= \V::app()->html->escape(\V::app()->csrf->getToken(false)) ?>">
		<button type="submit" class="nav-link w-100 text-start border-0 bg-transparent">
		    <i class="bi bi-box-arrow-right"></i> Logout
		</button>
	    </form>
	</nav>
    </div>

    <!-- DOCUMENTATION VIEW: the contents (DocsController::nav), fetched by app.sidebar when the view opens -->
    <div class="sidebar-view sidebar-view-docs d-none">
	<nav class="nav flex-column px-2 flex-shrink-0">
	    <a href="#" class="nav-link close-secondary-menu" style="border-bottom: var(--bs-border-width) solid var(--bs-border-color)">
		<i class="bi bi-arrow-left"></i> <b>Documentation</b>
	    </a>
	</nav>
	<nav class="docs-nav overflow-y-auto flex-fill min-height-0 px-2 pb-3" aria-label="Documentation"></nav>
    </div>
</div>

<!-- footer -->
<a href="#" class="sidebar-footer open-secondary-menu text-decoration-none p-2">
    <div class="avatar avatar-md">
	<?= \V::app()->user->avatar('md') ?>
    </div>
    <div class="flex-grow-1 min-width-0">
	<div class="text-truncate"><?= \V::app()->html->escape((string)\V::app()->user->name) ?></div>
	<div class="text-muted small"><?= \V::app()->html->escape((string)\V::app()->om->findById('Role', \V::app()->user->role)->name) ?></div>
    </div>

    <div class="float-end mb-3">
	<i class="bi bi-chevron-up float-end"></i>
    </div>
</a>

<div class="sidebar-resize-handle"></div>

