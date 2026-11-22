<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: log lines grouped into patterns, for the Log patterns step (models\node\NodeAppLogPatterns): what an SRE agent learns as "normal".
	parse     each line: its message, level, service (format: auto, plain, json, syslog, access (nginx / Apache combined), regex with named groups message, level, service)
	redact    secrets out of the text before anything else: private keys, Authorization headers, password= / token= / api_key= ... values, JWTs, cloud keys (AWS, GitHub,
	          Slack, sk-...), a URL's password, email addresses; IP addresses when asked
	template  the message's variable parts masked: <uuid> <ip> <hex> <time> <num> (numbers inside a token too: /users/<num>, pid=<num>; an access log's status kept),
	          at most MAX_TOKENS tokens
	mine      the templates grouped (Drain: by service, token count and first token; a line joins the most similar group when at least `similarity` of its tokens are
	          the same, the positions that differ become <*>); the known patterns (a catalog kept by the workflow) first: a line like one of them joins it and its
	          template and signature stay as they are, so a catalog keeps them from run to run
    A pattern: { signature (16 hex: of the service and the template, or the known one's), template, service, level (the most severe), count, sample (the first line, redacted,
    at most MAX_SAMPLE characters), known }; the result: { patterns (the most frequent first), count, lines, newPatterns }
*/

namespace util;

class LogPatterns {
    const FORMATS = [ 'auto' => 'Find out', 'plain' => 'Plain text', 'json' => 'JSON lines', 'syslog' => 'Syslog', 'access' => 'Web access log (combined)',
	'regex' => 'A regular expression' ];
    const MAX_LINES = 100000;
    const MAX_LINE = 8192;
    const MAX_TOKENS = 120;
    const MAX_PATTERNS = 5000;
    const MAX_SAMPLE = 500;
    const WILD = '<*>';
    //a token that starts with it is kept as it is (an access log's status: 502, not <num>)
    const KEEP = "\x01";
    //the levels, the most severe first; the words that name them
    const LEVELS = [ 'critical' => 5, 'error' => 4, 'warning' => 3, 'notice' => 2, 'info' => 1, 'debug' => 0 ];
    const LEVEL_WORDS = [ 'fatal' => 'critical', 'crit' => 'critical', 'critical' => 'critical', 'emerg' => 'critical', 'emergency' => 'critical', 'alert' => 'critical',
	'panic' => 'critical', 'err' => 'error', 'error' => 'error', 'severe' => 'error', 'warn' => 'warning', 'warning' => 'warning', 'notice' => 'notice', 'info' => 'info',
	'information' => 'info', 'informational' => 'info', 'debug' => 'debug', 'trace' => 'debug', 'verbose' => 'debug' ];
    const JSON_MESSAGE = [ 'message', 'msg', 'log', 'event', 'text', 'error' ];
    const JSON_LEVEL = [ 'level', 'severity', 'lvl', 'loglevel', 'log.level', 'levelname' ];
    const JSON_SERVICE = [ 'service', 'service.name', 'app', 'application', 'logger', 'component', 'container', 'program' ];
    const SYSLOG = '/^(?:<\d+>)?(?:[A-Z][a-z]{2} [ \d]\d \d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\S*)\s+(\S+)\s+([^\s\[:]+)(?:\[\d+\])?:\s?(.*)$/s';
    const ACCESS = '/^(\S+) \S+ \S+ \[[^\]]+\] "(\S+) ([^"? ]*)[^"]*" (\d{3}) (\S+)/';
    //a time or date at the start of a plain line
    const LEADING_TIME = '/^\[?(?:\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:[.,]\d+)?(?:Z|[+-]\d\d:?\d\d)?|[A-Z][a-z]{2} [ \d]\d \d\d:\d\d:\d\d|\d\d\/[A-Z][a-z]{2}\/\d{4}:\d\d:\d\d:\d\d(?: [+-]\d{4})?)\]?\s*/';

