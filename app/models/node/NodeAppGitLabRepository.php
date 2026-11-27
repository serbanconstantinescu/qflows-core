<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab Repository - a project's files, branches, tags, commits and releases (NodeAppGitLab). Operations:
	getFile        a file's content as text (at a branch, tag or commit), or saved as a project file
	putFile        a file written (a text or a project file) as a commit on a branch: created or replaced (whether it exists asked first)
	deleteFile     a file removed as a commit
	listFolder     a folder's files and folders: { name, path, type (blob, tree), id }
	branches, createBranch (from a branch, tag or commit), tags, commits (of a branch, a path, since a time)
	releases, latestRelease, createRelease (a tag, made from a branch when new; name, notes), uploadAsset (a project file uploaded to the project, linked to the release)
*/

namespace models\node;

class NodeAppGitLabRepository extends NodeAppGitLab {
    public static $name = 'gitlab-repository';
    public static $label = 'GitLab Repository';
    public static $icon = 'bi-journal-code';
    public static $defaultOperation = 'getFile';
    public static $defaultVariable = 'repo';

    const OPERATIONS = [
	'getFile' => 'Read a file',
	'putFile' => 'Write a file (commit)',
	'deleteFile' => 'Delete a file (commit)',
	'listFolder' => 'List a folder',
	'branches' => 'List branches',
	'createBranch' => 'Create a branch',
	'tags' => 'List tags',
	'commits' => 'List commits',
	'releases' => 'List releases',
	'latestRelease' => 'Get the latest release',
	'createRelease' => 'Create a release',
	'uploadAsset' => 'Add a file to a release',
    ];
    const WRITES = [ 'putFile', 'deleteFile', 'createBranch', 'createRelease', 'uploadAsset' ];
    const LISTS = [ 'listFolder', 'branches', 'tags', 'commits', 'releases' ];
    const MAX_FILE_MB = 50;

