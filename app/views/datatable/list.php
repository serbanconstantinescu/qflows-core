<?php

$this->pageTitle = 'Data Tables - list';

//CLD: 2026-11-21T11:00:00+02:00: Create is a menu: an empty table (its name asked first: DatatableController::create) or one from a CSV / Excel file (the import dialog, was a
//button in the header); one line, no single quote: it goes into the script's '...' string; the menu above the grid's sticky header row (sticky-top, z-index 1020), which hid its first item
$createBtn = '';
if (\V::app()->user->can('DataTable::create'))
    $createBtn = \util\VString::insert('<div class="dropdown"><button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Create</button>'
	. '<ul class="dropdown-menu dropdown-menu-end" style="z-index: 1030"><li><a class="dropdown-item" href="{:empty}"><i class="bi bi-table me-2"></i>Empty table&hellip;</a></li>'
	. '<li><a class="dropdown-item" href="{:import}"><i class="bi bi-upload me-2"></i>From a CSV or Excel file&hellip;</a></li></ul></div>', [
	'empty' => \V::app()->createUrl('/datatable/create'),
	'import' => \V::app()->createUrl('/datatable/import'),
    ]);

$dataUrl = \V::app()->createUrl('', [ 'data' => 1 ]);
//Share, with DataTable::share (not in a personal project); the second list: the tables shared with this project (open only: the rest is their project's)
$shareUrl = '/share/manage?object=DataTableDef&id={{item._id}}';
$shareAction = $canShare ? "share: `<a href='{$shareUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Share'><i class='bi bi-share'></i></a>`,
		" : '';
$sharedDataUrl = \V::app()->createUrl('', [ 'data' => 1, 'shared' => 1 ]);
//Move, as Delete: it leaves this project
$moveAction = \V::app()->user->can('DataTable::delete') ? "move: `<a href='/share/move?object=DataTableDef&id={{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Move to another project'><i class='bi bi-folder-symlink'></i></a>`,
		" : '';

$script = "
+function(){
    const container = document.getElementById('datatables-list');
    new app.GridView(container, {
	filter: false,
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Data Tables</h5>`,
	dataUrl: '{$dataUrl}',

	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		//_id: { label: 'ID', type: 'object', filter: false, choices: [] },
		//the name opens the table (link: app.GridView)
		name: { label: 'Name', type: 'text', filter: true, link: '/datatable/manage/{{item._id}}' },
		rows: { label: 'Rows', type: 'number', filter: false },
		cols: { label: 'Columns', type: 'number', filter: false },
		//the projects it is shared with
		shares: { label: 'Shared with', type: 'text', filter: false },
		created_at: { label: 'Created', type: 'datetime', filter: true },
	    },
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		//CLD: 2026-11-21T12:00:00+02:00: no Open action: the name opens the table; each with its tooltip; Delete asks with the table's name, rows and the workflows
		//using it (item.confirm: HTML, escaped again for the attribute)
		{$shareAction}{$moveAction}update: `<a href='/datatable/update/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Rename'><i class='bi bi-pencil'></i></a>`,
		delete: `<a href='/datatable/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Delete' data-confirm='{{- item.confirm}}' data-method='post'><i class='bi bi-trash'></i></a>`,
	    }
	},
	onInit: function(e) {
	    app.events.on('object::deleted object::created object::updated', (e) => {
		container._dataView.reload();
	    });
	}
    });
}();
";
\V::app()->cs->addPageScript($script);

if ($sharedCount > 0) {
    \V::app()->cs->addPageScript("
+function(){
    const container = document.getElementById('datatables-shared');
    new app.GridView(container, {
	filter: false,
	create: '',
	title: `<h5 class='h5 mb-0'>Shared with this project</h5>`,
	dataUrl: '{$sharedDataUrl}',
	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		name: { label: 'Name', type: 'text', filter: false, link: '/datatable/manage/{{item._id}}' },
		project: { label: 'From project', type: 'text', filter: false },
		access: { label: 'This project may', type: 'text', filter: false },
		rows: { label: 'Rows', type: 'number', filter: false },
	    },
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		//none: the name opens the table
	    }
	},
    });
}();
");
}

?>
<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Data Tables' ],
    ]]))->render() ?>
</div>

<div class="main-body p-4">
    <div id="datatables-list"></div>
    <?php if ($sharedCount > 0): ?>
    <div id="datatables-shared" class="mt-4"></div>
    <?php endif; ?>
</div>