    const SECRETS = [
	'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s' => '[private key]',
	'/\b(authorization|proxy-authorization)(["\']?\s*[:=]\s*["\']?)(?:(bearer|basic|token|digest)\s+)?[^\s"\',;]+/i' => '$1$2$3 ***',
	'/\b((?:[\w.-]*[_.-])?(?:password|passwd|pwd|pass|secret|token|api[_-]?key|apikey|access[_-]?key|secret[_-]?key|private[_-]?key|client[_-]?secret|session(?:id)?|cookie|credentials?|auth))(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i' => '$1$2***',
	'/\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]{5,}\b/' => '***',
	'/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => '***',
	'/\b(?:ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{20,}\b/' => '***',
	'/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/' => '***',
	'/\bsk-[A-Za-z0-9_-]{20,}\b/' => '***',
	'#(\b[a-z][a-z0-9+.-]*://[^\s:/@]+:)[^\s@/]+@#i' => '$1***@',
	'/[\p{L}\p{N}._%+\'-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}/u' => '<email>',
    ];
    const IPS = '/(?<![\w.:])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\w.])|(?<![\w:])(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}(?![\w:])/i';

    //the masks of a token's variable parts, in order
    const MASKS = [
	'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<uuid>',
	//the times before the IPv6 addresses (12:00:01 is not one)
	'/\d{4}-\d\d-\d\d(?:[T ]\d\d:\d\d(?::\d\d(?:[.,]\d+)?)?(?:Z|[+-]\d\d:?\d\d)?)?/' => '<time>',
	'/(?<![\d:])\d\d:\d\d:\d\d(?:[.,]\d+)?(?![\d:])/' => '<time>',
	'/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?(?![\w.])/' => '<ip>',
	'/(?<![\w:])(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}(?![\w:])/i' => '<ip>',
	'/\b0x[0-9a-f]+\b/i' => '<hex>',
	'/(?<![A-Za-z0-9])(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}(?![A-Za-z0-9])/i' => '<hex>',
	'/(?<![A-Za-z<])[-+]?\d+(?:[.,]\d+)?/' => '<num>',
    ];

    //the text of a line given: a text, or an object with `text` or `message` (as the Log watch trigger and Read a log file give them: { file, text })
    public static function lineOf($line) {
	if (is_string($line) || is_numeric($line))
	    return [ (string)$line, '' ];
	if (is_array($line))
	    return [ (string)($line['text'] ?? $line['message'] ?? $line['line'] ?? ''), (string)($line['file'] ?? '') ];
	return [ '', '' ];
    }

    //a level word as one of LEVELS, or ''
    public static function level($word) {
	$w = strtolower(trim((string)$word));
	if (is_numeric($w)) {
	    //syslog severities 0-7
	    $n = (int)$w;
	    return $n <= 2 ? 'critical' : ($n === 3 ? 'error' : ($n === 4 ? 'warning' : ($n === 5 ? 'notice' : ($n === 6 ? 'info' : 'debug'))));
	}
	return self::LEVEL_WORDS[$w] ?? '';
    }

    //the level named in a message, or ''
    public static function levelIn($text) {
	if (preg_match('/(?<![\w-])(fatal|crit(?:ical)?|emerg(?:ency)?|panic|err(?:or)?|severe|warn(?:ing)?|notice|info|debug|trace)(?![\w-])/i', mb_substr($text, 0, 300), $m))
	    return self::level($m[1]);
	return '';
    }

    //a value of a JSON object by one of $keys (a dotted key: nested, or as it is)
    protected static function jsonValue(array $o, array $keys) {
	foreach($keys as $k) {
	    if (array_key_exists($k, $o) && is_scalar($o[$k]))
		return (string)$o[$k];
	    if (str_contains($k, '.')) {
		$v = $o;
		foreach(explode('.', $k) as $part)
		    $v = is_array($v) && array_key_exists($part, $v) ? $v[$part] : null;
		if (is_scalar($v))
		    return (string)$v;
	    }
	}
	return null;
    }