    protected static function operationFields() {
	return [
	    self::field('path', 'Path', [ 'getFile', 'putFile', 'deleteFile', 'listFolder', 'commits' ], [ 'help' => 'In the repository, e.g. docs/README.md (List a folder: empty is the top; commits: optional)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ref', 'Branch, tag or commit', [ 'getFile', 'listFolder', 'commits', 'createBranch' ], [ 'help' => 'Empty: the default branch (Create a branch: where it starts)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('as', 'Read as', [ 'getFile' ], [ 'type' => 'select', 'required' => true, 'default' => 'text', 'choices' => [ 'text' => 'Text (into the variable)', 'file' => 'A project file' ], 'ui' => [ 'width' => 6 ] ]),
	    //CLD: 2026-11-27T12:00:00+02:00: the folder of Files the saved files go in
	    self::filesFolderField([ 'ui' => [ 'showIf' => "data.operation === 'getFile' && data.as === 'file'" ] ]),
	    self::field('branch', 'Branch', [ 'putFile', 'deleteFile', 'createBranch' ], [ 'help' => 'Write / delete: empty is the default branch; Create a branch: its name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('source', 'Content', [ 'putFile' ], [ 'type' => 'select', 'required' => true, 'default' => 'text', 'choices' => [ 'text' => 'A text', 'file' => 'A project file' ], 'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('content', 'Text', [ 'putFile' ], [ 'type' => 'textarea', 'help' => '{{ ... }} for expressions (a list / object is written as JSON)', 'ui' => [ 'rows' => 5, 'expression' => true,
		'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ] ]),
	    self::field('sourceFile', 'Project file', [ 'putFile', 'uploadAsset' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }}', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('message', 'Commit message', [ 'putFile', 'deleteFile' ], [ 'help' => 'Empty: Update <path> / Delete <path>', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('since', 'Since', [ 'commits' ], [ 'help' => 'Optional: a date and time (ISO 8601)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('tag', 'Tag', [ 'createRelease', 'uploadAsset' ], [ 'required' => true, 'help' => 'e.g. v1.4.0 (a new one is made from Target)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('target', 'Target', [ 'createRelease' ], [ 'help' => 'A new tag\'s branch or commit; empty: the default branch', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('releaseName', 'Name', [ 'createRelease' ], [ 'help' => 'Empty: the tag', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('notes', 'Notes', [ 'createRelease' ], [ 'type' => 'textarea', 'help' => 'Markdown', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('assetName', 'File name', [ 'uploadAsset' ], [ 'help' => 'Empty: the project file\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    private static function ref($v) {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return null;
	if (preg_match('/[\x00-\x20~^:?*\[\\\\]|\.\.|@\{/', $t))
	    throw new \RuntimeException("not a branch, tag or commit: `" . mb_substr($t, 0, 80) . '`');
	return $t;
    }

    private function defaultBranch($project) {
	return (string)($this->gl->request('GET', "/projects/{$project}")['default_branch'] ?? 'main');
    }

    private function projectFile($id) {
	$id = trim((string)$id);
	$file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($id, 0, 60) . '`');
	if ((int)$file->length > self::MAX_FILE_MB * 1024 * 1024)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . self::MAX_FILE_MB . ' MB');
	return $file;
    }

    private static function releaseOf($r) {
	$r = is_array($r) ? $r : [];
	return [ 'tag' => (string)($r['tag_name'] ?? ''), 'name' => (string)($r['name'] ?? ''), 'description' => (string)($r['description'] ?? ''), 'url' => (string)($r['_links']['self'] ?? ''),
	    'author' => (string)($r['author']['username'] ?? ''), 'created' => (string)($r['created_at'] ?? ''), 'released' => (string)($r['released_at'] ?? ''), 'upcoming' => (bool)($r['upcoming_release'] ?? false),
	    'commit' => (string)($r['commit']['id'] ?? ''), 'assets' => array_map(fn($a) => [ 'id' => (int)($a['id'] ?? 0), 'name' => (string)($a['name'] ?? ''), 'url' => (string)($a['url'] ?? '') ],
		(array)($r['assets']['links'] ?? [])) ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$project = $this->project();
	switch($operation) {
	    case 'getFile':
		$fp = \util\GitLab::filePath($value('path'));
		$ref = self::ref($value('ref')) ?? $this->defaultBranch($project);
		$url = "/projects/{$project}/repository/files/" . rawurlencode($fp) . '/raw';
		if ($this->cfg('as', 'text') === 'file') {
		    $filename = NodeAppDownload::filename(basename($fp), '', '', '');
		    if (!\models\File::isAllowedName($filename))
			throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
		    $stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		    try {
			$this->gl->request('GET', $url, [ 'ref' => $ref ], null, [ 'sink' => $stream, 'maxBytes' => self::MAX_FILE_MB * 1024 * 1024 ]);
			$size = (int)fstat($stream)['size'];
			rewind($stream);
			$fid = \models\File::createFromStream($filename, $stream, [ 'folder' => $this->filesFolder(), 'metadata' => [ 'project' => $this->exec->project, 'aid' => $this->exec->aid, 'owner' => null, 'source' => 'gitlab',
			    'wf' => $this->exec->wf, 'created_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
		    } finally {
			fclose($stream);
		    }
		    return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'path' => $fp ], "{$fp} saved as `{$filename}` ({$size} bytes)" ];
		}
		$content = $this->gl->request('GET', $url, [ 'ref' => $ref ], null, [ 'text' => true, 'maxBytes' => 20 * 1024 * 1024 ]);
		if (!mb_check_encoding($content, 'UTF-8'))
		    throw new \RuntimeException("{$fp} is not text (UTF-8): read it as a project file");
		return [ $content, "{$fp} read (" . strlen($content) . ' bytes)' ];
	    case 'putFile':
		$fp = \util\GitLab::filePath($value('path'));
		$branch = self::ref($value('branch')) ?? $this->defaultBranch($project);
		if ($this->cfg('source', 'text') === 'file') {
		    $file = $this->projectFile($text('sourceFile'));
		    $bytes = stream_get_contents(\models\File::_bucket()->openDownloadStream($file->_id));
		} else {
		    $c = $value('content');
		    $bytes = is_array($c) ? json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n" : \util\Expression::toText($c);
		}
		$path = "/projects/{$project}/repository/files/" . rawurlencode($fp);
		[ $status ] = $this->gl->request('HEAD', $path, [ 'ref' => $branch ], null, [ 'full' => true, 'ok' => [ 404 ] ]);
		$exists = $status !== 404;
		$message = $text('message');
		$d = $this->gl->request($exists ? 'PUT' : 'POST', $path, [], [ 'branch' => $branch, 'content' => base64_encode($bytes), 'encoding' => 'base64',
		    'commit_message' => $message !== '' ? $message : ($exists ? 'Update ' : 'Create ') . $fp ]);
		return [ [ 'path' => $fp, 'branch' => (string)($d['branch'] ?? $branch), 'created' => !$exists ], "{$fp}" . ($exists ? ' updated' : ' created') . " on {$branch}" ];
	    case 'deleteFile':
		$fp = \util\GitLab::filePath($value('path'));
		$branch = self::ref($value('branch')) ?? $this->defaultBranch($project);
		$message = $text('message');
		$this->gl->request('DELETE', "/projects/{$project}/repository/files/" . rawurlencode($fp), [], [ 'branch' => $branch, 'commit_message' => $message !== '' ? $message : "Delete {$fp}" ]);
		return [ [ 'path' => $fp, 'branch' => $branch, 'deleted' => true ], "{$fp} deleted on {$branch}" ];
	    case 'listFolder':
		$t = trim(\util\Expression::toText($value('path')), '/ ');
		$q = array_filter([ 'path' => $t !== '' ? \util\GitLab::filePath($t) : null, 'ref' => self::ref($value('ref')) ]);
		return $this->listOf("/projects/{$project}/repository/tree", $q, 'item', fn($i) => [ 'name' => (string)($i['name'] ?? ''), 'path' => (string)($i['path'] ?? ''),
		    'type' => (string)($i['type'] ?? ''), 'id' => (string)($i['id'] ?? '') ]);
	    case 'branches':
		return $this->listOf("/projects/{$project}/repository/branches", [], 'branch', fn($b) => [ 'name' => (string)($b['name'] ?? ''), 'sha' => (string)($b['commit']['id'] ?? ''),
		    'protected' => (bool)($b['protected'] ?? false), 'default' => (bool)($b['default'] ?? false), 'url' => (string)($b['web_url'] ?? '') ]);
	    case 'createBranch':
		$name = self::ref($value('branch'));
		if ($name === null)
		    throw new \RuntimeException('Branch: the new branch\'s name');
		$from = self::ref($value('ref')) ?? $this->defaultBranch($project);
		$b = $this->gl->request('POST', "/projects/{$project}/repository/branches", [], [ 'branch' => $name, 'ref' => $from ]);
		return [ [ 'name' => (string)($b['name'] ?? $name), 'sha' => (string)($b['commit']['id'] ?? ''), 'from' => $from ], "branch {$name} created from {$from}" ];
	    case 'tags':
		return $this->listOf("/projects/{$project}/repository/tags", [], 'tag', fn($t) => [ 'name' => (string)($t['name'] ?? ''), 'sha' => (string)($t['commit']['id'] ?? ''), 'message' => (string)($t['message'] ?? '') ]);
	    case 'commits':
		$t = trim(\util\Expression::toText($value('path')), '/ ');
		$q = array_filter([ 'ref_name' => self::ref($value('ref')), 'path' => $t !== '' ? \util\GitLab::filePath($t) : null, 'since' => self::timeOf($value('since'), 'Since') ]);
		return $this->listOf("/projects/{$project}/repository/commits", $q, 'commit', fn($c) => [ 'sha' => (string)($c['id'] ?? ''), 'title' => (string)($c['title'] ?? ''),
		    'message' => (string)($c['message'] ?? ''), 'author' => (string)($c['author_name'] ?? ''), 'email' => (string)($c['author_email'] ?? ''), 'date' => (string)($c['authored_date'] ?? $c['created_at'] ?? ''),
		    'url' => (string)($c['web_url'] ?? '') ]);
	    case 'releases':
		return $this->listOf("/projects/{$project}/releases", [], 'release', fn($r) => self::releaseOf($r));
	    case 'latestRelease':
		$r = self::releaseOf($this->gl->request('GET', "/projects/{$project}/releases/permalink/latest"));
		return [ $r, "{$r['tag']} {$r['name']}" ];
	    case 'createRelease':
		$tag = self::ref($value('tag'));
		if ($tag === null)
		    throw new \RuntimeException('Tag: empty');
		$body = array_filter([ 'tag_name' => $tag, 'ref' => self::ref($value('target')) ?? $this->defaultBranch($project), 'name' => $text('releaseName') !== '' ? $text('releaseName') : $tag,
		    'description' => \util\Expression::toText($value('notes')) ], fn($v) => $v !== null && $v !== '');
		$r = self::releaseOf($this->gl->request('POST', "/projects/{$project}/releases", [], $body));
		return [ $r, "release {$r['tag']} created" ];
	    case 'uploadAsset':
		$tag = self::ref($value('tag'));
		if ($tag === null)
		    throw new \RuntimeException('Tag: empty');
		$file = $this->projectFile($text('sourceFile'));
		$name = $text('assetName') !== '' ? $text('assetName') : (string)$file->filename;
		if (preg_match('/[\x00-\x1f"\\\\\/]/', $name))
		    throw new \RuntimeException("File name: not a file name: `{$name}`");
		$bytes = stream_get_contents(\models\File::_bucket()->openDownloadStream($file->_id));
		$boundary = 'qflows' . bin2hex(random_bytes(12));
		$multipart = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
		$up = $this->gl->request('POST', "/projects/{$project}/uploads", [], null, [ 'raw' => [ $multipart, "multipart/form-data; boundary={$boundary}" ] ]);
		$full = (string)($up['full_path'] ?? '');
		$url = $full !== '' ? $this->gl->base() . $full : (string)($up['url'] ?? '');
		$link = $this->gl->request('POST', "/projects/{$project}/releases/" . rawurlencode($tag) . '/assets/links', [], [ 'name' => $name, 'url' => $url, 'link_type' => 'other' ]);
		return [ [ 'id' => (int)($link['id'] ?? 0), 'name' => $name, 'size' => strlen($bytes), 'url' => (string)($link['url'] ?? $url), 'release' => $tag ], "`{$name}` added to {$tag}" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
