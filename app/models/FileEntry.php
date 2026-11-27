<?php

/*
    CLD: 2026-11-27T10:00:00+02:00: an entry of a project's Files: a file (File, the family's default variant: the GridFS file documents without __variant are files) or a
    folder (Folder). Both are documents of the GridFS bucket `filestore` (filestore.files); a folder has no chunks. The folder an entry is in: metadata.folder (its _id;
    null or missing: the top level). om->find('File') / find('Folder') get only their variant; find('FileEntry') both (the Files page)
*/

namespace models;

class FileEntry extends \sys\BaseModel {
    use \sys\PolymorphTrait;

    public static $indexes = [
	[ [ 'metadata.project' => 1, 'metadata.folder' => 1, '__variant' => 1 ], [] ],
	[ [ 'metadata.aid' => 1 ], [] ],
	//a folder's name is unique in its folder (Folder::checkName says it first)
	[ [ 'metadata.project' => 1, 'metadata.folder' => 1, 'filename' => 1 ], [ 'unique' => true, 'partialFilterExpression' => [ '__variant' => 'Folder' ] ] ],
    ];
    protected static $bucketName = 'filestore';
    protected static $chunkSizeBytes = 128 * 1024;

    protected static $bucket = null;

    //metadata keys also kept at the top level of the document (beforeSave, File::createFromStream, Folder::add)
    const TOP_LEVEL_METADATA = [ 'aid', 'project' ];

    public static function _collection() {
	return static::$bucketName . '.files';
    }

    public static function _bucket() {
	if (static::$bucket)
	    return static::$bucket;

	$db = \V::app()->mongo->selectDatabase(self::_database());
	static::$bucket = $db->selectGridFSBucket([
	    'bucketName' => static::$bucketName,
	    'chunkSizeBytes' => static::$chunkSizeBytes,
	    'disableMD5' => true,
	    'writeConcern' => new \MongoDB\Driver\WriteConcern('majority', 0, true),
	]);

	return static::$bucket;
    }

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;

	$this->owner = $this->metadata['owner'] ?? null;
	$this->created_at = $this->metadata['created_at'] ?? null;
	$this->updated_at = $this->metadata['updated_at'] ?? null;
    }

    public function getSchema() {
	$fields = [
	    //filename (a folder's name)
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	    //length
	    [
		'key' => 'length',
		'type' => 'number',
		'label' => 'Size',
		'help' => false,
		'format' => 'bytes-human',
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
	    ],

	    //type
	    [
		'key' => 'type',
		'type' => 'text',
		'label' => 'Type',
		'help' => false,
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
	    ],

	    //project
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'permission',
	    ],

	    //owner
	    [
		'key' => 'owner',
		'type' => 'object',
		'label' => 'Owner',
		'object' => 'User',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //metadata
	    [
		'key' => 'metadata',
		'type' => 'array',
		'label' => 'Metadata',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		//perm:update (perm:edit is read by nothing)
		'perm:update' => 'nobody',
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //keep a top-level `aid` and `project` (queried by generic code, e.g. Field_object::objectChoices),
    //taken from metadata.aid, metadata.project where GridFS uploads store the account
    public function beforeSave() {
	parent::beforeSave();

	foreach(self::TOP_LEVEL_METADATA as $key) {
	    if (empty($this->$key) && !empty($this->metadata[$key]))
		$this->$key = $this->metadata[$key];
	}
    }

    //the folders from the top level down to the one this entry is in: [ Folder, ... ] (empty: at the top level); a folder that is gone ends it there
    public function folders() {
	$list = [];
	$id = $this->metadata['folder'] ?? null;
	//Folder::move never makes a cycle; the bound is for a damaged tree
	while(!empty($id) && count($list) < Folder::MAX_DEPTH) {
	    $folder = \V::app()->om->findOne('Folder', [ '_id' => $id, 'metadata.aid' => $this->metadata['aid'] ?? null ]);
	    if (!$folder)
		break;
	    array_unshift($list, $folder);
	    $id = $folder->metadata['folder'] ?? null;
	}
	return $list;
    }

    //the path of the folder this entry is in, e.g. `Invoices/2026` ('' at the top level)
    public function folderPath() {
	return implode('/', array_map(fn($f) => (string)$f->filename, $this->folders()));
    }

    //access to an entry (BaseModel's default is always false), with the File permissions (folders too):
    //role scope `global` -> yes; `project` -> only if the user is a member of the entry's project (same account); else no.
    //AAA::checkAccess passes the full name (File::delete), field checks the short one (read, update)
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = 'File::' . $permName;

	//the user's role in the entry's project decides; a file without a project (an execution's, e.g. an AI step's approval) gives nothing here: global scope is sys\AAA's
	$projectId = $this->metadata['project'] ?? $this->project;
	return !empty($projectId) && (\V::app()->om->findById('Project', $projectId)?->checkAccess($userId, $permName) ?? false);
    }

    //label of an entry (e.g. in object fields)
    public function __toString() {
	return (string)($this->filename ?? '');
    }
}
