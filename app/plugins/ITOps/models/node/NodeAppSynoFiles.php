<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology Files - the NAS's files, through File Station (documented by Synology). Operations (setting `operation`):
	shares        the shared folders the account sees (SYNO.FileStation.List list_share): with their free and total space
	list          a folder's items (List list, by pages): files, folders or both; a name pattern (*.pdf); sorted
	get           items' details (List getinfo)
	search        the files under a folder and its subfolders by name pattern and extension (Search start, its results read until finished, then cleaned)
	download      a file saved in the project's Files (Download; as Nextcloud Files: allowed types only, largest size): { id, filename, size, contentType, ..., item }
	upload        a project file (e.g. {{ file.id }}) or a text, into a folder (Upload): a name that exists: renamed (name (2).ext), replaced or refused
	createFolder  a folder in a folder, its parents too (CreateFolder, force_parent)
	rename        an item renamed (Rename)
	copy / move   items copied or moved into a folder (CopyMove, a background task waited for); overwriting or not
	delete        items deleted (Delete, a background task waited for; to the recycle bin when the shared folder has one)
	compress      items compressed into a .zip or .7z file (Compress, waited for)
	extract       an archive extracted into a folder (Extract, waited for)
	shareLink     a sharing link of an item (Sharing create): a password, an expiry date
    Items: { name, path, isFolder, size, modified, created (ISO 8601), owner, group, type (the extension), realPath }; paths begin with the shared folder (/docs/a.pdf). A task
    not finished when the step's timeout nears: the result says finished false (the NAS goes on)
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoFiles extends NodeAppSynology {
    public static $name = 'syno-files';
    public static $label = 'Synology Files';
    public static $icon = 'bi-folder2-open';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'files';

    const OPERATIONS = [
	'shares' => 'List shared folders',
	'list' => 'List a folder',
	'get' => 'Get items',
	'search' => 'Search files',
	'download' => 'Download a file (into the project Files)',
	'upload' => 'Upload a file',
	'createFolder' => 'Create a folder',
	'rename' => 'Rename an item',
	'copy' => 'Copy items',
	'move' => 'Move items',
	'delete' => 'Delete items',
	'compress' => 'Compress items',
	'extract' => 'Extract an archive',
	'shareLink' => 'Create a sharing link',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'download', 'upload', 'createFolder', 'rename', 'copy', 'move', 'delete', 'compress', 'extract', 'shareLink' ];
    const LISTS = [ 'shares', 'list', 'get', 'search' ];
    const TYPES = [ 'all' => 'Files and folders', 'file' => 'Files', 'dir' => 'Folders' ];
    const SORT = [ 'name' => 'Name', 'mtime' => 'Modified', 'size' => 'Size', 'type' => 'Type' ];
    const CONFLICT = [ 'rename' => 'Keep both (the new one renamed)', 'replace' => 'Replace the existing file', 'fail' => 'Fail' ];
    const FORMATS = [ 'zip' => 'ZIP', '7z' => '7z' ];
    const ADDITIONAL = [ 'real_path', 'size', 'owner', 'time', 'type' ];
    const MAX_SIZE_MB = 500;

    protected static function operationFields() {
	return [
	    self::field('folder', 'Folder', [ 'list', 'search', 'upload', 'createFolder' ], [ 'required' => true, 'help' => 'Beginning with the shared folder, e.g. /docs/reports',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('path', 'Path', [ 'rename', 'download', 'shareLink', 'extract' ], [ 'required' => true, 'help' => 'The item\'s, e.g. /docs/reports/2026.pdf',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('paths', 'Items', [ 'get', 'copy', 'move', 'delete', 'compress' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'Their paths, one per line, or a list (e.g. {{ files.items }})', 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('name', 'Name', [ 'createFolder', 'rename', 'upload' ], [ 'help' => 'The new folder\'s or item\'s name (Upload: empty for the project file\'s)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'createFolder', 'rename' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('target', 'Into folder', [ 'copy', 'move', 'extract' ], [ 'required' => true, 'help' => 'e.g. /archive/2026', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('destFile', 'Archive', [ 'compress' ], [ 'required' => true, 'help' => 'The archive\'s path, e.g. /archive/reports.zip', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('format', 'Format', [ 'compress' ], [ 'type' => 'select', 'default' => 'zip', 'choices' => self::FORMATS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('overwrite', 'Overwrite', [ 'copy', 'move', 'extract' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Items of the same name in the folder (off: skipped)',
		'ui' => [ 'width' => 3 ] ]),
	    self::field('pattern', 'Name pattern', [ 'list', 'search' ], [ 'help' => 'e.g. *.pdf or report*; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('extension', 'Extensions', [ 'search' ], [ 'help' => 'e.g. pdf, docx; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filetype', 'Show', [ 'list', 'search' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::TYPES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sort', 'Sort by', [ 'list' ], [ 'type' => 'select', 'default' => 'name', 'choices' => self::SORT, 'ui' => [ 'width' => 3 ] ]),
	    self::field('descending', 'Descending', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 3 ] ]),
	    self::field('filename', 'Save as', [ 'download' ], [ 'help' => 'The name in the project Files; empty: the file\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest file (MB)', [ 'download', 'upload' ], [ 'type' => 'number', 'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ], 'ui' => [ 'step' => 1, 'width' => 3 ] ]),
	    self::field('source', 'Upload', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'file', 'choices' => [ 'file' => 'A project file', 'text' => 'A text' ],
		'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('sourceFile', 'Project file', [ 'upload' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ mail.attachment_files[0] }}',
		'ui' => [ 'expression' => 'text', 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ] ]),
	    self::field('content', 'Text', [ 'upload' ], [ 'type' => 'textarea', 'help' => 'The file\'s content ({{ }} allowed; an object is written as JSON)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ] ]),
	    self::field('conflict', 'If the name exists', [ 'upload' ], [ 'type' => 'select', 'default' => 'rename', 'choices' => self::CONFLICT, 'ui' => [ 'width' => 6 ] ]),
	    self::field('linkPassword', 'Link password', [ 'shareLink' ], [ 'help' => 'Empty: none', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('expires', 'Expires on', [ 'shareLink' ], [ 'help' => 'A date, YYYY-MM-DD; empty: never',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return $op === 'shares' ? '{ name, path, isFolder, owner, realPath, free, totalSpace, readOnly }'
	    : '{ name, path, isFolder, size, modified, created, owner, group, type, realPath }';
    }

    //an item of File Station, flattened
    public static function item(array $f) {
	$a = (array)($f['additional'] ?? []);
	$t = (array)($a['time'] ?? []);
	$iso = fn($s) => is_numeric($s) && $s > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int)$s) : null;
	$out = [
	    'name' => (string)($f['name'] ?? basename((string)($f['path'] ?? ''))),
	    'path' => (string)($f['path'] ?? ''),
	    'isFolder' => !empty($f['isdir']),
	    'size' => self::bytes($a['size'] ?? 0),
	    'modified' => $iso($t['mtime'] ?? null),
	    'created' => $iso($t['crtime'] ?? null),
	    'owner' => (string)($a['owner']['user'] ?? ''),
	    'group' => (string)($a['owner']['group'] ?? ''),
	    'type' => (string)($a['type'] ?? ''),
	    'realPath' => (string)($a['real_path'] ?? ''),
	];
	if (isset($a['volume_status']))
	    $out += [ 'free' => self::bytes($a['volume_status']['freespace'] ?? 0), 'totalSpace' => self::bytes($a['volume_status']['totalspace'] ?? 0),
		'readOnly' => !empty($a['volume_status']['readonly']) ];
	return $out;
    }

    protected function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    //an item's details, or null when it does not exist (File Station: 408)
    protected function itemOrNull($path) {
	try {
	    $d = $this->nas->call('SYNO.FileStation.List', 'getinfo', [ 'path' => [ $path ], 'additional' => self::ADDITIONAL ], 2);
	} catch (\RuntimeException $e) {
	    if (str_contains($e->getMessage(), '(code 408)'))
		return null;
	    throw $e;
	}
	$f = (array)($d['files'][0] ?? []);
	//getinfo answers a missing item with its code in the item
	if (!$f || isset($f['code']))
	    return null;
	return self::item($f);
    }

    //a background task started and waited for: [ its last status + { finished }, a word for the summary ]
    protected function waitTask($api, $version, array $params) {
	$d = $this->call($api, 'start', $params, $version);
	$taskid = (string)($d['taskid'] ?? '');
	if ($taskid === '')
	    throw new \RuntimeException("{$api}: DSM gave no task");
	$s = $this->nas->task($api, $taskid, $version, microtime(true) + $this->nas->left() - 5);
	if (!empty($s['errors']) || (isset($s['error']) && is_array($s['error'])))
	    throw new \RuntimeException(\plugins\ITOps\util\Synology::errorText((array)($s['error'] ?? [ 'code' => 401, 'errors' => $s['errors'] ]), $api));
	return [ [ 'taskid' => $taskid ] + $s, $s['finished'] ? 'done' : 'not finished when the wait ended (the NAS goes on)' ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'shares':
		[ $rows, $total ] = $this->pages('SYNO.FileStation.List', 'list_share', [ 'additional' => [ 'real_path', 'owner', 'time', 'volume_status' ] ], 'shares', 2);
		return $this->listResult(array_map([ self::class, 'item' ], $rows), 'shared folder', $total);
	    case 'list':
		$folder = self::path($value('folder'), 'Folder');
		$type = isset(self::TYPES[(string)$this->cfg('filetype', 'all')]) ? (string)$this->cfg('filetype', 'all') : 'all';
		$sort = isset(self::SORT[(string)$this->cfg('sort', 'name')]) ? (string)$this->cfg('sort', 'name') : 'name';
		$pattern = $text('pattern');
		[ $rows, $total ] = $this->pages('SYNO.FileStation.List', 'list', [ 'folder_path' => $folder, 'filetype' => $type, 'sort_by' => $sort,
		    'sort_direction' => $this->cfg('descending', false) ? 'desc' : 'asc', 'pattern' => $pattern !== '' ? $pattern : null, 'additional' => self::ADDITIONAL ], 'files', 2);
		[ $r, $summary ] = $this->listResult(array_map([ self::class, 'item' ], $rows), 'item', $total);
		return [ $r, "{$folder}: {$summary}" ];
	    case 'get':
		$paths = self::paths($value('paths'), 'Items');
		$d = $this->call('SYNO.FileStation.List', 'getinfo', [ 'path' => $paths, 'additional' => self::ADDITIONAL ], 2);
		$rows = [];
		foreach((array)($d['files'] ?? []) as $f)
		    if (is_array($f) && !isset($f['code']))
			$rows[] = self::item($f);
		if (!$rows)
		    throw new \RuntimeException('not found: ' . implode(', ', array_slice($paths, 0, 5)));
		return $this->listResult($rows, 'item');
	    case 'search':
		return $this->search($text, $value);
	    case 'download':
		return $this->download($text, $value);
	    case 'upload':
		return $this->upload($text, $value);
	    case 'createFolder':
		$folder = self::path($value('folder'), 'Folder');
		$name = self::fileName($text('name'));
		$d = $this->call('SYNO.FileStation.CreateFolder', 'create', [ 'folder_path' => [ $folder ], 'name' => [ $name ], 'force_parent' => true,
		    'additional' => self::ADDITIONAL ], 2);
		$f = (array)($d['folders'][0] ?? []);
		return [ $f ? self::item($f) : [ 'path' => "{$folder}/{$name}", 'name' => $name, 'isFolder' => true ], "{$folder}/{$name} created" ];
	    case 'rename':
		$path = self::path($value('path'));
		$name = self::fileName($text('name'));
		$d = $this->call('SYNO.FileStation.Rename', 'rename', [ 'path' => [ $path ], 'name' => [ $name ], 'additional' => self::ADDITIONAL ], 2);
		$f = (array)($d['files'][0] ?? []);
		return [ $f ? self::item($f) : [ 'path' => dirname($path) . "/{$name}", 'name' => $name ], "{$path} renamed to {$name}" ];
	    case 'copy':
	    case 'move':
		$paths = self::paths($value('paths'), 'Items');
		$target = self::path($value('target'), 'Into folder');
		[ $r, $how ] = $this->waitTask('SYNO.FileStation.CopyMove', 3, [ 'path' => $paths, 'dest_folder_path' => $target,
		    'overwrite' => (bool)$this->cfg('overwrite', false), 'remove_src' => $operation === 'move' ]);
		return [ $r, \util\Format::plural(count($paths), 'item') . ($operation === 'move' ? ' moved' : ' copied') . " to {$target}: {$how}" ];
	    case 'delete':
		$paths = self::paths($value('paths'), 'Items');
		[ $r, $how ] = $this->waitTask('SYNO.FileStation.Delete', 2, [ 'path' => $paths, 'recursive' => true, 'accurate_progress' => false ]);
		return [ $r + [ 'paths' => $paths ], \util\Format::plural(count($paths), 'item') . " deleted: {$how}" ];
	    case 'compress':
		$paths = self::paths($value('paths'), 'Items');
		$dest = self::path($value('destFile'), 'Archive');
		$format = isset(self::FORMATS[(string)$this->cfg('format', 'zip')]) ? (string)$this->cfg('format', 'zip') : 'zip';
		[ $r, $how ] = $this->waitTask('SYNO.FileStation.Compress', 3, [ 'path' => $paths, 'dest_file_path' => $dest, 'format' => $format, 'mode' => 'add', 'level' => 'moderate' ]);
		return [ $r + [ 'path' => $dest ], \util\Format::plural(count($paths), 'item') . " compressed into {$dest}: {$how}" ];
	    case 'extract':
		$path = self::path($value('path'));
		$target = self::path($value('target'), 'Into folder');
		[ $r, $how ] = $this->waitTask('SYNO.FileStation.Extract', 2, [ 'file_path' => $path, 'dest_folder_path' => $target,
		    'overwrite' => (bool)$this->cfg('overwrite', false), 'keep_dir' => true, 'create_subfolder' => false ]);
		return [ $r, "{$path} extracted into {$target}: {$how}" ];
	    case 'shareLink':
		$path = self::path($value('path'));
		$expires = $text('expires');
		if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires))
		    throw new \RuntimeException('Expires on: a date as YYYY-MM-DD, not `' . mb_substr($expires, 0, 40) . '`');
		$password = $text('linkPassword');
		$d = $this->call('SYNO.FileStation.Sharing', 'create', [ 'path' => $path, 'password' => $password !== '' ? $password : null,
		    'date_expired' => $expires !== '' ? $expires : null ], 3);
		$link = (array)($d['links'][0] ?? []);
		if (isset($link['error']) || empty($link['url']))
		    throw new \RuntimeException('no link made' . (isset($link['error']['code']) ? ' (code ' . (int)$link['error']['code'] . ')' : ''));
		return [ $link, "{$path}: {$link['url']}" . ($expires !== '' ? " (until {$expires})" : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a name of a file or folder: no /, no control characters
    public static function fileName($name, $label = 'Name') {
	$name = trim((string)$name);
	if ($name === '')
	    throw new \RuntimeException("{$label}: empty");
	if (str_contains($name, '/') || preg_match('/[\x00-\x1f]/', $name) || trim($name, ' .') === '')
	    throw new \RuntimeException("{$label}: not a file name: `" . mb_substr($name, 0, 100) . '`');
	return $name;
    }

    protected function search(\Closure $text, \Closure $value) {
	$folder = self::path($value('folder'), 'Folder');
	$pattern = $text('pattern');
	$ext = implode(',', array_map(fn($x) => ltrim($x, '.'), self::names($text('extension'))));
	$type = isset(self::TYPES[(string)$this->cfg('filetype', 'all')]) ? (string)$this->cfg('filetype', 'all') : 'all';
	$d = $this->call('SYNO.FileStation.Search', 'start', [ 'folder_path' => [ $folder ], 'recursive' => true, 'pattern' => $pattern !== '' ? $pattern : null,
	    'extension' => $ext !== '' ? $ext : null, 'filetype' => $type ], 2);
	$taskid = (string)($d['taskid'] ?? '');
	if ($taskid === '')
	    throw new \RuntimeException('SYNO.FileStation.Search: DSM gave no task');
	$max = $this->maxItems();
	$deadline = microtime(true) + $this->nas->left() - 5;
	try {
	    while(true) {
		$r = $this->nas->call('SYNO.FileStation.Search', 'list', [ 'taskid' => $taskid, 'offset' => 0, 'limit' => $max + 1, 'additional' => self::ADDITIONAL ], 2);
		$finished = !empty($r['finished']);
		if ($finished || microtime(true) + \plugins\ITOps\util\Synology::$poll > $deadline)
		    break;
		sleep(\plugins\ITOps\util\Synology::$poll);
	    }
	} finally {
	    try {
		$this->nas->call('SYNO.FileStation.Search', 'stop', [ 'taskid' => $taskid ], 2);
		$this->nas->call('SYNO.FileStation.Search', 'clean', [ 'taskid' => $taskid ], 2);
	    } catch (\RuntimeException $e) {
	    }
	}
	$rows = array_map([ self::class, 'item' ], array_values(array_filter((array)($r['files'] ?? []), 'is_array')));
	[ $result, $summary ] = $this->listResult($rows, 'file', (int)($r['total'] ?? count($rows)));
	$result['finished'] = $finished;
	return [ $result, "under {$folder}: {$summary}" . ($finished ? '' : ' (the search had not finished)') ];
    }

    protected function download(\Closure $text, \Closure $value) {
	$path = self::path($value('path'));
	$item = $this->itemOrNull($path);
	if (!$item)
	    throw new \RuntimeException("{$path}: no such file");
	if ($item['isFolder'])
	    throw new \RuntimeException("{$path} is a folder, not a file");
	$filename = trim($text('filename'));
	$filename = \models\node\NodeAppDownload::filename($filename !== '' ? $filename : $item['name'], '', '', '');
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
	$maxBytes = $this->maxBytes();
	if ($item['size'] > $maxBytes)
	    throw new \RuntimeException("{$path} is larger than " . ($maxBytes >> 20) . ' MB');
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	try {
	    [ $size, $mime ] = $this->nas->download($path, $stream, $maxBytes);
	    rewind($stream);
	    $image = \models\node\NodeAppDownload::imageInfo((string)fread($stream, \models\node\NodeAppDownload::IMAGE_SNIFF));
	    rewind($stream);
	    $fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 'synology',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	} finally {
	    fclose($stream);
	}
	return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'contentType' => $mime ] + $image + [ 'item' => $item ],
	    "{$path} saved as `{$filename}` ({$size} bytes)" ];
    }

    protected function upload(\Closure $text, \Closure $value) {
	$maxBytes = $this->maxBytes();
	$name = trim($text('name'));
	if ($this->cfg('source', 'file') === 'text') {
	    $content = $value('content');
	    $content = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) : \util\Expression::toText($content);
	    if ($name === '')
		throw new \RuntimeException('Name: a text needs one');
	    if (strlen($content) > $maxBytes)
		throw new \RuntimeException('the text is larger than ' . ($maxBytes >> 20) . ' MB');
	    $size = strlen($content);
	    $stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	    fwrite($stream, $content);
	    rewind($stream);
	} else {
	    $fileId = trim($text('sourceFile'));
	    $file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	    $size = (int)$file->length;
	    if ($size > $maxBytes)
		throw new \RuntimeException("`{$file->filename}` is larger than " . ($maxBytes >> 20) . ' MB');
	    if ($name === '')
		$name = (string)$file->filename;
	    $stream = \models\File::_bucket()->openDownloadStream($file->_id);
	}
	try {
	    $name = self::fileName($name);
	    $folder = self::path($value('folder'), 'Folder');
	    $conflict = isset(self::CONFLICT[(string)$this->cfg('conflict', 'rename')]) ? (string)$this->cfg('conflict', 'rename') : 'rename';
	    if ($conflict === 'rename') {
		$dot = strrpos($name, '.');
		[ $base, $ext ] = $dot > 0 ? [ substr($name, 0, $dot), substr($name, $dot) ] : [ $name, '' ];
		$first = $name;
		for ($i = 2; $this->itemOrNull("{$folder}/{$name}") !== null; $i++) {
		    if ($i > 100)
			throw new \RuntimeException("too many files named {$first}");
		    $name = "{$base} ({$i}){$ext}";
		}
	    }
	    $this->nas->upload($folder, $name, $stream, $size, $conflict === 'replace' ? true : null, true);
	} finally {
	    fclose($stream);
	}
	$dest = "{$folder}/{$name}";
	$item = $this->itemOrNull($dest) ?? [ 'path' => $dest, 'name' => $name, 'isFolder' => false, 'size' => $size ];
	return [ $item, "{$dest} uploaded ({$size} bytes)" ];
    }
}
