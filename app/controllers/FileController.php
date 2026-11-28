<?php

namespace controllers;

class FileController extends \sys\BaseController {

    use DataTrait;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	//the request's account (rt.account, set at sign-in), for the actions
	$this->account = \V::app()->config('rt.account');

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);

    }

    //CLD: 2026-11-27T10:00:00+02:00: the files and folders of folder ?folder= (none: the top level), the folders first; a search (names) or a filter (files only) looks in
    //every folder (the rows then say which: `folder`, shown by the grid's `show`)
    public function list($req, $resp) {
	if (!\V::app()->user->can('File::list'))
	    \V::app()->error(403, 'Unauthorized');

	$folder = $this->folderOrError($req->query['folder'] ?? '');

	if (!empty($req->query['data'])) {
	    $everywhere = trim((string)($req->query['__search'] ?? '')) !== '' || !empty($req->query['__filter']);
	    $query = [ 'metadata.project' => $this->project->_id ];
	    if (!$everywhere)
		$query['metadata.folder'] = $folder?->_id;
	    //the filters are on the files' fields (type, size, owner): no folders
	    if (!empty($req->query['__filter']))
		$query['__variant'] = [ '$ne' => 'Folder' ];
	    //folders first; by name when no column is chosen
	    $sortFirst = [ '__variant' => -1 ] + (empty($req->query['__sortField']) ? [ 'filename' => 1 ] : []);
	    //the paths of the folders the rows are in, once each
	    $paths = [];
	    $more = function($o) use (&$paths) {
		$id = (string)($o->metadata['folder'] ?? '');
		if (!isset($paths[$id]))
		    $paths[$id] = $id === '' ? '' : $o->folderPath();
		return [ 'folder' => $paths[$id], 'kind' => $o instanceof \models\Folder ? 'folder' : 'file' ];
	    };
	    $response = $this->__data('FileEntry', $query, $sortFirst, $more);
	    $response['show'] = [ 'folder' => $everywhere ];
	    $resp->sendJSON($response);
	}

	$this->render('list', compact('folder'));
    }

    //the folder of the current project with _id $id (a Folder); null for '' (the top level); 404 for another one
    private function folderOrError($id) {
	if ((string)$id === '')
	    return null;
	$oid = \V::app()->om->to_oid($id);
	$folder = $oid && $this->project ? \V::app()->om->findOne('Folder', [ '_id' => $oid, 'metadata.aid' => $this->account->_id, 'metadata.project' => $this->project->_id ]) : null;
	if (empty($folder))
	    \V::app()->error(404, 'Not found');
	return $folder;
    }

    //a new folder in folder ?folder= (none: the top level): its name in a dialog
    public function folder($req, $resp) {
	if (!\V::app()->user->can('File::create'))
	    \V::app()->error(403, 'Unauthorized');
	$parent = $this->folderOrError($req->query['folder'] ?? '');

	$name = '';
	$error = null;
	if ($req->is('post')) {
	    $name = trim((string)($req->data['name'] ?? ''));
	    try {
		\models\Folder::add($this->account->_id, $this->project->_id, $parent?->_id, $name, \V::app()->user->_id);
		$this->addToast('success', 'OK', 'Folder created!');
		$this->addEvent('object::created', [ 'object' => 'Folder' ]);
		$this->sendEvents();
	    } catch (\RuntimeException $e) {
		$error = $e->getMessage();
	    }
	}
	$this->render('folder', [ 'title' => 'New folder', 'button' => 'Create', 'name' => $name, 'error' => $error,
	    'action' => \V::app()->createUrl('/file/folder', $parent ? [ 'folder' => $parent->_id ] : []) ]);
    }

    //a folder's new name, in a dialog
    public function rename($req, $resp) {
	$folder = $this->entryOrError('File::update');
	if (!$folder instanceof \models\Folder)
	    \V::app()->error(404, 'Not found');

	$name = (string)$folder->filename;
	$error = null;
	if ($req->is('post')) {
	    $name = trim((string)($req->data['name'] ?? ''));
	    try {
		$folder->rename($name);
		$this->addToast('success', 'OK', 'Folder renamed!');
		$this->addEvent('object::updated', [ 'object' => 'Folder', 'oid' => (string)$folder->_id ]);
		$this->sendEvents();
	    } catch (\RuntimeException $e) {
		$error = $e->getMessage();
	    }
	}
	$this->render('folder', [ 'title' => 'Rename folder', 'button' => 'Rename', 'name' => $name, 'error' => $error,
	    'action' => \V::app()->createUrl('/file/rename', [ 'id' => $folder->_id ]) ]);
    }

    //a file or a folder moved to another folder (or the top level), chosen in a dialog; a folder not into itself or a folder inside it
    public function move($req, $resp) {
	$entry = $this->entryOrError('File::update');
	$isFolder = $entry instanceof \models\Folder;

	$error = null;
	if ($req->is('post')) {
	    $target = $this->folderOrError((string)($req->data['target'] ?? ''));
	    try {
		if ($isFolder)
		    $entry->moveTo($target);
		else
		    \V::app()->om->updateOne('File', [ '_id' => $entry->_id, 'metadata.aid' => $this->account->_id ],
			[ '$set' => [ 'metadata.folder' => $target?->_id, 'metadata.updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
		//CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
		if (!$isFolder)
		    \models\FileEntry::pushChanged($this->account->_id, $this->project->_id ?? null);
		$this->addToast('success', 'OK', ($isFolder ? 'Folder' : 'File') . ' moved!');
		$this->addEvent('object::updated', [ 'object' => $isFolder ? 'Folder' : 'File', 'oid' => (string)$entry->_id ]);
		$this->sendEvents();
	    } catch (\RuntimeException $e) {
		$error = $e->getMessage();
	    }
	}

	//every folder of the project with its path, by path; for a folder not itself and the ones inside it
	$folders = [];
	foreach(\V::app()->om->find('Folder', [ 'metadata.aid' => $this->account->_id, 'metadata.project' => $this->project->_id ]) as $f)
	    $folders[(string)$f->_id] = $f;
	$paths = [];
	foreach($folders as $id => $f) {
	    $names = [];
	    $skip = false;
	    for ($at = $f, $n = 0; $at && $n < \models\Folder::MAX_DEPTH; $at = $folders[(string)($at->metadata['folder'] ?? '')] ?? null, $n++) {
		if ($isFolder && (string)$at->_id === (string)$entry->_id)
		    $skip = true;
		array_unshift($names, (string)$at->filename);
	    }
	    if (!$skip)
		$paths[$id] = implode(' / ', $names);
	}
	natcasesort($paths);
	$current = (string)($entry->metadata['folder'] ?? '');
	$this->render('move', compact('entry', 'isFolder', 'paths', 'current', 'error'));
    }

    //the file or folder ?id= of the current account and project (404 otherwise), if the user has $permName on it
    private function entryOrError($permName) {
	$oid = $this->getOidOrError();
	if (empty($this->project))
	    \V::app()->error(404, 'Not found');
	$entry = \V::app()->om->findOne('FileEntry', [ '_id' => $oid, 'metadata.aid' => $this->account->_id, 'metadata.project' => $this->project->_id ]);
	if (empty($entry))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $entry ]))
	    \V::app()->error(403, 'Unauthorized');
	return $entry;
    }

    public function upload($req, $resp) {
	if (!$req->is('post'))
	    return;

	if (!\V::app()->user->can('File::create'))
	    \V::app()->error(403, 'Unauthorized');

	//errors as JSON { error } with a 4xx status
	$file = $req->files['file'] ?? null;
	if (empty($file))
	    $resp->sendJSON([ 'error' => 'No file uploaded' ], true, 400);

	if ($file['error'] > 0) {
	    $errors = [
		    0 => 'There is no error, the file uploaded with success',
		    1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
		    2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
		    3 => 'The uploaded file was only partially uploaded',
		    4 => 'No file was uploaded',
		    6 => 'Missing a temporary folder',
		    7 => 'Failed to write file to disk.',
		    8 => 'A PHP extension stopped the file upload.',
	    ];

	    $resp->sendJSON([ 'error' => 'File upload error: ' . ($errors[$file['error']] ?? 'unknown error') ], true, 400);
	}
	if (!is_uploaded_file($file['tmp_name']))
	    $resp->sendJSON([ 'error' => 'Not an uploaded file' ], true, 400);

	//only allowed file types (images, documents, spreadsheets, text, PDF; see File::$extensions)
	if (!\models\File::isAllowedName($file['name']))
	    $resp->sendJSON([ 'error' => 'File type not allowed' ], true, 415);

	//CLD: 2026-11-27T10:00:00+02:00: into the folder shown (`folder`: its _id; none: the top level)
	$folder = $this->folderOrError((string)($req->data['folder'] ?? ''));

	$fp = fopen($file['tmp_name'], 'rb');
	$options = [
	    'metadata' => [
		'project' => $this->project->_id,
		'owner' => \V::app()->user->_id,
		'aid' => $this->account->_id,
		'folder' => $folder?->_id,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ],
	];
	//the tier's limit of the account's files (util\Tiers; File::createFromStream checks it)
	try {
	    $file_id = \models\File::createFromStream($file['name'], $fp, $options);
	} catch (\util\LimitException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 413);
	}
	\models\node\NodeTriggerFile::uploaded($file_id);
	$resp->sendJSON([ 'id' => (string)$file_id ]);
    }

    //the file `id` of the current account and project (404 otherwise), if the user has $permName
    //(checked like list(): files have no checkAccess of their own yet)
    private function findFileOrError($permName) {
	$oid = $this->getOidOrError();
	if (empty($this->project))
	    \V::app()->error(404, 'Not found');

	$file = \V::app()->om->findOne('File', [
	    '_id' => $oid,
	    'metadata.aid' => $this->account->_id,
	    'metadata.project' => $this->project->_id,
	]);
	if (empty($file))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($permName))
	    \V::app()->error(403, 'Unauthorized');

	return $file;
    }

    //file page: details, image preview for image files (no other previews for now), download button
    public function view($req, $resp) {
	$file = $this->findFileOrError('File::read');
	$this->render('view', compact('file'));
    }

    //the file's bytes, streamed from GridFS: previewable images inline with their own type, everything else (and
    //?download=1) as an attachment of type application/octet-stream, so uploaded HTML/SVG/... never run in the app's origin
    public function content($req, $resp) {
	$file = $this->findFileOrError('File::read');

	$mime = $file->imageMimeType();
	$inline = ($mime !== null && empty($req->query['download']));

	$name = (string)$file->filename;
	$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

	$stream = \models\File::_bucket()->openDownloadStream($file->_id);

	//stream directly: no buffering of the whole file

	header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
	header('Content-Length: ' . (int)$file->length);
	header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($name));
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: private, max-age=3600');

	fpassthru($stream);
	exit(0);
    }

    //CLD: 2026-11-27T10:00:00+02:00: a file, or a folder when it is empty (Folder::delete says why not)
    public function delete($req, $resp) {
	if (!$req->is('post'))
	    return;

	$oid = $this->getOidOrError();

	//restrict to the current account (files keep it in metadata.aid); a global-scope role passes
	//File::delete without looking at the object, so without this a known file id from another account could be deleted
	$entry = \V::app()->om->findOne('FileEntry', [ '_id' => $oid, 'metadata.aid' => $this->account->_id ]);
	if (empty($entry))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('File::delete', [ 'object' => $entry ]))
	    \V::app()->error(403, 'Unauthorized');

	$what = $entry instanceof \models\Folder ? 'Folder' : 'File';
	try {
	    $entry->delete();
	} catch (\RuntimeException $e) {
	    $this->addToast('error', 'Not deleted', $e->getMessage());
	    $this->sendEvents();
	}
	$this->addToast('success', 'OK', $what . ' deleted!');
	$this->addEvent('object::deleted', [ 'object' => $what, 'oid' => (string)$oid ]);
	$this->sendEvents();
    }
}
