<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: S3 Links - presigned links to an object (NodeAppS3): anyone with the link may download (or upload) that one object until it expires,
    without a key of their own (AWS: at most 7 days; a temporary key's link ends with the key). Operations:
	download  a GET link; optionally the name the browser saves it as (response-content-disposition)
	upload    a PUT link: the person (or a system) PUTs the file's bytes to it
    The result: { url, method, bucket, key, expires (ISO 8601) }. Signing happens here: nothing is sent to S3, so the object need not exist (an upload link) and a wrong key
    gives a link that S3 refuses
*/

namespace models\node;

class NodeAppS3Links extends NodeAppS3 {
    public static $name = 's3-links';
    public static $label = 'S3 Links';
    public static $icon = 'bi-link-45deg';
    public static $defaultOperation = 'download';
    public static $defaultVariable = 'link';

    const OPERATIONS = [
	'download' => 'A download link',
	'upload' => 'An upload link',
    ];
    const WRITES = [ 'upload' ];
    const UNITS = [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ];
    const SECONDS = [ 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];
    const MAX_SECONDS = 604800;

    protected static function operationFields() {
	return [
	    self::bucketField([ 'download', 'upload' ]),
	    self::field('key', 'Key', [ 'download', 'upload' ], [ 'required' => true, 'help' => 'e.g. reports/2026-11.pdf, or s3://bucket/key', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('valid', 'Valid for', [ 'download', 'upload' ], [ 'type' => 'number', 'required' => true, 'default' => 24, 'validate' => [ [ 'inRange', 'lower' => 1, 'skipEmpty' => true, 'message' => '1 or more' ] ],
		'ui' => [ 'step' => 1, 'width' => 3, 'newRow' => true ] ]),
	    self::field('unit', 'Unit', [ 'download', 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'hours', 'choices' => self::UNITS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('saveAs', 'Save as', [ 'download' ], [ 'help' => 'Optional: the name the browser saves it as', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => '{ url, method, bucket, key, expires }', 'fields' => [ 'url' => [ 'type' => 'string', 'info' => 'the link' ],
	    'method' => [ 'type' => 'string', 'info' => 'GET or PUT' ], 'bucket' => [ 'type' => 'string' ], 'key' => [ 'type' => 'string' ], 'expires' => [ 'type' => 'string', 'info' => 'ISO 8601' ] ] ] ] : [];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$v = $this->settingOf('key');
	$t = trim(\util\Expression::toText(is_array($v) ? ($v['key'] ?? '') : $v));
	$bucket = is_array($v) && isset($v['bucket']) ? \util\vendor\S3::bucket($v['bucket']) : (preg_match('~^s3://([^/]+)/~i', $t, $m) ? \util\vendor\S3::bucket($m[1]) : $this->bucketOf());
	$key = \util\vendor\S3::key($t);
	$unit = isset(self::SECONDS[(string)$this->cfg('unit', 'hours')]) ? (string)$this->cfg('unit', 'hours') : 'hours';
	$seconds = (int)$value('valid') * self::SECONDS[$unit];
	if ($seconds < 1 || $seconds > self::MAX_SECONDS)
	    throw new \RuntimeException('Valid for: from 1 minute to 7 days');
	$query = [];
	$saveAs = $operation === 'download' ? str_replace([ '"', "\r", "\n", '\\' ], '', $text('saveAs')) : '';
	if ($saveAs !== '')
	    $query['response-content-disposition'] = 'attachment; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $saveAs) . '"; filename*=UTF-8\'\'' . rawurlencode($saveAs);
	$method = $operation === 'upload' ? 'PUT' : 'GET';
	$url = $this->s3->presign($method, $bucket, $key, $seconds, $query);
	$expires = gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
	return [ [ 'url' => $url, 'method' => $method, 'bucket' => $bucket, 'key' => $key, 'expires' => $expires ], "{$operation} link to s3://{$bucket}/{$key}, until {$expires}" ];
    }
}
