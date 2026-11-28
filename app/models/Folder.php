<?php

/*
    CLD: 2026-11-27T10:00:00+02:00: a folder of a project's Files (a variant of FileEntry): a document of filestore.files without chunks (length 0), its name in `filename`,
    the folder it is in in metadata.folder (null: the top level). Folders nest; a name is unique in its folder; only an empty folder is deleted. The File permissions apply
    (FileEntry::checkAccess)
*/

namespace models;

class Folder extends FileEntry {
    //the deepest a folder can be (its folders above it + itself)
    const MAX_DEPTH = 32;
    const MAX_NAME = 120;

    public function _init() {
	parent::_init();
	$this->type = 'Folder';
    }

    //no size
    public function getSchema() {
	return array_values(array_filter(parent::getSchema(), fn($f) => ($f['key'] ?? null) !== 'length'));
    }

    //what is wrong with $name for a folder in $parent (a folder _id, null: the top level) of project $project, else null; $except: the folder being renamed or moved
    public static function checkName($aid, $project, $parent, $name, $except = null) {
	$name = (string)$name;
	if ($name === '')
	    return 'Give the folder a name';
	if (mb_strlen($name) > self::MAX_NAME)
	    return 'At most ' . self::MAX_NAME . ' characters';
	if (preg_match('~[/\\\\\x00-\x1f\x7f]~', $name) || $name === '.' || $name === '..')
	    return 'A folder name cannot have / or \\, or be . or ..';
	$query = [ 'metadata.aid' => $aid, 'metadata.project' => $project, 'metadata.folder' => $parent, 'filename' => $name ];
	if (!empty($except))
	    $query['_id'] = [ '$ne' => $except ];
	if (\V::app()->om->count('Folder', $query) > 0)
	    return "There is already a folder `{$name}` here";
	return null;
    }

    //a new folder $name in $parent (a folder _id or null) of project $project: the Folder; \RuntimeException with what is wrong (checkName, too deep)
    public static function add($aid, $project, $parent, $name, $owner = null) {
	$name = trim((string)$name);
	if (($problem = self::checkName($aid, $project, $parent, $name)) !== null)
	    throw new \RuntimeException($problem);
	if (!empty($parent)) {
	    $above = \V::app()->om->findOne('Folder', [ '_id' => $parent, 'metadata.aid' => $aid, 'metadata.project' => $project ]);
	    if (!$above)
		throw new \RuntimeException('The folder it goes in is gone');
	    if (count($above->folders()) + 2 > self::MAX_DEPTH)
		throw new \RuntimeException('At most ' . self::MAX_DEPTH . ' folders deep');
	}

	$now = new \MongoDB\BSON\UTCDateTime();
	//what GridFS has in a file document (length, chunkSize, uploadDate), so the bucket's tools read it as an empty file
	$doc = [
	    '__variant' => 'Folder',
	    'filename' => $name,
	    'length' => 0,
	    'chunkSize' => static::$chunkSizeBytes,
	    'uploadDate' => $now,
	    'metadata' => [ 'project' => $project, 'aid' => $aid, 'owner' => $owner, 'folder' => $parent, 'created_at' => $now ],
	    'aid' => $aid,
	    'project' => $project,
	];
	try {
	    $id = \V::app()->mongo->selectCollection(self::_database(), self::_collection())->insertOne($doc)->getInsertedId();
	//the unique index: the same name made at the same time
	} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
	    throw new \RuntimeException("There is already a folder `{$name}` here");
	}
	//CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
	self::pushChanged($aid, $project, 1);
	return \V::app()->om->findOne('Folder', [ '_id' => $id ]);
    }

    //the _id of the folder at $path (e.g. `Invoices/2026`, from the top level) of project $project, made with the ones above it when missing; null for '' (the top level).
    //Steps saving files (File::createFromStream, option folder). \RuntimeException: a name that cannot be a folder's, too deep
    public static function resolve($aid, $project, $path) {
	$parent = null;
	$names = array_values(array_filter(array_map('trim', explode('/', str_replace('\\', '/', (string)$path))), fn($n) => $n !== ''));
	if (count($names) > self::MAX_DEPTH)
	    throw new \RuntimeException('Folder `' . $path . '`: at most ' . self::MAX_DEPTH . ' folders deep');
	foreach($names as $name) {
	    $folder = \V::app()->om->findOne('Folder', [ 'metadata.aid' => $aid, 'metadata.project' => $project, 'metadata.folder' => $parent, 'filename' => $name ]);
	    if (!$folder) {
		try {
		    $folder = self::add($aid, $project, $parent, $name);
		} catch (\RuntimeException $e) {
		    //made by another run meanwhile
		    $folder = \V::app()->om->findOne('Folder', [ 'metadata.aid' => $aid, 'metadata.project' => $project, 'metadata.folder' => $parent, 'filename' => $name ]);
		    if (!$folder)
			throw new \RuntimeException('Folder `' . $path . '`: ' . $e->getMessage());
		}
	    }
	    $parent = $folder->_id;
	}
	return $parent;
    }

    public function rename($name) {
	$name = trim((string)$name);
	$parent = $this->metadata['folder'] ?? null;
	if (($problem = self::checkName($this->metadata['aid'], $this->metadata['project'], $parent, $name, $this->_id)) !== null)
	    throw new \RuntimeException($problem);
	$this->update([ 'filename' => $name ]);
    }

    //moved into folder $target (a Folder, null: the top level); not into itself or a folder inside it
    public function moveTo($target) {
	$targetId = $target ? $target->_id : null;
	if ($target) {
	    $chain = array_merge($target->folders(), [ $target ]);
	    foreach($chain as $f) {
		if ((string)$f->_id === (string)$this->_id)
		    throw new \RuntimeException('A folder cannot go into itself or a folder inside it');
	    }
	    if (count($chain) + 1 > self::MAX_DEPTH)
		throw new \RuntimeException('At most ' . self::MAX_DEPTH . ' folders deep');
	}
	if (($problem = self::checkName($this->metadata['aid'], $this->metadata['project'], $targetId, (string)$this->filename, $this->_id)) !== null)
	    throw new \RuntimeException($problem);
	$this->update([ 'metadata.folder' => $targetId ]);
    }

    //$set on the folder's document (+ metadata.updated_at); the unique index: a name taken meanwhile
    protected function update(array $set) {
	try {
	    \V::app()->om->updateOne('Folder', [ '_id' => $this->_id, 'metadata.aid' => $this->metadata['aid'] ], [ '$set' => $set + [ 'metadata.updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
	} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
	    throw new \RuntimeException('There is already a folder with this name there');
	}
	//CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
	self::pushChanged($this->metadata['aid'] ?? null, $this->metadata['project'] ?? null);
    }

    //only an empty folder (no files, no folders in it): \RuntimeException otherwise
    public function delete($options = []) {
	if (empty($this->_id))
	    return false;
	$n = \V::app()->om->count('FileEntry', [ 'metadata.aid' => $this->metadata['aid'] ?? null, 'metadata.folder' => $this->_id ]);
	if ($n > 0)
	    throw new \RuntimeException('The folder is not empty (' . \util\Format::plural($n, 'item') . '): move or delete what is in it first');
	\V::app()->om->deleteOne('Folder', [ '_id' => $this->_id ]);
	//CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
	self::pushChanged($this->metadata['aid'] ?? null, $this->metadata['project'] ?? null);
	$this->_config['exists'] = false;
	$this->_data = [];
	return true;
    }
}
