<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: log files on a server read through SSH (util\Ssh) from where the last read stopped, for the SSH step's "Read a log file"
    (models\node\NodeAppSsh) and the Log watch trigger (models\node\NodeTriggerLog). Two scripts on the server, the decisions here:
	1. list:  the files of the paths (one per line, shell patterns: /var/log/nginx/*.log), each resolved (readlink -f) with its inode and size; and, for the files of the
	          last position no longer there, the file of the same folder that has their inode now (a log rotated by renaming: app.log -> app.log.1)
	2. read:  byte ranges of the allowed files (each checked again to be the file resolved: no link put in between), as length-prefixed blocks
    Allowed: only files under the credential's log folders (CredentialSsh `logFolders`, /var/log by default; compared on the resolved path, so ../ and links do not leave
    them). Compressed files (.gz .xz .bz2 .zst .lz4 .zip) are left out: their text is not where the position says.
    The position: [ { path, inode, offset } ] (what read() gives back, kept by the caller: a variable, a data table, the trigger's state), by inode so a renamed file goes on:
	- none (the first read) or $from 'end': the last N lines of each file, the position at its end; $from 'mark': nothing read, the position at the ends
	- a file of the position (by inode): from its offset; smaller than the offset (truncated: copytruncate) from the start
	- a file of the position gone from the paths (rotated): the rest of it read where it is now, then dropped; not found (compressed, deleted): `lost` in the notes
	- a file not in the position: from its start (a new log)
    Lines: whole lines only (a line still being written waits for the next read, except at the end of a rotated file); at most MAX_LINE bytes each (cut); invalid UTF-8
    replaced; the filter (a regular expression) keeps the matching ones; at most $maxLines lines and MAX_BYTES read per call: the position stops after the last line given,
    `more` says there is more. The result: { lines: [ { file, text } ], count, more, position, notes }
*/

namespace util;

class LogFiles {
    //bytes read in one call (ssh's output is cut at Ssh::MAX_OUTPUT), a line's bytes, lines given
    const MAX_BYTES = 768 * 1024;
    const MAX_LINE = 8192;
    const MAX_LINES = 10000;
    const MAX_PATHS = 20;
    const MAX_FILES = 50;
    const DEFAULT_FOLDERS = [ '/var/log' ];
    const COMPRESSED = '/\.(gz|xz|bz2|zst|lz4|zip|Z)$/';
    const FROM = [ 'position' => 'Where the last read stopped', 'end' => 'The last lines' ];

    //the paths of a setting (one per line): absolute, no NUL, at most MAX_PATHS; \RuntimeException
    public static function paths($text) {
	$paths = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$text)), fn($p) => $p !== ''));
	if (!$paths)
	    throw new \RuntimeException('Log files: empty');
	if (count($paths) > self::MAX_PATHS)
	    throw new \RuntimeException('Log files: at most ' . self::MAX_PATHS . ' paths');
	foreach($paths as $p) {
	    if ($p[0] !== '/' || str_contains($p, "\0") || strlen($p) > 1024)
		throw new \RuntimeException("Log files: not an absolute path: {$p}");
	}
	return $paths;
    }

    //the log folders of a credential's setting (one per line; empty: DEFAULT_FOLDERS): absolute, without a trailing /, no .. part
    public static function folders($text) {
	$list = [];
	foreach(preg_split('/\R/', (string)$text) as $f) {
	    $f = rtrim(trim($f), '/');
	    if ($f === '')
		continue;
	    if ($f[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $f) || str_contains($f, "\0"))
		throw new \RuntimeException("Log folders: not an absolute folder: {$f}");
	    $list[] = $f;
	}
	return $list ?: self::DEFAULT_FOLDERS;
    }

    public static function allowed($path, array $folders) {
	foreach($folders as $f) {
	    if (str_starts_with($path, $f . '/'))
		return true;
	}
	return false;
    }

    //a filter's regular expression (PCRE, without delimiters; empty: none) as a pattern, or a \RuntimeException
    public static function filter($text) {
	$text = trim((string)$text);
	if ($text === '')
	    return null;
	$re = '~' . str_replace('~', '\~', $text) . '~u';
	if (@preg_match($re, '') === false)
	    throw new \RuntimeException('Only lines matching: not a regular expression: ' . $text);
	return $re;
    }

    //a position given back (a list of { path, inode, offset }, its JSON text, or empty): [ inode => [ path, offset ] ]
    public static function position($value) {
	if (is_string($value)) {
	    $value = trim($value);
	    if ($value === '')
		return [];
	    $value = json_decode($value, true);
	    if (!is_array($value))
		throw new \RuntimeException('Position: not a list of { path, inode, offset } (JSON)');
	}
	if ($value === null || $value === '')
	    return [];
	if (!is_array($value) || !array_is_list($value))
	    throw new \RuntimeException('Position: not a list of { path, inode, offset }');
	$pos = [];
	foreach($value as $e) {
	    if (!is_array($e) || !isset($e['inode'], $e['path']) || !is_numeric($e['inode']))
		throw new \RuntimeException('Position: an entry without path or inode');
	    $pos[(string)(int)$e['inode']] = [ (string)$e['path'], max(0, (int)($e['offset'] ?? 0)) ];
	}
	return $pos;
    }

    //the list script: the paths' files (F inode size resolved), the patterns matching nothing (M pattern), and for each inode asked the file of its folder that has it (R inode size resolved)
    const LIST_SCRIPT = <<<'SH'
