<?php

//CLD: 2026-11-27T10:00:00+02:00: the files and folders of $folder (null: the top level), folders first: a folder opens it, a file its page; New folder, Upload (into
//this folder); rows: Rename (folders), Move, Delete (a folder only when empty). A search or a filter looks in every folder (the Folder column then)
$this->pageTitle = $folder ? 'Files: ' . $folder->filename : 'Files';

$here = $folder ? [ 'folder' => $folder->_id ] : [];

$createBtn = '';
if (\V::app()->user->can('File::create')) {
    $createBtn = \V::app()->html->link(
	'<i class="bi bi-folder-plus me-1"></i>New folder',
	\V::app()->createUrl('/file/folder', $here),
	[ 'id' => 'btn-folder', 'class' => 'btn btn-outline-primary btn-sm me-2', 'escape' => false ]
    );
    $createBtn .= \V::app()->html->link(
	'Upload',
	\V::app()->createUrl('/file/upload'),
	[ 'id' => 'btn-upload', 'class' => 'btn btn-primary btn-sm', 'escape' => false ]
    );
    //allowed file types (".png,.jpg,..."); also filters dropped files (app.FileUpload checks `accept`)
    $createBtn .= '<input id="file-input" type="file" hidden multiple accept="' . implode(',', array_map(fn($ext) => '.' . $ext, array_keys(\models\File::$extensions))) . '">';
}

//schema and filtering
$sf = \util\WidgetsUtil::getSchema('File');
//CLD: 2026-11-27T10:00:00+02:00: the name opens the folder, or the file's page (preview, download; link: app.GridView), no View row action
$sf['schema']['filename']['link'] = "{% if (item.kind == 'folder') { %}/file/list?folder={{item._id}}{% } else { %}/file/view/{{item._id}}{% } %}";
$sf['schema']['filename']['icon'] = "{% if (item.kind == 'folder') { %}bi-folder-fill text-warning{% } else { %}bi-file-earmark text-body-secondary{% } %}";
//the folder a row is in: for a search or a filter (the server's `show`)
$sf['schema']['folder'] = [ 'label' => 'Folder', 'type' => 'text', 'filter' => false, 'edit' => false, 'delete' => false, 'show' => false ];
$schema = \util\JSON::encode($sf['schema']);
$filter = \util\JSON::encode($sf['filter']);

$dataUrl = \util\JSON::encode(\V::app()->createUrl('/file/list', $here + [ 'data' => 1 ]));
$folderId = \util\JSON::encode($folder ? (string)$folder->_id : '');
$title = \util\JSON::encode(\V::app()->html->escape($folder ? (string)$folder->filename : 'Files'));

$script = "
+function(){
    const fileList = document.getElementById('files-list');
    //CLD: 2026-11-28T10:00:00Z: an entry of the project added, renamed, moved or deleted elsewhere (another person, a workflow: app.live files::changed): the rows loaded
    //again (app.live.grid); this page's own change (live.own) is not loaded twice
    const live = app.live.grid(fileList, 'files::changed', () => 'rows');

    new app.GridView(fileList, {
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>` + {$title} + `</h5>`,
	dataUrl: {$dataUrl},
	sort: {},
	schema: {
	    readonly: true,
	    data: {$schema},
	},

	filter: {
	    data: {$filter},
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		rename: `{% if (item.kind == 'folder') { %}<a href='/file/rename/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Rename'><i class='bi bi-pencil'></i></a>{% } %}`,
		move: `<a href='/file/move/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Move'><i class='bi bi-folder-symlink'></i></a>`,
		delete: `<a href='/file/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Delete' data-confirm='{% if (item.kind == 'folder') { %}Delete this folder? Only an empty folder is deleted.{% } else { %}Delete this file?{% } %}' data-method='post'><i class='bi bi-trash'></i></a>`,
	    }
	},
	onInit: () => {
	    const fileInput = document.getElementById('file-input');
	    const btnUpload = document.getElementById('btn-upload');
	    var saveLabel = null;

	    const dropZoneMessage = document.getElementById('dropzone-message');

	    btnUpload.addEventListener('click', (e) => {
		e.preventDefault();
		fileInput._fileUpload.browse();
	    });

	    //refresh gv on a new folder, a rename, a move, a delete
	    app.events.on('object::deleted object::created object::updated', (e) => {
		live.own();
		fileList._dataView.reload();
	    })

	    new app.FileUpload(fileInput, {
		url: '/file/upload',
		dropzone: document.getElementById('dropzone'),
		formData: () => {
		    const csrfParam = $('meta[name=csrf-param]').attr('content');
		    const csrfToken = $('meta[name=csrf-token]').attr('content');

		    //into the folder shown
		    var resp = { folder: {$folderId} };
		    if (!csrfParam.length || !csrfToken.length)
			return resp;

		    resp[csrfParam] = csrfToken;
		    return resp;
		},

		onDragEnter: (e) => {
		    fileList.classList.add('d-none');
		    dropZoneMessage.classList.remove('d-none');
		},
		onDragLeave: (e) => {
		    fileList.classList.remove('d-none');
		    dropZoneMessage.classList.add('d-none');
		},
		onDrop: (e) => {
		    fileList.classList.remove('d-none');
		    dropZoneMessage.classList.add('d-none');
		},
		onUploadProgress: (data) => {
		    btnUpload.textContent = data.nLoaded + '/' + data.nFiles + ' ' + Math.floor(data.pctCurrent) + '%';
		},
		onFileUploadError: (item) => {
    		    app.utils.toast({
    			type: 'error',
    			title: item.name,
    			body: item.error.message,
    		    });
		},
		onUploadComplete: () => {
		    live.own();
		    btnUpload.disabled = false;
		    btnUpload.textContent = saveLabel;
		    btnUpload.style.width = null;
		    btnUpload.style.textAlign = null;
		    fileList._dataView.reload();
		},
		onUploadStart: () => {
		    saveLabel = btnUpload.textContent;
		    btnUpload.disabled = true;
		    btnUpload.style.width = '6rem'; //btnUpload.getBoundingClientRect().width + 'px';
		    btnUpload.style.textAlign = 'left';
		},
	    });
	}
    });
}();

";
\V::app()->cs->addPageScript($script);

?>
<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?php
	//Files, then the folders down to this one (each opens it); labels escaped here
	$crumbs = [ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Files', 'href' => $folder ? '/file/list' : null ] ];
	foreach($folder ? array_merge($folder->folders(), [ $folder ]) : [] as $f)
	    $crumbs[] = [ 'label' => \V::app()->html->escape((string)$f->filename), 'href' => $f === $folder ? null : \V::app()->createUrl('/file/list', [ 'folder' => $f->_id ]) ];
	echo (new \sys\html\Breadcrumbs([ 'items' => array_map(fn($c) => array_filter($c, fn($v) => $v !== null), $crumbs) ]))->render();
    ?>
</div>

<div id="dropzone" class="main-body p-4">
    <div id="files-list"></div>
    <div id="dropzone-message" class="text-center border-info border-2 rounded h-100 d-none" style="border-style: dashed">
	<div class="position-relative" style="top:50%;left:50%;transform:translate(-50%);">Drop files to upload</div>
    </div>
</div>

