<?php

$this->pageTitle = 'Manage Data Table: ' . $dt->name;

//schema and filtering
$sf = \util\WidgetsUtil::getSchema($dt->iname);
$schema = \util\JSON::encode($sf['schema']);
$filter = \util\JSON::encode($sf['filter']);

$dataUrl = \V::app()->createUrl('', [ 'id' => $dt->_id, 'data' => 1 ]);

//what this user may do here: the column designer needs DataTable::update, row editing DataTable::writeData (viewing: DataTable::readData)
$canUpdate = \V::app()->user->can('DataTable::update', [ 'object' => $dt ]);
$canWrite = \V::app()->user->can('DataTable::writeData', [ 'object' => $dt ]);

//column designer URLs; the grid replaces __KEY__ with the column key
$createColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/create_column', [ 'id' => $dt->_id ]) : '';
$updateColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/update_column', [ 'id' => $dt->_id, 'key' => '__KEY__' ]) : '';
$deleteColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/delete_column', [ 'id' => $dt->_id, 'key' => '__KEY__' ]) : '';
$readonly = $canUpdate ? 'false' : 'true';

//saved views (named filters, shared by the users who can open the table): list / save / delete URLs; the grid replaces __VIEW__ with the view id
$viewsUrl = \V::app()->createUrl('/datatable/views', [ 'id' => $dt->_id ]);
$createViewUrl = \V::app()->createUrl('/datatable/create_view', [ 'id' => $dt->_id ]);
$deleteViewUrl = \V::app()->createUrl('/datatable/delete_view', [ 'id' => $dt->_id, 'view' => '__VIEW__' ]);

//the Excel export; the grid adds its current search and filter
$exportUrl = \V::app()->createUrl('/datatable/export', [ 'id' => $dt->_id ]);

//row editing URLs; rowActions templates get the row id from {{item._id}}
$createRowUrl = \V::app()->createUrl('/datatable/create_row', [ 'id' => $dt->_id ]);
$updateRowUrl = str_replace('__ROW__', '{{item._id}}', \V::app()->createUrl('/datatable/update_row', [ 'id' => $dt->_id, 'row' => '__ROW__' ]));
$deleteRowUrl = str_replace('__ROW__', '{{item._id}}', \V::app()->createUrl('/datatable/delete_row', [ 'id' => $dt->_id, 'row' => '__ROW__' ]));
//row editing (Create: the row form in a modal, add-row button, edit / delete actions) only with DataTable::writeData
//CLD: 2026-11-21T13:00:00+02:00: with DataTable::writeData also Import (rows from a file or pasted: DatatableController::import_rows) before Add row, and the rows can be
//selected and deleted together (select: DatatableController::delete_rows)
$createBtn = $canWrite ? \V::app()->html->link('<i class="bi bi-upload me-1"></i>Import', \V::app()->createUrl('/datatable/import_rows', [ 'id' => $dt->_id ]), [ 'class' => 'btn btn-outline-info btn-sm', 'escape' => false, 'title' => 'Import rows from a CSV or Excel file, or paste them from a spreadsheet' ])
    . ' ' . \V::app()->html->link('<i class="bi bi-plus-lg me-1"></i>Add row', $createRowUrl, [ 'class' => 'btn btn-primary btn-sm', 'escape' => false ]) : '';
$selectJs = $canWrite ? "{ deleteUrl: '" . \V::app()->createUrl('/datatable/delete_rows', [ 'id' => $dt->_id ]) . "' }" : 'null';
$createRowJs = $canWrite ? "'{$createRowUrl}'" : 'false';
$rowActionsJs = $canWrite ? "{
		    edit: `<a href='{$updateRowUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Edit row'><i class='bi bi-pencil'></i></a>`,
		    delete: `<a href='{$deleteRowUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Delete row' data-confirm='Delete this row?' data-method='post'><i class='bi bi-trash'></i></a>`,
		}" : '{}';