    //a line parsed: [ message, level, service ]
    public static function parse($text, $format = 'auto', $regex = null) {
	$text = (string)$text;
	if ($format === 'regex' && $regex !== null && preg_match($regex, $text, $m))
	    return [ (string)($m['message'] ?? $text), self::level($m['level'] ?? '') ?: self::levelIn((string)($m['message'] ?? $text)), (string)($m['service'] ?? '') ];
	if ($format === 'json' || ($format === 'auto' && ($text[0] ?? '') === '{')) {
	    $o = json_decode($text, true);
	    if (is_array($o)) {
		$msg = self::jsonValue($o, self::JSON_MESSAGE) ?? $text;
		return [ $msg, self::level(self::jsonValue($o, self::JSON_LEVEL) ?? '') ?: self::levelIn($msg), (string)(self::jsonValue($o, self::JSON_SERVICE) ?? '') ];
	    }
	}
	if (($format === 'access' || $format === 'auto') && preg_match(self::ACCESS, $text, $m)) {
	    $status = (int)$m[4];
	    return [ "{$m[2]} {$m[3]} " . self::KEEP . $m[4], $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), '' ];
	}
	if (($format === 'syslog' || $format === 'auto') && preg_match(self::SYSLOG, $text, $m))
	    return [ $m[3], self::levelIn($m[3]), $m[2] ];
	$msg = (string)preg_replace(self::LEADING_TIME, '', $text);
	return [ $msg, self::levelIn($msg), '' ];
    }

    //the secrets of $text masked (and its IP addresses, $ips)
    public static function redact($text, $ips = false) {
	$text = (string)preg_replace(array_keys(self::SECRETS), array_values(self::SECRETS), (string)$text) ?? '';
	if ($ips)
	    $text = (string)preg_replace(self::IPS, '<ip>', $text);
	return $text;
    }

    //the template's tokens of a message
    public static function tokens($message) {
	$tokens = preg_split('/\s+/', trim((string)$message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
	if (count($tokens) > self::MAX_TOKENS)
	    $tokens = array_merge(array_slice($tokens, 0, self::MAX_TOKENS - 1), [ self::WILD ]);
	foreach($tokens as $i => $t)
	    $tokens[$i] = str_starts_with($t, self::KEEP) ? substr($t, 1) : (string)preg_replace(array_keys(self::MASKS), array_values(self::MASKS), $t);
	return $tokens;
    }

    public static function signature($service, $template) {
	return substr(sha1($service . "\n" . $template), 0, 16);
    }

    //a group's key: its service, token count and first token (one with a mask in it: *)
    protected static function bucket($service, array $tokens) {
	$first = $tokens[0] ?? '';
	return $service . "\n" . count($tokens) . "\n" . (str_contains($first, '<') ? '*' : $first);
    }

    //[ same tokens, wildcard positions ] of $tokens against a template's
    protected static function similarity(array $template, array $tokens) {
	$same = $wild = 0;
	foreach($template as $i => $t) {
	    if ($t === self::WILD)
		$wild++;
	    elseif ($t === $tokens[$i])
		$same++;
	}
	return [ $same, $wild ];
    }

    /*
	the patterns of $lines: $opts
	    format, regex      parse()
	    service            the service of every line ('' : the line's own, or its file's name without .log)
	    known              the known patterns: a list of templates, or of objects { template, signature?, service? }
	    similarity         0.1 .. 1 (default 0.5)
	    redact, ips        redact()
    */
    public static function mine(array $lines, array $opts = []) {
	$format = (string)($opts['format'] ?? 'auto');
	$regex = $opts['regex'] ?? null;
	$fixed = trim((string)($opts['service'] ?? ''));
	$th = max(0.1, min(1.0, (float)($opts['similarity'] ?? 0.5)));
	$redact = $opts['redact'] ?? true;
	$ips = !empty($opts['ips']);
	$lines = array_slice(array_values($lines), 0, self::MAX_LINES);

	//the groups: [ id => [ tokens, service, signature|null (known), count, level, sample ] ], by bucket
	$groups = [];
	$buckets = [];
	foreach((array)($opts['known'] ?? []) as $k) {
	    $tpl = is_array($k) ? (string)($k['template'] ?? '') : (string)$k;
	    $tokens = preg_split('/\s+/', trim($tpl), -1, PREG_SPLIT_NO_EMPTY) ?: [];
	    if (!$tokens || count($groups) >= self::MAX_PATTERNS)
		continue;
	    $service = is_array($k) ? trim((string)($k['service'] ?? '')) : '';
	    $sig = is_array($k) && trim((string)($k['signature'] ?? '')) !== '' ? trim((string)$k['signature']) : self::signature($service, implode(' ', $tokens));
	    $id = count($groups);
	    $groups[$id] = [ $tokens, $service, $sig, 0, '', '' ];
	    $buckets[self::bucket($service, $tokens)][] = $id;
	}
	$known = count($groups);
	$analysed = 0;
	foreach($lines as $line) {
	    [ $text, $file ] = self::lineOf($line);
	    $text = mb_substr($text, 0, self::MAX_LINE);
	    if (trim($text) === '')
		continue;
	    $analysed++;
	    if ($redact)
		$text = self::redact($text, $ips);
	    [ $message, $level, $service ] = self::parse($text, $format, $regex);
	    if ($fixed !== '')
		$service = $fixed;
	    elseif ($service === '' && $file !== '')
		$service = (string)preg_replace('/\.log$/', '', basename($file));
	    $tokens = self::tokens($message);
	    if (!$tokens)
		$tokens = [ '' ];
	    $n = count($tokens);
	    $best = null;
	    $bestScore = -1;
	    $keys = array_unique([ self::bucket($service, $tokens), $service . "\n" . $n . "\n*" ]);
	    foreach($keys as $b) {
		foreach($buckets[$b] ?? [] as $id) {
		    [ $same, $wild ] = self::similarity($groups[$id][0], $tokens);
		    $isKnown = $id < $known;
		    //a known pattern: its wildcards count; a group mined here: the same tokens only (it does not grow into wildcards)
		    $score = ($same + ($isKnown ? $wild : 0)) / $n;
		    if (($same > 0 || $n === $wild) && $score >= $th && ($score > $bestScore || ($score === $bestScore && $isKnown))) {
			$best = $id;
			$bestScore = $score;
		    }
		}
	    }
	    if ($best === null) {
		if (count($groups) >= self::MAX_PATTERNS)
		    continue;
		$best = count($groups);
		$groups[$best] = [ $tokens, $service, null, 0, '', mb_substr($text, 0, self::MAX_SAMPLE) ];
		$buckets[self::bucket($service, $tokens)][] = $best;
	    } elseif ($best >= $known) {
		foreach($groups[$best][0] as $i => $t) {
		    if ($t !== $tokens[$i])
			$groups[$best][0][$i] = self::WILD;
		}
	    }
	    $g = &$groups[$best];
	    $g[3]++;
	    if ($g[5] === '')
		$g[5] = mb_substr($text, 0, self::MAX_SAMPLE);
	    if ($level !== '' && ($g[4] === '' || self::LEVELS[$level] > self::LEVELS[$g[4]]))
		$g[4] = $level;
	    unset($g);
	}

	$patterns = [];
	foreach($groups as $id => [ $tokens, $service, $sig, $count, $level, $sample ]) {
	    if ($count === 0)
		continue;
	    $template = implode(' ', $tokens);
	    $patterns[] = [ 'signature' => $sig ?? self::signature($service, $template), 'template' => $template, 'service' => $service, 'level' => $level, 'count' => $count,
		'sample' => $sample, 'known' => $id < $known ];
	}
	usort($patterns, fn($a, $b) => $b['count'] <=> $a['count']);
	return [ 'patterns' => $patterns, 'count' => count($patterns), 'lines' => $analysed, 'newPatterns' => count(array_filter($patterns, fn($p) => !$p['known'])) ];
    }
}
