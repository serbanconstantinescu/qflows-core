<?php

namespace models;

//CLD: 2026-11-27T10:00:00+02:00: a file of a project's Files, the default variant of FileEntry (the bucket, the fields, the access are FileEntry's); in a folder: metadata.folder
class File extends FileEntry {

    //the allow-list of uploadable files (isAllowedName; the upload's accept attribute, views/file/list.php): images, documents, presentations,
    //spreadsheets, text files and PDFs; anything else (archives, executables, scripts, unknown types) is refused
    //extension => type shown in the list
    public static $extensions = [
	//images
	'png' => 'Image',
	'jpg' => 'Image',
	'jpeg' => 'Image',
	'gif' => 'Image',
	'webp' => 'Image',
	'bmp' => 'Image',
	'tif' => 'Image',
	'tiff' => 'Image',
	'svg' => 'Image',
	'heic' => 'Image',
	//pdf
	'pdf' => 'PDF',
	//documents, presentations
	'doc' => 'Word',
	'docx' => 'Word',
	'odt' => 'Document',
	'rtf' => 'Document',
	'ppt' => 'PowerPoint',
	'pptx' => 'PowerPoint',
	'odp' => 'Presentation',
	//spreadsheets
	'xls' => 'Excel',
	'xlsx' => 'Excel',
	'ods' => 'Spreadsheet',
	'csv' => 'CSV',
	'tsv' => 'CSV',
	//text
	'txt' => 'Text',
	'log' => 'Text',
	'md' => 'Markdown',
	'html' => 'HTML',
	'htm' => 'HTML',
	'json' => 'JSON',
	'xml' => 'XML',
	'yaml' => 'YAML',
	'yml' => 'YAML',
    ];

    public static $imageMimeTypes = [
	'png' => 'image/png',
	'jpg' => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'gif' => 'image/gif',
	'webp' => 'image/webp',
    ];


    //the content type of a file, by extension (else application/octet-stream): uploads, attachments, files saved by steps
    const CONTENT_TYPES = [
	'pdf' => 'application/pdf',
	'png' => 'image/png',
	'jpg' => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'gif' => 'image/gif',
	'webp' => 'image/webp',
	'bmp' => 'image/bmp',
	'tif' => 'image/tiff',
	'tiff' => 'image/tiff',
	'svg' => 'image/svg+xml',
	'heic' => 'image/heic',
	'doc' => 'application/msword',
	'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'odt' => 'application/vnd.oasis.opendocument.text',
	'rtf' => 'application/rtf',
	'ppt' => 'application/vnd.ms-powerpoint',
	'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	'odp' => 'application/vnd.oasis.opendocument.presentation',
	'xls' => 'application/vnd.ms-excel',
	'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
	'csv' => 'text/csv',
	'tsv' => 'text/tab-separated-values',
	'txt' => 'text/plain',
	'log' => 'text/plain',
	'md' => 'text/markdown',
	'html' => 'text/html',
	'htm' => 'text/html',
	'json' => 'application/json',
	'xml' => 'application/xml',
	'yaml' => 'application/yaml',
	'yml' => 'application/yaml',
    ];

    // project file ids of a setting's value: one id, a list, or ids separated by commas; files of objects ({ id } of Download File / Google Drive) too
    public static function idsOf($value) {
	$list = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : explode(',', \util\Expression::toText($value));
	$ids = [];
	foreach($list as $v) {
	    $id = trim(is_array($v) ? (string)($v['id'] ?? $v['file'] ?? '') : \util\Expression::toText($v));
	    if ($id !== '')
		$ids[] = $id;
	}
	return array_values(array_unique($ids));
    }


    //true if a file with this name may be uploaded (its extension is in $extensions)
    public static function isAllowedName($filename) {
	$ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
	return $ext !== '' && isset(self::$extensions[$ext]);
    }



    //lower-case extension of the file name ('' if none)
    public function extension() {
	return strtolower(pathinfo((string)$this->filename, PATHINFO_EXTENSION));
    }

    //the image MIME type when the file can be previewed, else null
    public function imageMimeType() {
	return self::$imageMimeTypes[$this->extension()] ?? null;
    }

    public function _init() {
	parent::_init();

	if (!empty($this->filename)) {
	    $this->type = self::$extensions[$this->extension()] ?? 'Unknown';
	}
    }

    //override delete so that we let bucket do it's job
    public function delete($options = []) {
	if (!empty($this->_id)) {
	    self::_bucket()->delete($this->_id);
	    //CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
	    self::pushChanged($this->metadata['aid'] ?? null, $this->metadata['project'] ?? null, 1);

	    $this->_config['exists'] = false;
	    $this->_data = [];
	    return true;
	}
	return false;
    }

    //specific to File
    public function getContents() {
	if (!$this->exists())
	    throw new \ErrorException('Try to get contents on a non-existing file');

	return stream_get_contents(self::_bucket()->openDownloadStream($this->_id));
    }

    public static function createFromString($filename, $content = '', $options = []) {
	$stream = fopen("php://temp", 'r+');
	fputs($stream, $content);
	rewind($stream);

	$file_id = self::createFromStream($filename, $stream, $options);
	fclose($stream);

	return $file_id;
    }

    //CLD: 2026-11-27T10:00:00+02:00: $options['folder']: the path of the folder of the project it goes in (Folder::resolve: made when missing; ''/none: the top level),
    //or metadata.folder (a folder _id) given; \RuntimeException for a path that cannot be a folder's
    public static function createFromStream($filename, $stream, $options = []) {
	if (!is_resource($stream))
	    throw new \ErrorException('stream is not a resource');

	if (isset($options['folder'])) {
	    if (trim((string)$options['folder']) !== '')
		$options['metadata']['folder'] = Folder::resolve($options['metadata']['aid'] ?? null, $options['metadata']['project'] ?? null, $options['folder']);
	    unset($options['folder']);
	}

	//the tier's limit of the account's files (util\Tiers): refused when the file's size (when the stream knows it) does not fit; checked again once written
	//(a stream of unknown size): the file is removed when the account goes over the limit. LimitException: the step / the page shows its message
	$aid = $options['metadata']['aid'] ?? null;
	if (!empty($aid)) {
	    $st = @fstat($stream);
	    $size = is_array($st) && (int)($st['size'] ?? 0) > 0 ? max(0, (int)$st['size'] - (int)@ftell($stream)) : 0;
	    \util\Tiers::check($aid, 'files_bytes', $size);
	}

	//GridFS only takes custom data in `metadata`: copy metadata.aid to a top-level `aid` (+ __variant)
	//on the stored file document right after the upload (as FileEntry::beforeSave does on model saves)
	$file_id = self::_bucket()->uploadFromStream($filename, $stream, $options);

	if (!empty($aid)) {
	    try {
		\util\Tiers::check($aid, 'files_bytes', 0);
	    } catch (\util\LimitException $e) {
		self::_bucket()->delete($file_id);
		throw $e;
	    }
	}

	//a file of the FileEntry family (a document without it is one too: om's default variant)
	$set = [ '__variant' => 'File' ];
	foreach(self::TOP_LEVEL_METADATA as $key) {
	    if (!empty($options['metadata'][$key]))
		$set[$key] = $options['metadata'][$key];
	}
	\V::app()->mongo->selectCollection(self::_database(), self::_collection())->updateOne(
	    [ '_id' => $file_id ],
	    [ '$set' => $set ]
	);
	//CLD: 2026-11-28T10:00:00Z: the project's Files pages told (FileEntry::pushChanged)
	self::pushChanged($aid, $options['metadata']['project'] ?? null, 1);

	return $file_id;
    }

}