//CLD: 2026-11-21T11:00:00+02:00: no title (the name was put into the script unescaped; the breadcrumb has it); with no rows, how to start (what this user may do);
//a click on a column's name sorts (sort); a rename (the breadcrumb's pencil) shows the new name there (object::updated with the label)
$emptyHint = $canWrite ? ($canUpdate ? 'No rows. Add columns with + in the header, and rows with + below or Add row.' : 'No rows. Add one with + below or Add row.') : 'No rows.';
$emptyHintJs = \util\JSON::encode(\V::app()->html->escape($emptyHint));
$dtIdJs = (string)$dt->_id;
$script = "
+function() {
    //container kept for the reload on row events (onInit)
    const container = document.getElementById('datatables-list');
    new app.GridView(
	container, {
	    create: '{$createBtn}',
	    title: '',
	    emptyLayout: '<div class=\"p-4 text-center text-body-secondary\">' + {$emptyHintJs} + '</div>',
	    sort: {},
	    select: {$selectJs},
	    dataUrl: '{$dataUrl}',
	    schema: {
		//read-only without DataTable::update
		readonly: {$readonly},
		//real column designer URLs
		updateColumnUrl: '{$updateColumnUrl}',
		createColumnUrl: '{$createColumnUrl}',
		deleteColumnUrl: '{$deleteColumnUrl}',
		data: {$schema},
	    },
	    filter: {
		data: {$filter},
		//saved views
		views: {
		    listUrl: '{$viewsUrl}',
		    createUrl: '{$createViewUrl}',
		    deleteUrl: '{$deleteViewUrl}',
		},
	    },
	    //the Export button
	    exporter: { url: '{$exportUrl}' },
	    //the page size kept in this browser, the same for every data table
	    pager: {
		storeKey: 'qflows.datatable.pageSize',
	    },
	    //real row URLs and actions (the grid reads `grid:`)
	    grid: {
		//add-row button and row actions only with DataTable::writeData (set in PHP above)
		createRowUrl: {$createRowJs},
		rowActions: {$rowActionsJs}
	    },
	    //reload rows after add/edit/delete (events sent by the row actions)
	    onInit: function(e) {
		app.events.on('object::deleted object::created object::updated', (e) => {
		    if (e && e.object === 'DataTableDef' && e.oid === '{$dtIdJs}' && e.label !== undefined)
			document.querySelectorAll('.main-header .dt-name').forEach((el) => el.innerHTML = e.label);
		    live.own();
		    container._dataView.reload();
		});
	    }
	}
    );

    //CLD: 2026-11-28T10:00:00Z: a change of this table elsewhere (another person, a workflow, an agent: app.live datatable::changed): its rows loaded again, its columns: the
    //page loaded again (app.live.grid); this page's own change (live.own) is not loaded twice
    const live = app.live.grid(container, 'datatable::changed', (data) => data.table === '{$dtIdJs}' && (data.columns ? 'page' : 'rows'));
}();
";
\V::app()->cs->addPageScript($script);

?>
<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Data Tables', 'href' => '/datatable/list' ],
	//Breadcrumbs prints labels as HTML; escape the user-editable name; with DataTable::update a link to rename it (the pencil on hover, as the designer's name)
	[ 'label' => \util\VString::insert($canUpdate
	    ? '<a href="{:href}" class="crumb-edit text-reset text-decoration-none" title="Rename the data table"><span class="dt-name">{:name}</span><i class="bi bi-pencil crumb-edit-pencil ms-2"></i></a>'
	    : '<span class="dt-name">{:name}</span>', [
	    'href' => \V::app()->createUrl('/datatable/update', [ 'id' => $dt->_id ]),
	    'name' => \V::app()->html->escape((string)$dt->name),
	]) ],
    ]]))->render() ?>
    <?php //a table of another project, open through a share ?>
    <?php if (!$dt->isHome(\V::app()->user->crtProject)): ?>
    <div class="spacer"></div>
    <span class="badge text-bg-info"><i class="bi bi-share me-1"></i>From project <?= \V::app()->html->escape((string)\V::app()->om->findById('Project', $dt->project)) ?><?= $canWrite ? '' : ' (read only)' ?></span>
    <?php endif; ?>
</div>

<div id="datatables-list" class="main-body p-4"></div>
