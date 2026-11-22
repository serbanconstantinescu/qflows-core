<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: a PDF of a design of the PDF designer (models\PdfDesign), made with tc-lib-pdf (app/pkgs/tc-lib-pdf, installed by scripts/tc-lib-pdf.sh,
    loaded only here); from the template service of the stand-alone page designer (tc-lib-pdf's quirks handled the same way)

    render($design, $vars, $options)   [ pdf (the bytes), pages ]; $design normalized (PdfDesign::normalize), $vars the variables of the expressions;
	options: aid, project (whose Files the images are; none: no images), title, mode ('' or a PDF/A mode: MODES)
	\RuntimeException for what is wrong with the design or the values (a repeating row's value not a list, too many rows, a missing image), \ErrorException for
	a failing expression
    fill($html, $vars)                 the HTML with its repeating rows, conditions and {{ }} done (values HTML-escaped; a value's own {{ }} stays text)
    rows($html)                        its repeating rows (the outermost): [ [ start, end, each, as ] ]
    withoutMarkers($html)              without {{#if …}}, {{else}}, {{/if}} (for the checks of the other expressions)

    Layout: the body flows over as many pages as it needs (addHTMLCell adds them), then the header and the footer are drawn on each page, filled once ({PAGE} / {PAGES}
    per page); in the body, {PAGE} / {PAGES} are tc-lib-pdf's aliases, filled in when the PDF is written.
*/
namespace util;

class PdfDocument {
    const AUTOLOAD = BASE . '/app/pkgs/tc-lib-pdf/autoload.php';
    //PDF/A modes of tc-lib-pdf (B: visual appearance)
    const MODES = [ '' => 'PDF 1.7', 'pdfa1b' => 'PDF/A-1b', 'pdfa2b' => 'PDF/A-2b', 'pdfa3b' => 'PDF/A-3b' ];
    //line height of HTML text as a multiple of the font size (TCPDF's classic 1.25; the designer shows the same)
    const LINE_HEIGHT = 1.25;
    //tc-lib-pdf's page-number aliases, replaced when the PDF is written
    const ALIAS_PAGE = '~#PN';
    const ALIAS_PAGES = '~#PT';
    //at most, in a document: rows made by the repeating rows, bytes of HTML once filled, bytes of one image
    const MAX_ROWS = 20000;
    const MAX_HTML = 8 << 20;
    const MAX_IMAGE = 10 << 20;
    const IF_RE = '/\{\{#if\s+((?:(?!\}\}).)*)\}\}((?:(?!\{\{#if\s).)*?)(?:\{\{else\s*\}\}((?:(?!\{\{#if\s).)*?))?\{\{\/if\s*\}\}/s';

    private $vars;
    private $options;
    private $rowCount = 0;
    private $images = [];

    public static function available() {
	return is_file(self::AUTOLOAD);
    }

    public static function render(array $design, array $vars, array $options = []) {
	if (!self::available())
	    throw new \RuntimeException('tc-lib-pdf is not installed: run scripts/tc-lib-pdf.sh install');
	require_once self::AUTOLOAD;
	$r = new self();
	$r->options = $options + [ 'aid' => null, 'project' => null, 'title' => '', 'mode' => '' ];
	return $r->build($design, $vars);
    }

    public static function fill($html, array $vars) {
	$r = new self();
	return $r->fillHtml($html, $vars);
    }

    private function fillHtml($html, array $vars) {
	//1. repeating rows: each filled with its item (its own conditions and {{ }}); a token in their place until the rest is filled, so a value is never filled again
	$done = [];
	$out = '';
	$at = 0;
	foreach(self::rows($html) as $row) {
	    $list = \util\Expression::render(\sys\J\Template::OPEN . $row['each'] . \sys\J\Template::CLOSE, $vars);
	    if ($list === null || $list === '')
		$list = [];
	    if (!is_array($list) || !array_is_list($list))
		throw new \RuntimeException("repeating row `" . mb_substr($row['each'], 0, 60) . "`: not a list (" . get_debug_type($list) . ')');
	    $this->rowCount += count($list);
	    if ($this->rowCount > self::MAX_ROWS)
		throw new \RuntimeException('more than ' . self::MAX_ROWS . ' repeated rows');
	    //the row without its data-each / data-as
	    $tr = substr($html, $row['start'], $row['end'] - $row['start']);
	    $tr = preg_replace('/^(<tr\b[^>]*?)\s+data-each\s*=\s*"[^"]*"/i', '$1', $tr);
	    $tr = preg_replace('/^(<tr\b[^>]*?)\s+data-as\s*=\s*"[^"]*"/i', '$1', $tr);
	    $filled = '';
	    foreach($list as $i => $item)
		$filled .= $this->fillHtml($tr, [ $row['as'] => $item, 'loop' => [ 'index' => $i, 'number' => $i + 1, 'first' => $i === 0, 'last' => $i === count($list) - 1 ] ] + $vars);
	    $token = "\x02" . count($done) . "\x03";
	    $done[$token] = $filled;
	    $out .= substr($html, $at, $row['start'] - $at) . $token;
	    $at = $row['end'];
	}
	$html = $out . substr($html, $at);

	//2. conditions, innermost first
	do {
	    $html = preg_replace_callback(self::IF_RE, fn($m) => self::truthy(\util\Expression::render(\sys\J\Template::OPEN
		. html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . \sys\J\Template::CLOSE, $vars)) ? $m[2] : ($m[3] ?? ''), $html, -1, $count);
	} while ($count > 0);

	//3. the {{ }}, then the rows
	$html = strtr(\util\EmailHtml::fill($html, $vars), $done);
	if (strlen($html) > self::MAX_HTML)
	    throw new \RuntimeException('the document is larger than ' . (self::MAX_HTML >> 20) . ' MB of HTML once filled');
	return $html;
    }

    //a condition's value: not null, false, 0, '' or an empty list
    private static function truthy($v) {
	if (is_array($v))
	    return $v !== [];
	return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0);
    }

    public static function rows($html) {
	$rows = [];
	$offset = 0;
	while (preg_match('/<tr\b[^>]*\bdata-each\s*=\s*"([^"]*)"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
	    $start = $m[0][1];
	    $as = preg_match('/\bdata-as\s*=\s*"([^"]*)"/i', $m[0][0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'item';
	    //its </tr>: rows of tables inside its cells counted
	    $depth = 1;
	    $pos = $start + strlen($m[0][0]);
	    while ($depth > 0 && preg_match('~<tr\b|</tr\s*>~i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
		$depth += $t[0][0][1] === '/' ? -1 : 1;
		$pos = $t[0][1] + strlen($t[0][0]);
	    }
	    $end = $depth === 0 ? $pos : strlen($html);
	    $rows[] = [ 'start' => $start, 'end' => $end, 'each' => trim(html_entity_decode($m[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'as' => trim($as) ];
	    $offset = $end;
	}
	return $rows;
    }

    public static function withoutMarkers($html) {
	return preg_replace([ '/\{\{#if\s+(?:(?!\}\}).)*\}\}/s', '/\{\{else\s*\}\}/', '/\{\{\/if\s*\}\}/' ], '', $html);
    }

    //the PDF of a normalized design: [ pdf, pages ]
    private function build(array $design, array $vars) {
	$page = $design['page'];
	$m = $page['margins'];
	$sections = \models\PdfDesign::sections($design);
	//filled once (the header / footer get the page numbers per page)
	foreach($sections as $k => $html)
	    $sections[$k] = $html === '' ? '' : $this->finish($this->fillHtml($html, $vars));

	$pdf = new \Com\Tecnick\Pdf\Tcpdf('pt', true, true, true, (string)$this->options['mode']);
	$pdf->setCreator((string)\V::app()->config('app.name'));
	$pdf->setTitle((string)$this->options['title']);
	$font = $pdf->font->insert($pdf->pon, $page['font'], '', $page['size']);
	$first = $pdf->addPage([
	    'format' => $page['format'],
	    'orientation' => $page['orientation'],
	    'margin' => [ 'PL' => $m['left'], 'PR' => $m['right'], 'PT' => 0.0, 'HB' => 0.0, 'CT' => $m['top'], 'CB' => $m['bottom'], 'FT' => 0.0, 'PB' => 0.0 ],
	    'autobreak' => true,
	]);
	$width = max(0.0, (float)$first['width'] - $m['left'] - $m['right']);

	//the body: addHTMLCell() adds the pages it needs
	$pdf->page->addContent($font['out']);
	$body = strtr($sections['body'], [ '{PAGE}' => self::ALIAS_PAGE, '{PAGES}' => self::ALIAS_PAGES ]);
	if (trim($body) !== '')
	    $pdf->addHTMLCell($this->wrap($body), $m['left'], $m['top'], $width, 0, \Com\Tecnick\Pdf\Tcpdf::ZEROCELL);

	//the header and the footer, once the page count is known
	$pages = $pdf->page->getPages();
	if ($sections['header'] !== '' || $sections['footer'] !== '') {
	    $total = count($pages);
	    foreach(array_keys($pages) as $pid) {
		$p = $pdf->setCurrentPage((int)$pid);
		$numbers = [ '{PAGE}' => (string)($pid + 1), '{PAGES}' => (string)$total ];
		$height = (float)$p['height'];
		$pageWidth = max(0.0, (float)$p['width'] - $m['left'] - $m['right']);
		foreach([ [ $sections['header'], $m['header'] ], [ $sections['footer'], $height - $m['footer'] ] ] as [ $html, $y ]) {
		    if ($html === '')
			continue;
		    //a cell as tall as the rest of the page: getHTMLCell() never breaks the page
		    $out = $pdf->graph->getStartTransform() . $font['out']
			. $pdf->getHTMLCell($this->wrap(strtr($html, $numbers)), $m['left'], $y, $pageWidth, max(0.0, $height - $y), \Com\Tecnick\Pdf\Tcpdf::ZEROCELL)
			. $pdf->graph->getStopTransform();
		    $pdf->page->addContent($out, (int)$pid);
		}
	    }
	}
	return [ 'pdf' => $pdf->getOutPDFString(), 'pages' => count($pages) ];
    }

    //the base line height (the div is left open: the parser closes it at the end)
    private function wrap($html) {
	return '<div style="line-height:' . self::LINE_HEIGHT . '">' . $html;
    }

    //filled HTML as tc-lib-pdf prints what the designer shows: alignment, quotes, backgrounds, the images embedded
    private function finish($html) {
	//alignment: the align attribute wins over CSS text-align in the PDF, browsers do it the other way round; text-align wins and becomes align
	$html = preg_replace_callback('/<(p|div|h[1-6]|li|td|th|blockquote|pre|ol|ul|table)\b([^>]*)>/i', static function(array $m) {
	    $attrs = $m[2];
	    if (!preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $attrs, $s)
		|| !preg_match('/(?:^|;)\s*text-align\s*:\s*(left|right|center|justify|start|end)\s*(?=;|$)/i', $s[1], $a))
		return $m[0];
	    $align = match(strtolower($a[1])) { 'start' => 'left', 'end' => 'right', default => strtolower($a[1]) };
	    $style = trim((string)preg_replace('/(?:^|;)\s*text-align\s*:[^;]*/i', '', $s[1]), ' ;');
	    $attrs = str_replace($s[0], $style !== '' ? 'style="' . $style . '"' : '', $attrs);
	    $attrs = (string)preg_replace('/\salign\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs);
	    return '<' . $m[1] . rtrim($attrs) . ' align="' . $align . '">';
	}, $html);
	//<blockquote> is not indented in the PDF: an indented block
	$html = (string)preg_replace('#<blockquote\b[^>]*>#i', '<div style="margin-left:28pt">', $html);
	$html = (string)preg_replace('#</blockquote\s*>#i', '</div>', $html);
	$html = self::spreadBackgrounds($html);
	//the images: files of the project, as data URIs (nothing is fetched by tc-lib-pdf)
	return (string)preg_replace_callback('/<img\b[^>]*>/i', function(array $m) {
	    if (!preg_match('~\bsrc="/file/content/([0-9a-f]{24})"~i', $m[0], $s))
		return '';
	    return str_replace($s[0], 'src="' . $this->image($s[1]) . '"', $m[0]);
	}, $html);
    }

    //project file $id (an image) as a data URI
    private function image($id) {
	if (isset($this->images[$id]))
	    return $this->images[$id];
	$file = $this->options['aid'] ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->options['aid'], 'metadata.project' => $this->options['project'] ]) : null;
	if (!$file)
	    throw new \RuntimeException("image `{$id}`: not a file of the project");
	$mime = $file->imageMimeType();
	if (!in_array($mime, \models\PdfDesign::IMAGE_TYPES, true))
	    throw new \RuntimeException("image `{$file->filename}`: not PNG, JPEG or GIF");
	if ((int)$file->length > self::MAX_IMAGE)
	    throw new \RuntimeException("image `{$file->filename}`: larger than " . (self::MAX_IMAGE >> 20) . ' MB');
	return $this->images[$id] = "data:{$mime};base64," . base64_encode((string)$file->getContents());
    }

    /*
     * tc-lib-pdf paints an inline background only on text directly inside the styled element, so <span style="background-color:…">a <b>b</b></span> would lose it
     * on "b": the element is split into sibling pieces that each carry the background, with the formatting of a child (b, i, u, s, span, font) moved into the
     * piece's style. Only one level deep.
     */
    private static function spreadBackgrounds($html) {
	$css = [ 'b' => 'font-weight:bold', 'strong' => 'font-weight:bold', 'i' => 'font-style:italic', 'em' => 'font-style:italic', 'u' => 'text-decoration:underline',
	    's' => 'text-decoration:line-through', 'del' => 'text-decoration:line-through', 'strike' => 'text-decoration:line-through', 'span' => '', 'font' => '' ];
	$tags = implode('|', array_keys($css));
	$result = preg_replace_callback('/<(a|span|font)(\s[^>]*?)?\s+style="([^"]*background-color[^"]*)"([^>]*)>(.*?)<\/\1>/is', static function(array $m) use ($css, $tags) {
	    [ $whole, $tag ] = [ $m[0], strtolower($m[1]) ];
	    if (!str_contains($m[5], '<'))
		return $whole;
	    $attrs = trim(($m[2] ?? '') . ($m[4] ?? ''));
	    $piece = static fn($style, $text) => '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . ' style="' . trim($style, '; ') . '">' . $text . '</' . $tag . '>';
	    $style = rtrim($m[3], '; ');
	    $tokens = preg_split("/(<(?:$tags)\\b[^>]*>[^<]*<\\/(?:$tags)>)/i", $m[5], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
	    $out = '';
	    foreach($tokens ?: [] as $token) {
		if (preg_match("/^<($tags)\\b([^>]*)>([^<]*)<\\/\\1>$/i", $token, $c)) {
		    $own = preg_match('/\\bstyle\\s*=\\s*"([^"]*)"/i', $c[2], $st) ? $st[1] : '';
		    if (strtolower($c[1]) === 'font' && preg_match('/\\bcolor\\s*=\\s*"([^"]*)"/i', $c[2], $fc))
			$own .= ';color:' . $fc[1];
		    $out .= $piece($style . ';' . $css[strtolower($c[1])] . ';' . $own, $c[3]);
		} elseif (str_contains($token, '<')) {
		    //deeper nesting, images, line breaks: left as it is
		    return $whole;
		} else {
		    $out .= $piece($style, $token);
		}
	    }
	    return $out;
	}, $html);
	return $result ?? $html;
    }
}