run() { if [ "$P_sudo" = 1 ]; then sudo -n "$@"; else "$@"; fi; }
IFS='
'
for pat in $P_paths; do
  found=
  for f in $pat; do
    run test -e "$f" || continue
    rp=$(run readlink -f -- "$f") || continue
    run test -f "$rp" || continue
    st=$(run stat -L -c '%i %s' -- "$rp" 2>/dev/null) || continue
    printf 'F\t%s\t%s\t%s\n' "${st% *}" "${st#* }" "$rp"
    found=1
  done
  [ -n "$found" ] || printf 'M\t%s\n' "$pat"
done
set -f
TAB=$(printf '\t')
for line in $P_inodes; do
  ino=${line%%"$TAB"*}; dir=${line#*"$TAB"}
  run test -d "$dir" || continue
  rp=$(run find "$dir" -maxdepth 1 -type f -inum "$ino" -print 2>/dev/null | head -n 1)
  [ -n "$rp" ] || continue
  st=$(run stat -L -c '%i %s' -- "$rp" 2>/dev/null) || continue
  printf 'R\t%s\t%s\t%s\n' "${st% *}" "${st#* }" "$rp"
done
exit 0
SH;

    //the read script: for each range (start length inode path) a block "@@QFLOG <n> <bytes>" + the bytes; a file that is no longer that file: <bytes> -1
    const READ_SCRIPT = <<<'SH'
run() { if [ "$P_sudo" = 1 ]; then sudo -n "$@"; else "$@"; fi; }
t=$(mktemp) || exit 3
trap 'rm -f "$t"' EXIT
n=0
TAB=$(printf '\t')
printf '%s\n' "$P_ranges" | while IFS="$TAB" read -r start len ino p; do
  [ -n "$p" ] || continue
  rp=$(run readlink -f -- "$p" 2>/dev/null)
  cur=$(run stat -L -c '%i' -- "$p" 2>/dev/null)
  if [ "$rp" != "$p" ] || [ "$cur" != "$ino" ]; then
    printf '@@QFLOG %s -1\n' "$n"
  else
    run tail -c +$((start + 1)) -- "$p" 2>/dev/null | head -c "$len" > "$t"
    printf '@@QFLOG %s %s\n' "$n" "$(wc -c < "$t" | tr -d ' ')"
    cat "$t"
  fi
  n=$((n + 1))
done
exit 0
SH;

    /*
	reads the log files of $paths through $ssh: $opts
	    folders     the allowed folders (folders())
	    filter      a pattern (filter()) or null
	    from        position | end | mark
	    position    [ inode => [ path, offset ] ] (position())
	    lines       the most lines given (and the last N lines for `end` and a first read)
	    sudo        the commands with sudo -n
	    timeout     seconds of each of the two scripts
	the result described above
    */
    public static function read(Ssh $ssh, array $paths, array $opts) {
	$folders = $opts['folders'] ?? self::DEFAULT_FOLDERS;
	$filter = $opts['filter'] ?? null;
	$from = (string)($opts['from'] ?? 'position');
	$saved = (array)($opts['position'] ?? []);
	$maxLines = max(1, min(self::MAX_LINES, (int)($opts['lines'] ?? 1000)));
	$sudo = !empty($opts['sudo']) ? '1' : '';
	$timeout = max(1, (int)($opts['timeout'] ?? 60));
	if (!$saved && $from === 'position')
	    $from = 'end';

	//1. the files; for the inodes of the position the folders of their last paths
	$inodes = [];
	foreach($saved as $ino => [ $path ])
	    $inodes[] = $ino . "\t" . (dirname($path) ?: '/');
	[ $out, $err, $code ] = $ssh->run(self::LIST_SCRIPT, [ 'paths' => implode("\n", $paths), 'inodes' => implode("\n", $inodes), 'sudo' => $sudo ], $timeout);
	if ($code !== 0)
	    throw new \RuntimeException('listing the log files failed (exit code ' . $code . ')' . (trim($err) !== '' ? ': ' . trim(mb_substr($err, 0, 300)) : ''));
	[ $files, $rotated, $notes ] = self::listed($out, $folders);

	//2. the ranges: [ start, end, inode, path, rotated? (its end is the end of its lines), tail lines (end mode) ]
	$ranges = [];
	$newPos = [];
	foreach($files as $ino => [ $path, $size ]) {
	    if ($from === 'mark' || $from === 'end') {
		$newPos[$ino] = [ $path, $size ];
		if ($from === 'end' && $size > 0)
		    $ranges[] = [ max(0, $size - self::MAX_BYTES), $size, $ino, $path, false, $maxLines ];
		continue;
	    }
	    $offset = isset($saved[$ino]) ? $saved[$ino][1] : 0;
	    if ($offset > $size) {
		$notes[] = "truncated: {$path} (read from its start)";
		$offset = 0;
	    }
	    $newPos[$ino] = [ $path, $offset ];
	    if ($size > $offset)
		$ranges[] = [ $offset, $size, $ino, $path, false, 0 ];
	}
	if ($from === 'position') {
	    //the files of the position no longer among the paths' files: rotated (found by their inode) or lost; the rotated ones first, they are older
	    $before = [];
	    foreach($saved as $ino => [ $path, $offset ]) {
		if (isset($files[$ino]))
		    continue;
		if (!isset($rotated[$ino])) {
		    $notes[] = "lost: {$path} (rotated away before it was read to its end)";
		    continue;
		}
		[ $rpath, $rsize ] = $rotated[$ino];
		$notes[] = "rotated: {$path} -> {$rpath}";
		if ($rsize > $offset) {
		    $before[] = [ $offset, $rsize, $ino, $rpath, true, 0 ];
		    $newPos[$ino] = [ $rpath, $offset ];
		}
	    }
	    $ranges = array_merge($before, $ranges);
	}

	//3. the bytes, at most MAX_BYTES in all (the first ranges first)
	$lines = [];
	$more = false;
	if ($ranges) {
	    $budget = self::MAX_BYTES;
	    $asked = [];
	    foreach($ranges as $i => $r) {
		$len = min($r[1] - $r[0], $budget);
		if ($len <= 0) {
		    $more = $more || $from === 'position';
		    break;
		}
		//the last lines: the end of the file, its start inside a line
		if ($r[5] > 0 && $len < $r[1] - $r[0])
		    $ranges[$i][0] = $r[1] - $len;
		$asked[$i] = $len;
		$budget -= $len;
	    }
	    $spec = implode("\n", array_map(fn($i) => $ranges[$i][0] . "\t" . $asked[$i] . "\t" . $ranges[$i][2] . "\t" . $ranges[$i][3], array_keys($asked)));
	    [ $out, $err, $code ] = $ssh->run(self::READ_SCRIPT, [ 'ranges' => $spec, 'sudo' => $sudo ], $timeout);
	    if ($code !== 0)
		throw new \RuntimeException('reading the log files failed (exit code ' . $code . ')' . (trim($err) !== '' ? ': ' . trim(mb_substr($err, 0, 300)) : ''));
	    $blocks = self::blocks($out);
	    foreach(array_keys($asked) as $n => $i) {
		[ $start, $end, $ino, $path, $isRotated, $tail ] = $ranges[$i];
		$data = $blocks[$n] ?? null;
		if ($data === null) {
		    $notes[] = "changed while read: {$path} (the next read takes it)";
		    $more = true;
		    continue;
		}
		if ($tail > 0) {
		    [ $got ] = self::split($data, $start, $path, $filter, $tail, true, false, $start > 0);
		    $lines = array_merge($lines, $got);
		    continue;
		}
		$cut = $asked[$i] < $end - $start;
		[ $got, $used, $full ] = self::split($data, $start, $path, $filter, $maxLines - count($lines), false, $isRotated && !$cut);
		$lines = array_merge($lines, $got);
		$more = $more || $cut || $full;
		if ($isRotated && $start + $used >= $end)
		    unset($newPos[$ino]);
		else
		    $newPos[$ino] = [ $path, $start + $used ];
		if ($from === 'position' && count($lines) >= $maxLines) {
		    //the later ranges are not taken: their offsets stay
		    $more = $more || $n < count($asked) - 1;
		    break;
		}
	    }
	    if ($from === 'end' && count($lines) > $maxLines)
		$lines = array_slice($lines, -$maxLines);
	}
	$position = [];
	foreach($newPos as $ino => [ $path, $offset ])
	    $position[] = [ 'path' => $path, 'inode' => (int)$ino, 'offset' => $offset ];
	return [ 'lines' => $lines, 'count' => count($lines), 'more' => $more, 'position' => $position, 'notes' => $notes ];
    }

    //the list script's output: [ files [ inode => [ path, size ] ], rotated [ inode => [ path, size ] ], notes ]
    public static function listed($out, array $folders) {
	$files = $rotated = [];
	$notes = [];
	foreach(preg_split('/\n/', (string)$out) as $line) {
	    $c = explode("\t", $line, 4);
	    if ($c[0] === 'M' && isset($c[1])) {
		$notes[] = "no file: {$c[1]}";
		continue;
	    }
	    if (!in_array($c[0], [ 'F', 'R' ], true) || count($c) < 4 || !ctype_digit($c[1]) || !ctype_digit($c[2]))
		continue;
	    [ , $ino, $size, $path ] = $c;
	    if (!self::allowed($path, $folders)) {
		$notes[] = "not in the log folders: {$path}";
		continue;
	    }
	    if (preg_match(self::COMPRESSED, $path))
		continue;
	    if ($c[0] === 'F') {
		if (count($files) >= self::MAX_FILES) {
		    $notes[] = 'more than ' . self::MAX_FILES . " files: {$path} and the others are not read";
		    break;
		}
		$files[$ino] = [ $path, (int)$size ];
	    } else
		$rotated[$ino] = [ $path, (int)$size ];
	}
	return [ $files, $rotated, array_values(array_unique($notes)) ];
    }

    //the read script's blocks: [ n => bytes, or null (the file changed) ]
    public static function blocks($out) {
	$blocks = [];
	$pos = 0;
	$len = strlen((string)$out);
	while ($pos < $len) {
	    $eol = strpos($out, "\n", $pos);
	    if ($eol === false || !preg_match('/^@@QFLOG (\d+) (-1|\d+)$/', substr($out, $pos, $eol - $pos), $m))
		break;
	    $n = (int)$m[1];
	    $size = (int)$m[2];
	    $pos = $eol + 1;
	    if ($size < 0) {
		$blocks[$n] = null;
		continue;
	    }
	    $blocks[$n] = (string)substr($out, $pos, $size);
	    $pos += $size;
	}
	return $blocks;
    }

    /*
	the lines of $data (bytes from offset $start of $path): [ lines [ { file, text } ], bytes used, the most lines reached ]
	    $max      the most lines kept; $last: the last $max of them (all read), else the first $max (the bytes after the last one not used)
	    $final    the data's end is the file's end for good (a rotated file): a last line without its line break is a line
	    $partial  the data starts inside a line (the last lines of a large file): its first line left out
    */
    public static function split($data, $start, $path, $filter, $max, $last = false, $final = false, $partial = false) {
	$lines = [];
	$pos = 0;
	$len = strlen($data);
	if ($partial) {
	    $eol = strpos($data, "\n");
	    $pos = $eol === false ? $len : $eol + 1;
	}
	$full = false;
	$used = $pos;
	while ($pos < $len) {
	    $eol = strpos($data, "\n", $pos);
	    if ($eol === false) {
		//a line still being written waits; a line longer than all the data read is taken cut, so the reading goes on
		if (!$final && !($pos === 0 && $len >= self::MAX_BYTES))
		    break;
		$eol = $len;
	    }
	    $raw = rtrim(substr($data, $pos, min($eol - $pos, self::MAX_LINE)), "\r");
	    $pos = min($len, $eol + 1);
	    $text = mb_scrub($raw, 'UTF-8');
	    if ($text !== '' && ($filter === null || preg_match($filter, $text))) {
		if (!$last && count($lines) >= $max) {
		    $full = true;
		    break;
		}
		$lines[] = [ 'file' => $path, 'text' => $text ];
	    }
	    $used = $pos;
	}
	if ($last && count($lines) > $max)
	    $lines = array_slice($lines, -$max);
	return [ $lines, $used, $full ];
    }
}
