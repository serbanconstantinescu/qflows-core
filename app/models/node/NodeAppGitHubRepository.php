<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub Repository - a repository's files, branches, tags, commits and releases (NodeAppGitHub). Operations:
	getFile        a file's content as text (at a branch, tag or commit), or saved as a project file
	putFile        a file written (a text or a project file) as a commit on a branch: created, or replaced (its current sha read first)
	deleteFile     a file removed as a commit
	listFolder     a folder's files and folders: { name, path, type (file, dir), size, sha, url }
	branches, createBranch (from a branch, tag or commit), tags, commits (of a branch, a path, since a time)
	releases, latestRelease, createRelease (a tag, made from a branch when new; name, notes or GitHub's generated ones, draft, pre-release), uploadAsset (a project file)
*/

namespace models\node;

class NodeAppGitHubRepository extends NodeAppGitHub {
    public static $name = 'github-repository';
    public static $label = 'GitHub Repository';
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
	    self::field('generateNotes', 'Generated notes', [ 'createRelease' ], [ 'type' => 'switch', 'default' => false, 'help' => 'GitHub writes the notes from the pull requests since the last release', 'ui' => [ 'width' => 6 ] ]),
	    self::field('notes', 'Notes', [ 'createRelease' ], [ 'type' => 'textarea', 'help' => 'Markdown (with Generated notes: before them)', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('draft', 'Draft', [ 'createRelease' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 3 ] ]),
	    self::field('prerelease', 'Pre-release', [ 'createRelease' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 3 ] ]),
	    self::field('assetName', 'File name', [ 'uploadAsset' ], [ 'help' => 'Empty: the project file\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //a ref of a setting: empty is null (the default branch)
    private static function ref($v) {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return null;
	if (preg_match('/[\x00-\x20~^:?*\[\\\\]|\.\.|@\{/', $t))
	    throw new \RuntimeException("not a branch, tag or commit: `" . mb_substr($t, 0, 80) . '`');
	return $t;
    }

    private function defaultBranch($repo) {
	return (string)($this->gh->request('GET', "/repos/{$repo}")['default_branch'] ?? 'main');
    }

    //a project file of the run's project
    private function projectFile($id) {
	$id = trim((string)$id);
	$file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($id, 0, 60) . '`');
	if ((int)$file->length > self::MAX_FILE_MB * 1024 * 1024)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . self::MAX_FILE_MB . ' MB');
	return $file;
    }

    private static function itemOf($i) {
	return [ 'name' => (string)($i['name'] ?? ''), 'path' => (string)($i['path'] ?? ''), 'type' => (string)($i['type'] ?? ''), 'size' => (int)($i['size'] ?? 0),
	    'sha' => (string)($i['sha'] ?? ''), 'url' => (string)($i['html_url'] ?? '') ];
    }

    private static function releaseOf($r) {
	$r = is_array($r) ? $r : [];
	return [ 'id' => (int)($r['id'] ?? 0), 'tag' => (string)($r['tag_name'] ?? ''), 'name' => (string)($r['name'] ?? ''), 'body' => (string)($r['body'] ?? ''), 'url' => (string)($r['html_url'] ?? ''),
	    'draft' => (bool)($r['draft'] ?? false), 'prerelease' => (bool)($r['prerelease'] ?? false), 'author' => (string)($r['author']['login'] ?? ''), 'created' => (string)($r['created_at'] ?? ''),
	    'published' => (string)($r['published_at'] ?? ''), 'assets' => array_map(fn($a) => [ 'id' => (int)($a['id'] ?? 0), 'name' => (string)($a['name'] ?? ''), 'size' => (int)($a['size'] ?? 0),
		'url' => (string)($a['browser_download_url'] ?? '') ], (array)($r['assets'] ?? [])) ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$repo = $this->repository();
	switch($operation) {
	    case 'getFile':
		$path = \util\GitHub::path($value('path'));
		$q = array_filter([ 'ref' => self::ref($value('ref')) ]);
		if ($this->cfg('as', 'text') === 'file') {
		    $name = basename(rawurldecode($path));
		    $filename = NodeAppDownload::filename($name, '', '', '');
		    if (!\models\File::isAllowedName($filename))
			throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
		    $stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		    try {
			$this->gh->request('GET', "/repos/{$repo}/contents/{$path}", $q, null, [ 'accept' => 'application/vnd.github.raw', 'sink' => $stream, 'maxBytes' => self::MAX_FILE_MB * 1024 * 1024 ]);
			$size = (int)fstat($stream)['size'];
			rewind($stream);
			$fid = \models\File::createFromStream($filename, $stream, [ 'folder' => $this->filesFolder(), 'metadata' => [ 'project' => $this->exec->project, 'aid' => $this->exec->aid, 'owner' => null, 'source' => 'github',
			    'wf' => $this->exec->wf, 'created_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
		    } finally {
			fclose($stream);
		    }
		    return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'path' => rawurldecode($path) ], rawurldecode($path) . " saved as `{$filename}` ({$size} bytes)" ];
		}
		$content = $this->gh->request('GET', "/repos/{$repo}/contents/{$path}", $q, null, [ 'accept' => 'application/vnd.github.raw', 'text' => true, 'maxBytes' => 20 * 1024 * 1024 ]);
		if (!mb_check_encoding($content, 'UTF-8'))
		    throw new \RuntimeException(rawurldecode($path) . ' is not text (UTF-8): read it as a project file');
		return [ $content, rawurldecode($path) . ' read (' . strlen($content) . ' bytes)' ];
	    case 'putFile':
		$path = \util\GitHub::path($value('path'));
		$branch = self::ref($value('branch'));
		if ($this->cfg('source', 'text') === 'file') {
		    $file = $this->projectFile($text('sourceFile'));
		    $bytes = stream_get_contents(\models\File::_bucket()->openDownloadStream($file->_id));
		} else {
		    $c = $value('content');
		    $bytes = is_array($c) ? json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n" : \util\Expression::toText($c);
		}
		$sha = null;
		try {
		    $cur = $this->gh->request('GET', "/repos/{$repo}/contents/{$path}", array_filter([ 'ref' => $branch ]));
		    $sha = is_array($cur) && ($cur['type'] ?? '') === 'file' ? (string)$cur['sha'] : null;
		    if (is_array($cur) && array_is_list($cur))
			throw new \RuntimeException(rawurldecode($path) . ' is a folder');
		} catch (\RuntimeException $e) {
		    if (!str_starts_with($e->getMessage(), 'HTTP status 404'))
			throw $e;
		}
		$message = $text('message');
		$d = $this->gh->request('PUT', "/repos/{$repo}/contents/{$path}", [], array_filter([ 'message' => $message !== '' ? $message : ($sha ? 'Update ' : 'Create ') . rawurldecode($path),
		    'content' => base64_encode($bytes), 'branch' => $branch, 'sha' => $sha ], fn($v) => $v !== null));
		return [ [ 'path' => rawurldecode($path), 'sha' => (string)($d['content']['sha'] ?? ''), 'commit' => (string)($d['commit']['sha'] ?? ''), 'url' => (string)($d['content']['html_url'] ?? ''),
		    'created' => $sha === null ], rawurldecode($path) . ($sha ? ' updated' : ' created') . ' in ' . substr((string)($d['commit']['sha'] ?? ''), 0, 7) ];
	    case 'deleteFile':
		$path = \util\GitHub::path($value('path'));
		$branch = self::ref($value('branch'));
		$cur = $this->gh->request('GET', "/repos/{$repo}/contents/{$path}", array_filter([ 'ref' => $branch ]));
		if (!is_array($cur) || ($cur['type'] ?? '') !== 'file')
		    throw new \RuntimeException(rawurldecode($path) . ' is not a file');
		$message = $text('message');
		$d = $this->gh->request('DELETE', "/repos/{$repo}/contents/{$path}", [], array_filter([ 'message' => $message !== '' ? $message : 'Delete ' . rawurldecode($path), 'sha' => (string)$cur['sha'],
		    'branch' => $branch ], fn($v) => $v !== null));
		return [ [ 'path' => rawurldecode($path), 'commit' => (string)($d['commit']['sha'] ?? ''), 'deleted' => true ], rawurldecode($path) . ' deleted' ];
	    case 'listFolder':
		$path = \util\GitHub::path($value('path'), 'Path', true);
		$d = $this->gh->request('GET', "/repos/{$repo}/contents" . ($path !== '' ? "/{$path}" : ''), array_filter([ 'ref' => self::ref($value('ref')) ]));
		if (!is_array($d) || !array_is_list($d))
		    throw new \RuntimeException(rawurldecode($path) . ' is a file, not a folder');
		$all = array_map(fn($i) => self::itemOf($i), $d);
		$items = array_slice($all, 0, $this->maxItems());
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => count($all) > count($items) ], \util\Format::plural(count($items), 'item') ];
	    case 'branches':
		return $this->listOf("/repos/{$repo}/branches", [], 'branch', fn($b) => [ 'name' => (string)($b['name'] ?? ''), 'sha' => (string)($b['commit']['sha'] ?? ''), 'protected' => (bool)($b['protected'] ?? false) ]);
	    case 'createBranch':
		$name = self::ref($value('branch'));
		if ($name === null)
		    throw new \RuntimeException('Branch: the new branch\'s name');
		$from = self::ref($value('ref')) ?? $this->defaultBranch($repo);
		$sha = preg_match('/^[0-9a-f]{40}$/', $from) ? $from : (string)($this->gh->request('GET', "/repos/{$repo}/commits/" . rawurlencode($from), [], null, [])['sha'] ?? '');
		$d = $this->gh->request('POST', "/repos/{$repo}/git/refs", [], [ 'ref' => "refs/heads/{$name}", 'sha' => $sha ]);
		return [ [ 'name' => $name, 'sha' => (string)($d['object']['sha'] ?? $sha), 'from' => $from ], "branch {$name} created from {$from}" ];
	    case 'tags':
		return $this->listOf("/repos/{$repo}/tags", [], 'tag', fn($t) => [ 'name' => (string)($t['name'] ?? ''), 'sha' => (string)($t['commit']['sha'] ?? '') ]);
	    case 'commits':
		$path = \util\GitHub::path($value('path'), 'Path', true);
		$q = array_filter([ 'sha' => self::ref($value('ref')), 'path' => $path !== '' ? rawurldecode($path) : null, 'since' => self::timeOf($value('since'), 'Since') ]);
		return $this->listOf("/repos/{$repo}/commits", $q, 'commit', fn($c) => [ 'sha' => (string)($c['sha'] ?? ''), 'message' => (string)($c['commit']['message'] ?? ''),
		    'author' => (string)($c['author']['login'] ?? $c['commit']['author']['name'] ?? ''), 'email' => (string)($c['commit']['author']['email'] ?? ''), 'date' => (string)($c['commit']['author']['date'] ?? ''),
		    'url' => (string)($c['html_url'] ?? '') ]);
	    case 'releases':
		return $this->listOf("/repos/{$repo}/releases", [], 'release', fn($r) => self::releaseOf($r));
	    case 'latestRelease':
		$r = self::releaseOf($this->gh->request('GET', "/repos/{$repo}/releases/latest"));
		return [ $r, "{$r['tag']} {$r['name']}" ];
	    case 'createRelease':
		$tag = self::ref($value('tag'));
		if ($tag === null)
		    throw new \RuntimeException('Tag: empty');
		$body = array_filter([ 'tag_name' => $tag, 'target_commitish' => self::ref($value('target')), 'name' => $text('releaseName') !== '' ? $text('releaseName') : $tag,
		    'body' => \util\Expression::toText($value('notes')), 'draft' => (bool)$this->cfg('draft', false), 'prerelease' => (bool)$this->cfg('prerelease', false),
		    'generate_release_notes' => (bool)$this->cfg('generateNotes', false) ], fn($v) => $v !== null && $v !== '');
		$r = self::releaseOf($this->gh->request('POST', "/repos/{$repo}/releases", [], $body));
		return [ $r, "release {$r['tag']} created" . ($r['draft'] ? ' (draft)' : '') ];
	    case 'uploadAsset':
		$tag = self::ref($value('tag'));
		$release = null;
		if ($tag !== null && preg_match('/^\d{1,20}$/', $tag)) {
		    try {
			$release = $this->gh->request('GET', "/repos/{$repo}/releases/{$tag}");
		    } catch (\RuntimeException $e) {
			$release = null;
		    }
		}
		if (!$release) {
		    try {
			$release = $this->gh->request('GET', "/repos/{$repo}/releases/tags/" . rawurlencode((string)$tag));
		    } catch (\RuntimeException $e) {
			//a draft has no tag yet: looked for among the releases
			[ $all ] = $this->gh->pages("/repos/{$repo}/releases", [], 100);
			$release = array_values(array_filter($all, fn($r) => ($r['tag_name'] ?? '') === $tag))[0] ?? null;
			if (!$release)
			    throw new \RuntimeException("Tag: no release {$tag}");
		    }
		}
		$file = $this->projectFile($text('sourceFile'));
		$name = $text('assetName') !== '' ? $text('assetName') : (string)$file->filename;
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$stream = \models\File::_bucket()->openDownloadStream($file->_id);
		try {
		    $a = $this->gh->request('POST', $this->gh->uploads() . "/repos/{$repo}/releases/{$release['id']}/assets", [ 'name' => $name ], null,
			[ 'upload' => [ $stream, (int)$file->length, \models\File::CONTENT_TYPES[$ext] ?? 'application/octet-stream' ] ]);
		} finally {
		    fclose($stream);
		}
		return [ [ 'id' => (int)($a['id'] ?? 0), 'name' => (string)($a['name'] ?? $name), 'size' => (int)($a['size'] ?? 0), 'url' => (string)($a['browser_download_url'] ?? ''), 'release' => (int)$release['id'] ],
		    "`{$name}` added to {$release['tag_name']}" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
