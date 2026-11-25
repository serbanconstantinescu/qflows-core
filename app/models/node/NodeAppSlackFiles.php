<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack Files - files as the credential's app (NodeAppSlack). Operations:
	upload     a project file (e.g. {{ file.id }}) or a text, shared in a channel or with a person (optionally in a thread, with a comment): files.getUploadURLExternal,
	           the bytes, files.completeUploadExternal
	download   a file of the workspace (its id, or a message's file) saved as a project file: { id, filename, size, contentType }
	list       the files of a channel or of a person, the newest first
	info       a file: { id, name, title, size, type, user, created, link, channels }
	delete     a file the app shared
*/

namespace models\node;

class NodeAppSlackFiles extends NodeAppSlack {
    public static $name = 'slack-files';
    public static $label = 'Slack Files';
    public static $icon = 'bi-file-earmark-arrow-up';
    public static $defaultOperation = 'upload';
    public static $defaultVariable = 'file';

    const OPERATIONS = [
	'upload' => 'Upload a file',
	'download' => 'Download a file',
	'list' => 'List files',
	'info' => 'Get a file',
	'delete' => 'Delete a file',
    ];
    const WRITES = [ 'upload', 'download', 'delete' ];
    const LISTS = [ 'list' ];
    const MAX_SIZE_MB = 200;

    protected static function operationFields() {
	return [
	    self::field('to', 'Share in', [ 'upload' ], [ 'required' => true, 'help' => 'A channel (C…, #name) or a person (U…, an email)', 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '#general' ] ]),
	    self::field('thread', 'In thread', [ 'upload' ], [ 'help' => 'Optional: the ts or link of the thread\'s first message', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('source', 'Upload', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'file', 'choices' => [ 'file' => 'A project file', 'text' => 'A text' ], 'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('name', 'Name', [ 'upload' ], [ 'help' => 'Empty: the project file\'s name (a text needs one)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sourceFile', 'Project file', [ 'upload' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ pdf.id }}',
		'ui' => [ 'expression' => 'text', 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ] ]),
	    self::field('content', 'Text', [ 'upload' ], [ 'type' => 'textarea', 'help' => '{{ ... }} for expressions (a list / object is written as JSON)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ] ]),
	    self::field('comment', 'Comment', [ 'upload' ], [ 'type' => 'textarea', 'help' => 'Optional: a message with the file (Markdown)', 'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('file', 'File', [ 'download', 'info', 'delete' ], [ 'required' => true, 'help' => 'Its id (F…), or a file of a message, e.g. {{ slack.files[0] }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filename', 'Save as', [ 'download' ], [ 'help' => 'Empty: the file\'s own name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('channel', 'Channel', [ 'list' ], [ 'help' => 'Optional: a channel\'s files (C…, #name)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'Person', [ 'list' ], [ 'help' => 'Optional: a person\'s files (U…, an email)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'upload', 'download' ], [ 'type' => 'number', 'required' => true, 'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	];
    }

    private function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'upload' => $this->opUpload($text, $value),
	    'download' => $this->opDownload($text, $value),
	    'list' => $this->opList($text, $value),
	    'info' => $this->opInfo($value),
	    'delete' => $this->opDelete($value),
	};
    }

    //a file's id of a setting: F…, a file ({ id }), or a message's file
    private static function fileId($value) {
	if (is_array($value))
	    $value = $value['id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (!preg_match('/^F[A-Z0-9]{6,}$/', $v))
	    throw new \RuntimeException('File: a file\'s id (F…): `' . mb_substr($v, 0, 60) . '`');
	return $v;
    }

    private function opUpload(\Closure $text, \Closure $value) {
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
	    if ($size < 1)
		throw new \RuntimeException('the file is empty');
	    if (preg_match('/[\x00-\x1f\/\\\\]/', $name) || trim($name, ' .') === '')
		throw new \RuntimeException('not a file name: `' . mb_substr($name, 0, 100) . '`');
	    $channel = $this->destination($value('to'), 'Share in');
	    $up = $this->slack->call('files.getUploadURLExternal', [ 'filename' => $name, 'length' => $size ]);
	    $this->slack->uploadBytes((string)($up['upload_url'] ?? ''), $stream, $size);
	} finally {
	    fclose($stream);
	}
	$args = [ 'files' => [ [ 'id' => (string)($up['file_id'] ?? ''), 'title' => $name ] ], 'channel_id' => $channel ];
	$thread = $text('thread');
	if ($thread !== '')
	    $args['thread_ts'] = \util\Slack::ts($thread, 'In thread');
	$comment = trim(\util\Expression::toText($value('comment')));
	if ($comment !== '')
	    $args['initial_comment'] = \util\Slack::mrkdwn($comment);
	$d = $this->slack->call('files.completeUploadExternal', $args);
	$f = self::fileOf((array)($d['files'][0] ?? []) + [ 'id' => $up['file_id'] ?? '', 'name' => $name, 'size' => $size ]);
	return [ $f, "`{$name}` shared in {$channel} ({$size} bytes)" ];
    }

    private function opDownload(\Closure $text, \Closure $value) {
	$info = (array)($this->slack->call('files.info', [ 'file' => self::fileId($value('file')) ])['file'] ?? []);
	$url = (string)($info['url_private_download'] ?? $info['url_private'] ?? '');
	if ($url === '')
	    throw new \RuntimeException('the file has no download address (an external file, or a post)');
	$maxBytes = $this->maxBytes();
	if ((int)($info['size'] ?? 0) > $maxBytes)
	    throw new \RuntimeException('`' . ($info['name'] ?? '') . '` is larger than ' . ($maxBytes >> 20) . ' MB');
	$filename = trim($text('filename'));
	$filename = NodeAppDownload::filename($filename !== '' ? $filename : (string)($info['name'] ?? 'file'), '', '', (string)($info['mimetype'] ?? ''));
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	try {
	    [ $size, $mime ] = $this->slack->download($url, $stream, $maxBytes);
	    rewind($stream);
	    $image = NodeAppDownload::imageInfo((string)fread($stream, NodeAppDownload::IMAGE_SNIFF));
	    rewind($stream);
	    $fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 'slack',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	} finally {
	    fclose($stream);
	}
	return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'contentType' => (string)($info['mimetype'] ?? $mime) ] + $image + [ 'slack' => self::fileOf($info) ],
	    "`{$filename}` saved ({$size} bytes)" ];
    }

    private function opList(\Closure $text, \Closure $value) {
	$args = [];
	if ($text('channel') !== '')
	    $args['channel'] = $this->channel($value('channel'));
	if ($text('user') !== '')
	    $args['user'] = $this->userId($value('user'), 'Person');
	//files.list pages by page / count, not a cursor
	$max = $this->maxItems();
	$items = [];
	$truncated = false;
	for ($page = 1; ; $page++) {
	    $d = $this->slack->call('files.list', $args + [ 'count' => min(200, $max + 1), 'page' => $page ]);
	    foreach((array)($d['files'] ?? []) as $f) {
		if (count($items) >= $max) {
		    $truncated = true;
		    break 2;
		}
		$items[] = self::fileOf($f);
	    }
	    if ($page >= (int)($d['paging']['pages'] ?? 1))
		break;
	}
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'file') . ($truncated ? ' (more left out)' : '') ];
    }

    private function opInfo(\Closure $value) {
	$f = self::fileOf($this->slack->call('files.info', [ 'file' => self::fileId($value('file')) ])['file'] ?? []);
	return [ $f, "`{$f['name']}` ({$f['size']} bytes)" ];
    }

    private function opDelete(\Closure $value) {
	$id = self::fileId($value('file'));
	$this->slack->call('files.delete', [ 'file' => $id ]);
	return [ [ 'id' => $id, 'deleted' => true ], "{$id} deleted" ];
    }

    //a file of Slack's answers
    public static function fileOf($f) {
	$f = is_array($f) ? $f : [];
	return [
	    'id' => (string)($f['id'] ?? ''),
	    'name' => (string)($f['name'] ?? ''),
	    'title' => (string)($f['title'] ?? ''),
	    'size' => (int)($f['size'] ?? 0),
	    'type' => (string)($f['mimetype'] ?? ''),
	    'user' => (string)($f['user'] ?? ''),
	    'created' => isset($f['created']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$f['created']) : '',
	    'link' => (string)($f['permalink'] ?? ''),
	    'channels' => array_values(array_map('strval', array_merge((array)($f['channels'] ?? []), (array)($f['groups'] ?? []), (array)($f['ims'] ?? [])))),
	];
    }
}
