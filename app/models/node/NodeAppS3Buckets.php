<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: S3 Buckets - the account's buckets (NodeAppS3). Operations:
	list    the buckets: { name, created }
	info    a bucket's region, and whether the key may reach it (HEAD)
	create  a bucket in the credential's region (Amazon S3: names are global; outside us-east-1 its LocationConstraint)
	delete  an empty bucket
    Versioning, lifecycle, policies, CORS: the S3 API step
*/

namespace models\node;

class NodeAppS3Buckets extends NodeAppS3 {
    public static $name = 's3-buckets';
    public static $label = 'S3 Buckets';
    public static $icon = 'bi-bucket-fill';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'buckets';

    const OPERATIONS = [
	'list' => 'List buckets',
	'info' => 'Get a bucket',
	'create' => 'Create a bucket',
	'delete' => 'Delete a bucket',
    ];
    const WRITES = [ 'create', 'delete' ];
    const LISTS = [ 'list' ];

    protected static function operationFields() {
	return [
	    self::bucketField([ 'info', 'create', 'delete' ], 'bucket', 'Bucket', 'Its name (3 to 63 lower case letters, digits, dots and hyphens); empty: the credential\'s default'),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $d = $this->s3->xml('GET', '');
	    $all = array_map(fn($b) => [ 'name' => (string)($b['Name'] ?? ''), 'created' => isset($b['CreationDate']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($b['CreationDate'])) : '' ],
		\util\S3::listOf($d['Buckets']['Bucket'] ?? null));
	    $items = array_slice($all, 0, $this->maxItems());
	    return [ [ 'items' => $items, 'count' => count($items), 'truncated' => count($all) > count($items) ], \util\Format::plural(count($items), 'bucket') ];
	}
	$bucket = $this->bucketOf();
	switch($operation) {
	    case 'info':
		[ $status, $h ] = $this->s3->request('HEAD', $bucket, '', [], [], [ 'ok' => [ 301, 403, 404 ] ]);
		if ($status === 404)
		    throw new \RuntimeException("NoSuchBucket: no bucket {$bucket}");
		$region = (string)($h['x-amz-bucket-region'] ?? '');
		return [ [ 'name' => $bucket, 'region' => $region, 'reachable' => $status === 200, 'url' => "s3://{$bucket}" ], "{$bucket}" . ($region !== '' ? " ({$region})" : '')
		    . ($status === 200 ? '' : ($status === 301 ? ': in another region' : ': access denied')) ];
	    case 'create':
		$region = $this->s3->region();
		$body = $this->s3->endpoint() === '' && $region !== 'us-east-1'
		    ? '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>' . htmlspecialchars($region, ENT_XML1) . '</LocationConstraint></CreateBucketConfiguration>' : '';
		$this->s3->request('PUT', $bucket, '', [], $body !== '' ? [ 'Content-Type' => 'application/xml' ] : [], [ 'body' => $body ]);
		return [ [ 'name' => $bucket, 'region' => $region, 'url' => "s3://{$bucket}" ], "{$bucket} created" ];
	    case 'delete':
		$this->s3->request('DELETE', $bucket);
		return [ [ 'name' => $bucket, 'deleted' => true ], "{$bucket} deleted" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
