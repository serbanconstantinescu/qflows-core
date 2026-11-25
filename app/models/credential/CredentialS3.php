<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: an S3 storage account, for the S3 steps (Objects, Links, Buckets, API) and the S3 object trigger (util\S3): Amazon S3 (a region) or an
    S3-compatible service (its endpoint: MinIO, Wasabi, Backblaze B2, Cloudflare R2, Hetzner, OVH, …; path-style addresses by default), an access key (the ID, the secret
    encrypted; a temporary key's session token, encrypted, optional) and a default bucket (optional: the steps' Bucket when empty). Test: the default bucket (one object
    listed), else the buckets. Provides `s3`
*/

namespace models\credential;

class CredentialS3 extends \models\Credential {
    public static $label = 'S3 storage';
    public static $icon = 'bi-bucket';
    public static $group = 'Storage';
    public static $provides = [ 's3' ];
    public static $testable = true;

    const ADDRESSING = [ 'path' => 'Path style (endpoint/bucket/key)', 'virtual' => 'Virtual-hosted (bucket.endpoint/key)' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'endpoint',
		'type' => 'text',
		'label' => 'Endpoint',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: Amazon S3. Another service\'s address, e.g. https://s3.eu-central-1.wasabisys.com, https://<account>.r2.cloudflarestorage.com, https://minio.example.com:9000',
		'validate' => [ [ 'regex', 'pattern' => '^https://[A-Za-z0-9.-]+(:\d{1,5})?/?$', 'skipEmpty' => true, 'message' => 'https://host[:port], without a path' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'Amazon S3' ],
	    ],
	    [
		'key' => 'region',
		'type' => 'text',
		'label' => 'Region',
		'default' => 'us-east-1',
		'transform' => 'trim',
		'help' => 'Of the buckets, e.g. eu-central-1 (another service: its region, or us-east-1; R2: auto)',
		'validate' => [ [ 'regex', 'pattern' => '^[a-z0-9-]{2,32}$', 'skipEmpty' => true, 'message' => 'A region code, e.g. eu-central-1' ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'accessKeyId',
		'type' => 'text',
		'label' => 'Access key ID',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Of an IAM user (or the service\'s key) with the permissions the steps use, e.g. s3:GetObject, s3:PutObject, s3:ListBucket',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9+/=_.-]{3,128}$', 'skipEmpty' => true, 'message' => 'The access key ID' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'secretAccessKey',
		'type' => 'password',
		'label' => 'Secret access key',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'bucket',
		'type' => 'text',
		'label' => 'Default bucket',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: the steps\' bucket when they name none',
		'validate' => [ [ 'regex', 'pattern' => '^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$', 'skipEmpty' => true, 'message' => 'A bucket name' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'sessionToken',
		'type' => 'password',
		'label' => 'Session token',
		'encrypt' => true,
		'help' => 'Only for a temporary key (ASIA…)',
		'ui' => [ 'width' => 6, 'advanced' => true ],
	    ],
	    [
		'key' => 'addressing',
		'type' => 'select',
		'label' => 'Addresses',
		'required' => true,
		'default' => 'path',
		'choices' => self::ADDRESSING,
		'help' => 'With an endpoint: how buckets are addressed (Amazon S3: virtual-hosted, chosen by itself)',
		'ui' => [ 'width' => 6, 'advanced' => true ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the certificate',
		'default' => false,
		'help' => 'With an endpoint: a self-signed certificate is accepted (on-premises MinIO)',
		'ui' => [ 'width' => 6, 'advanced' => true ],
	    ],
	]);
    }

    public function client($aid = null, $timeout = 60) {
	return new \util\S3($this, $aid ?? $this->aid, $timeout);
    }

    public function test() {
	$s = $this->client(null, 20);
	$where = trim((string)$this->endpoint) !== '' ? \util\Http::shown($this->endpoint) : 'Amazon S3 (' . $s->region() . ')';
	try {
	    if (trim((string)$this->bucket) !== '') {
		[ $objects, , $more ] = $s->listObjects(trim((string)$this->bucket), '', '', 1);
		return "connected to {$where}: bucket " . trim((string)$this->bucket) . ' ' . ($objects ? 'has objects' : 'is empty');
	    }
	    $d = $s->xml('GET', '');
	    $names = array_map(fn($b) => (string)($b['Name'] ?? ''), \util\S3::listOf($d['Buckets']['Bucket'] ?? null));
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return "connected to {$where}: " . \util\Format::plural(count($names), 'bucket') . ($names ? ' (' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', …' : '') . ')' : '');
    }
}
