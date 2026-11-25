<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: S3 Objects - the objects of a bucket (NodeAppS3). Operations:
	list      the objects below a prefix, the oldest key first (S3's order: by key); with Folders: one level, the "folders" (common prefixes) apart
	upload    a project file (e.g. {{ file.id }}) or a text as an object: its content type (from the name when empty), metadata (x-amz-meta-*), storage class; an existing key:
	          replaced, or refused (If-None-Match: *)
	download  an object saved as a project file: { id, filename, size, contentType, object }
	read      an object's content into the variable, as text or JSON (at most Largest size)
	info      an object's size, type, time, ETag, storage class and metadata (HEAD); exists: the same with { exists } (no error when missing)
	copy      an object to another key (and bucket); move: copy, then delete the original
	delete    one object, or many (a list of keys, or of objects of a list: DeleteObjects, 1000 per request)
    An object: { bucket, key, name, folder, size, modified, etag, storageClass, url (s3://bucket/key) }
*/

namespace models\node;

class NodeAppS3Objects extends NodeAppS3 {
    public static $name = 's3-objects';
    public static $label = 'S3 Objects';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 's3';

    const OPERATIONS = [
	'list' => 'List objects',
	'upload' => 'Upload an object',
	'download' => 'Download an object',
	'read' => 'Read an object',
	'info' => 'Get an object\'s details',
	'exists' => 'Check if an object exists',
	'copy' => 'Copy an object',
	'move' => 'Move an object',
	'delete' => 'Delete objects',
    ];
    const WRITES = [ 'upload', 'download', 'copy', 'move', 'delete' ];
    const LISTS = [ 'list' ];
    const MAX_SIZE_MB = 1024;
    const MAX_READ_MB = 20;
    const BY_KEY = [ 'upload', 'download', 'read', 'info', 'exists', 'copy', 'move' ];

    protected static function operationFields() {
	return [
	    self::bucketField([ 'list', 'upload', 'download', 'read', 'info', 'exists', 'copy', 'move', 'delete' ]),
	    self::field('prefix', 'Prefix', [ 'list' ], [ 'help' => 'Optional: keys starting with it, e.g. invoices/2026/', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('folders', 'Folders', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'help' => 'One level: the objects directly below the prefix, the folders apart', 'ui' => [ 'width' => 6 ] ]),
	    self::field('key', 'Key', self::BY_KEY, [ 'required' => true, 'help' => 'e.g. invoices/2026/a.pdf, or s3://bucket/key, or {{ item }} of a list', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('keys', 'Keys', [ 'delete' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'One per line, a list, or the objects of a list, e.g. {{ old.items }}', 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::bucketField([ 'copy', 'move' ], 'toBucket', 'To bucket', 'Empty: the same bucket'),
	    self::field('toKey', 'To key', [ 'copy', 'move' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('source', 'Upload', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'file', 'choices' => [ 'file' => 'A project file', 'text' => 'A text' ], 'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('ifExists', 'When the key exists', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'replace', 'choices' => [ 'replace' => 'Replace it', 'fail' => 'Fail' ], 'ui' => [ 'width' => 3 ] ]),
	    self::field('contentType', 'Content type', [ 'upload' ], [ 'help' => 'Empty: from the name, e.g. application/pdf', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sourceFile', 'Project file', [ 'upload' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ pdf.id }}',
		'ui' => [ 'expression' => 'text', 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ] ]),
	    self::field('content', 'Text', [ 'upload' ], [ 'type' => 'textarea', 'help' => '{{ ... }} for expressions (a list / object is written as JSON)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ] ]),
	    self::field('metadata', 'Metadata', [ 'upload' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object of names and values (x-amz-meta-*)', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('storageClass', 'Storage class', [ 'upload' ], [ 'help' => 'Optional: e.g. STANDARD_IA, INTELLIGENT_TIERING, GLACIER_IR', 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	    self::field('filename', 'Save as', [ 'download' ], [ 'help' => 'Empty: the key\'s name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('as', 'Read as', [ 'read' ], [ 'type' => 'select', 'required' => true, 'default' => 'text', 'choices' => [ 'text' => 'Text', 'json' => 'JSON' ], 'ui' => [ 'width' => 3 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'upload', 'download', 'read' ], [ 'type' => 'number', 'required' => true, 'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$object = [ 'bucket' => [ 'type' => 'string' ], 'key' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'folder' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number', 'info' => 'bytes' ],
	    'modified' => [ 'type' => 'string' ], 'etag' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 's3://bucket/key' ] ];
	return [ $name => match($op) {
	    'list' => [ 'type' => 'object', 'info' => '{ items, folders, count, truncated }', 'fields' => [ 'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $object ] ],
		'folders' => [ 'type' => 'array', 'info' => 'with Folders: the prefixes one level below' ], 'count' => [ 'type' => 'number' ], 'truncated' => [ 'type' => 'boolean' ] ] ],
	    'download' => [ 'type' => 'object', 'info' => 'the project file: { id, filename, size, contentType, object }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'filename' => [ 'type' => 'string' ],
		'size' => [ 'type' => 'number' ], 'contentType' => [ 'type' => 'string' ] ] ],
	    'read' => [ 'type' => 'any', 'info' => 'the content: a text, or the JSON value' ],
	    'delete' => [ 'type' => 'object', 'info' => '{ deleted: [ keys ], errors: [ { key, error } ] }' ],
	    'info', 'exists' => [ 'type' => 'object', 'info' => 'the object, with contentType, storageClass, metadata (exists: and exists)', 'fields' => $object + [ 'exists' => [ 'type' => 'boolean' ],
		'contentType' => [ 'type' => 'string' ], 'metadata' => [ 'type' => 'object' ] ] ],
	    default => [ 'type' => 'object', 'info' => 'the object', 'fields' => $object ],
	} ];
    }

    private function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    //[ bucket, key ] of setting $key: an s3:// address names its bucket; else the Bucket setting $bucketKey ($sameAs: its fallback, the source's bucket)
    private function target($key, $bucketKey, $label = 'Key', $sameAs = null) {
	$v = $this->settingOf($key);
	if (is_array($v) && isset($v['key']))
	    return [ isset($v['bucket']) ? \util\S3::bucket($v['bucket']) : ($sameAs ?? $this->bucketOf($bucketKey)), \util\S3::key($v['key'], $label) ];
	$t = trim(\util\Expression::toText($v));
	if (preg_match('~^s3://([^/]+)/~i', $t, $m))
	    return [ \util\S3::bucket($m[1]), \util\S3::key($t, $label) ];
	if ($sameAs !== null && trim(\util\Expression::toText($this->settingOf($bucketKey))) === '')
	    return [ $sameAs, \util\S3::key($t, $label) ];
	return [ $this->bucketOf($bucketKey), \util\S3::key($t, $label) ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'list' => $this->opList($text),
	    'upload' => $this->opUpload($text, $value),
	    'download' => $this->opDownload($text),
	    'read' => $this->opRead(),
	    'info', 'exists' => $this->opInfo($operation),
	    'copy', 'move' => $this->opCopy($operation),
	    'delete' => $this->opDelete($value),
	};
    }

    private function opList(\Closure $text) {
	$bucket = $this->bucketOf();
	$prefix = ltrim(\util\Expression::toText($this->settingOf('prefix')), '/');
	$folders = (bool)$this->cfg('folders', false);
	if ($folders && $prefix !== '' && !str_ends_with($prefix, '/'))
	    $prefix .= '/';
	[ $objects, $dirs, $truncated ] = $this->s3->listObjects($bucket, $prefix, $folders ? '/' : '', $this->maxItems());
	$items = array_map(fn($o) => $o + [ 'url' => self::s3Url($bucket, $o['key']) ], $objects);
	return [ [ 'items' => $items, 'folders' => $dirs, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'object') . ($dirs ? ', ' . \util\Format::plural(count($dirs), 'folder') : '')
	    . ($truncated ? ' (more left out)' : '') ];
    }

    //the object { … } of a HEAD's headers
    private static function headOf($bucket, $key, array $h) {
	$meta = [];
	foreach($h as $k => $v)
	    if (str_starts_with($k, 'x-amz-meta-'))
		$meta[substr($k, 11)] = $v;
	$slash = strrpos($key, '/');
	return [ 'bucket' => $bucket, 'key' => $key, 'name' => $slash === false ? $key : substr($key, $slash + 1), 'folder' => $slash === false ? '' : substr($key, 0, $slash + 1),
	    'size' => (int)($h['content-length'] ?? 0), 'contentType' => (string)($h['content-type'] ?? ''), 'modified' => isset($h['last-modified']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($h['last-modified'])) : '',
	    'etag' => trim((string)($h['etag'] ?? ''), '"'), 'storageClass' => (string)($h['x-amz-storage-class'] ?? 'STANDARD'), 'versionId' => (string)($h['x-amz-version-id'] ?? ''),
	    'metadata' => $meta, 'url' => self::s3Url($bucket, $key) ];
    }

    private function opInfo($operation) {
	[ $bucket, $key ] = $this->target('key', 'bucket');
	[ $status, $h ] = $this->s3->request('HEAD', $bucket, $key, [], [], [ 'ok' => [ 404, 403 ] ]);
	if ($status === 404 || $status === 403) {
	    if ($operation === 'exists' && $status === 404)
		return [ [ 'exists' => false, 'bucket' => $bucket, 'key' => $key, 'url' => self::s3Url($bucket, $key) ], self::s3Url($bucket, $key) . ': not there' ];
	    throw new \RuntimeException($status === 404 ? 'NoSuchKey: no such object (' . self::s3Url($bucket, $key) . ')' : 'AccessDenied: the key may not read it (s3:GetObject)');
	}
	$o = self::headOf($bucket, $key, $h);
	return [ $operation === 'exists' ? [ 'exists' => true ] + $o : $o, "{$o['url']} ({$o['size']} bytes)" ];
    }

    private function opUpload(\Closure $text, \Closure $value) {
	[ $bucket, $key ] = $this->target('key', 'bucket');
	$maxBytes = $this->maxBytes();
	$headers = [];
	$type = $text('contentType');
	if ($this->cfg('source', 'file') === 'text') {
	    $content = $value('content');
	    $content = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) : \util\Expression::toText($content);
	    if (strlen($content) > $maxBytes)
		throw new \RuntimeException('the text is larger than ' . ($maxBytes >> 20) . ' MB');
	    $size = strlen($content);
	    $o = [ 'body' => $content ];
	    $close = null;
	} else {
	    $fileId = $text('sourceFile');
	    $file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	    $size = (int)$file->length;
	    if ($size > $maxBytes)
		throw new \RuntimeException("`{$file->filename}` is larger than " . ($maxBytes >> 20) . ' MB');
	    $close = \models\File::_bucket()->openDownloadStream($file->_id);
	    $o = [ 'upload' => [ $close, $size ] ];
	}
	if ($type === '') {
	    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
	    $type = \models\File::CONTENT_TYPES[$ext] ?? ($this->cfg('source', 'file') === 'text' ? 'text/plain; charset=utf-8' : 'application/octet-stream');
	}
	$headers['Content-Type'] = $type;
	$headers['Content-Length'] = (string)$size;
	foreach((array)($this->jsonObject('metadata', 'Metadata') ?? []) as $k => $v) {
	    $k = strtolower(trim((string)$k));
	    if (!preg_match('/^[a-z0-9_-]{1,100}$/', $k))
		throw new \RuntimeException("Metadata: not a name: `{$k}` (letters, digits, - and _)");
	    $headers["x-amz-meta-{$k}"] = str_replace([ "\r", "\n" ], ' ', \util\Expression::toText($v));
	}
	$class = strtoupper($text('storageClass'));
	if ($class !== '') {
	    if (!preg_match('/^[A-Z_]{3,30}$/', $class))
		throw new \RuntimeException("Storage class: not a class: `{$class}`");
	    $headers['x-amz-storage-class'] = $class;
	}
	if ($this->cfg('ifExists', 'replace') === 'fail')
	    $headers['If-None-Match'] = '*';
	try {
	    [ , $h ] = $this->s3->request('PUT', $bucket, $key, [], $headers, $o);
	} catch (\RuntimeException $e) {
	    throw new \RuntimeException(str_starts_with($e->getMessage(), 'PreconditionFailed') ? self::s3Url($bucket, $key) . ' exists (When the key exists: Fail)' : $e->getMessage());
	} finally {
	    if ($close)
		fclose($close);
	}
	$slash = strrpos($key, '/');
	$obj = [ 'bucket' => $bucket, 'key' => $key, 'name' => $slash === false ? $key : substr($key, $slash + 1), 'folder' => $slash === false ? '' : substr($key, 0, $slash + 1), 'size' => $size,
	    'contentType' => $type, 'etag' => trim((string)($h['etag'] ?? ''), '"'), 'versionId' => (string)($h['x-amz-version-id'] ?? ''), 'url' => self::s3Url($bucket, $key) ];
	return [ $obj, "{$obj['url']} uploaded ({$size} bytes)" ];
    }

    //a JSON object setting: null when empty
    private function jsonObject($key, $label) {
	$v = $this->settingOf($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return null;
	    $v = json_decode($v, true);
	}
	if ($v === null || $v === [])
	    return null;
	if (!is_array($v) || array_is_list($v))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    private function opDownload(\Closure $text) {
	[ $bucket, $key ] = $this->target('key', 'bucket');
	$maxBytes = $this->maxBytes();
	$name = $text('filename');
	$base = basename($key);
	$filename = NodeAppDownload::filename($name !== '' ? $name : ($base !== '' ? $base : 'object'), '', '', '');
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	try {
	    try {
		[ , $h ] = $this->s3->request('GET', $bucket, $key, [], [], [ 'sink' => $stream, 'maxBytes' => $maxBytes ]);
	    } catch (\RuntimeException $e) {
		throw new \RuntimeException(str_starts_with($e->getMessage(), 'HTTP status 404') ? 'NoSuchKey: no such object (' . self::s3Url($bucket, $key) . ')' : $e->getMessage());
	    }
	    $size = (int)fstat($stream)['size'];
	    rewind($stream);
	    $image = NodeAppDownload::imageInfo((string)fread($stream, NodeAppDownload::IMAGE_SNIFF));
	    rewind($stream);
	    $fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 's3',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	} finally {
	    fclose($stream);
	}
	return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'contentType' => (string)($h['content-type'] ?? '') ] + $image + [ 'object' => self::headOf($bucket, $key, $h) ],
	    self::s3Url($bucket, $key) . " saved as `{$filename}` ({$size} bytes)" ];
    }

    private function opRead() {
	[ $bucket, $key ] = $this->target('key', 'bucket');
	$maxBytes = min(self::MAX_READ_MB, max(1, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
	try {
	    [ , , $body ] = $this->s3->request('GET', $bucket, $key, [], [], [ 'maxBytes' => $maxBytes ]);
	} catch (\RuntimeException $e) {
	    throw new \RuntimeException(str_starts_with($e->getMessage(), 'the answer is larger') ? 'the object is larger than ' . ($maxBytes >> 20) . ' MB (reading at most ' . self::MAX_READ_MB . ' MB)' : $e->getMessage());
	}
	if ($this->cfg('as', 'text') === 'json') {
	    $v = json_decode($body, true);
	    if ($v === null && trim($body) !== 'null')
		throw new \RuntimeException(self::s3Url($bucket, $key) . ' is not JSON');
	    return [ $v, self::s3Url($bucket, $key) . ' read as JSON (' . strlen($body) . ' bytes)' ];
	}
	if (!mb_check_encoding($body, 'UTF-8'))
	    throw new \RuntimeException(self::s3Url($bucket, $key) . ' is not text (UTF-8): download it instead');
	return [ $body, self::s3Url($bucket, $key) . ' read (' . strlen($body) . ' bytes)' ];
    }

    private function opCopy($operation) {
	[ $bucket, $key ] = $this->target('key', 'bucket');
	[ $toBucket, $toKey ] = $this->target('toKey', 'toBucket', 'To key', $bucket);
	if ($bucket === $toBucket && $key === $toKey)
	    throw new \RuntimeException('the object would be copied onto itself');
	$source = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
	[ , $h, $body ] = $this->s3->request('PUT', $toBucket, $toKey, [], [ 'x-amz-copy-source' => $source ]);
	//CopyObject may fail after its 200 (the error is in the body)
	$d = \util\S3::xmlArray($body);
	if (isset($d['Code']))
	    throw new \RuntimeException(\util\S3::errorOf($body, 200));
	if ($operation === 'move')
	    $this->s3->request('DELETE', $bucket, $key);
	$slash = strrpos($toKey, '/');
	$obj = [ 'bucket' => $toBucket, 'key' => $toKey, 'name' => $slash === false ? $toKey : substr($toKey, $slash + 1), 'folder' => $slash === false ? '' : substr($toKey, 0, $slash + 1),
	    'etag' => trim((string)($d['ETag'] ?? ''), '"'), 'modified' => isset($d['LastModified']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($d['LastModified'])) : '', 'url' => self::s3Url($toBucket, $toKey) ];
	return [ $obj, self::s3Url($bucket, $key) . ($operation === 'move' ? ' moved to ' : ' copied to ') . $obj['url'] ];
    }

    private function opDelete(\Closure $value) {
	$bucket = $this->bucketOf();
	$v = $value('keys');
	$list = is_array($v) ? (array_is_list($v) ? $v : [ $v ]) : preg_split('/\r?\n/', \util\Expression::toText($v));
	$keys = [];
	foreach($list as $k) {
	    if (is_string($k) && trim($k) === '')
		continue;
	    $keys[] = \util\S3::key($k, 'Keys');
	}
	$keys = array_values(array_unique($keys));
	if (!$keys)
	    throw new \RuntimeException('Keys: none given');
	if (count($keys) === 1) {
	    $this->s3->request('DELETE', $bucket, $keys[0]);
	    return [ [ 'deleted' => $keys, 'errors' => [] ], self::s3Url($bucket, $keys[0]) . ' deleted' ];
	}
	$deleted = [];
	$errors = [];
	foreach(array_chunk($keys, 1000) as $chunk) {
	    $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Quiet>false</Quiet>'
		. implode('', array_map(fn($k) => '<Object><Key>' . htmlspecialchars($k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>', $chunk)) . '</Delete>';
	    [ , , $body ] = $this->s3->request('POST', $bucket, '', [ 'delete' => '' ], [ 'Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($xml, true)) ], [ 'body' => $xml ]);
	    $d = \util\S3::xmlArray($body);
	    foreach(\util\S3::listOf($d['Deleted'] ?? null) as $x)
		$deleted[] = (string)($x['Key'] ?? '');
	    foreach(\util\S3::listOf($d['Error'] ?? null) as $x)
		$errors[] = [ 'key' => (string)($x['Key'] ?? ''), 'error' => trim(($x['Code'] ?? '') . ': ' . ($x['Message'] ?? ''), ': ') ];
	}
	return [ [ 'deleted' => $deleted, 'errors' => $errors ], \util\Format::plural(count($deleted), 'object') . ' deleted' . ($errors ? ', ' . count($errors) . ' not' : '') ];
    }
}
