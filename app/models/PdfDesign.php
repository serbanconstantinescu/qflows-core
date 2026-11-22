<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: a PDF document designed with the PDF designer (app.PdfDesigner), stored as JSON in the config of the PDF document step (NodeAppPdf);
    util\PdfDocument turns it into a PDF with tc-lib-pdf (app/pkgs/tc-lib-pdf, scripts/tc-lib-pdf.sh)

    {
	page: { format: A4 | A5 | LETTER | LEGAL, orientation: P | L, margins: { top, bottom, left, right, header, footer }, font, size, indent, showHeader, showFooter },
	header, body, footer,
	sample
    }

    margins   in pt (1/72 in): top = where the body starts, bottom = where it stops, header = from the top edge, footer = from the bottom edge
    font      FONTS (built by scripts/tc-lib-pdf.sh), size the base size in pt; indent the step of Tab (pt)
    header / body / footer   HTML in the tags / attributes / styles tc-lib-pdf prints (TAGS, ATTRS, STYLES), with
	{{ expression }}                       its value, HTML-escaped ({{ html(x) }}, {{ x.html }}, {{ markdown2html(x) }}: as HTML; util\EmailHtml::fill)
	{{#if expression}} … {{else}} … {{/if}} shown when the expression's value is not empty (null, false, 0, '', [])
	<tr data-each="expression" data-as="item">   the row once per item of the list (in it {{ item }}, {{ loop.index }} from 0, {{ loop.number }} from 1)
	{PAGE} {PAGES}                         the page number, the page count
	<img src="/file/content/<id>">         an image of the project's Files (PNG, JPEG, GIF), embedded; width / height in pt
	the body flows over as many pages as it needs, the header and the footer are printed on every page (unless showHeader / showFooter are false)
    sample    JSON text: an object of variables for the designer's preview only (as the step's variables)

    schema($field)        the config field (the designer is the smartform field type `pdfdesigner`)
    normalize($design)    as stored: known properties, numbers within bounds, the HTML cleaned (clean())
    problems($design, $wf) what is wrong with it: [ message ] (expressions, conditions, repeating rows, images)
*/
namespace models;

class PdfDesign {
    //page sizes in pt (portrait)
    const FORMATS = [ 'A4' => [ 595.28, 841.89 ], 'A5' => [ 419.53, 595.28 ], 'LETTER' => [ 612.0, 792.0 ], 'LEGAL' => [ 612.0, 1008.0 ] ];
    //the fonts of scripts/tc-lib-pdf.sh (tc-font-mirror: dejavu, freefont, core); core fonts: Western European text only (cp1252: no ș ț)
    const FONTS = [
	'dejavusans' => 'DejaVu Sans',
	'dejavuserif' => 'DejaVu Serif',
	'dejavusansmono' => 'DejaVu Sans Mono',
	'dejavusanscondensed' => 'DejaVu Sans Condensed',
	'freesans' => 'FreeSans',
	'freeserif' => 'FreeSerif',
	'freemono' => 'FreeMono',
	'helvetica' => 'Helvetica (core)',
	'times' => 'Times (core)',
	'courier' => 'Courier (core)',
    ];
    const CORE_FONTS = [ 'helvetica', 'times', 'courier' ];
    const SECTIONS = [ 'header', 'body', 'footer' ];
    const MAX_HTML = 300000;
    const MAX_SAMPLE = 200000;
    const IMAGE_TYPES = [ 'image/png', 'image/jpeg', 'image/gif' ];
    //an image's src: a file of the project's Files
    const IMAGE_SRC = '~^/file/content/([0-9a-f]{24})$~';

    //tags kept => their own attributes (with ATTRS); others are unwrapped (their text stays), DROP removed with their content
    const TAGS = [ 'a', 'b', 'blockquote', 'br', 'dd', 'del', 'div', 'dl', 'dt', 'em', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre',
	'small', 'span', 'strong', 'sub', 'sup', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'tt', 'u', 'ul', 's', 'strike' ];
    const DROP = [ 'script', 'style', 'iframe', 'object', 'embed', 'meta', 'link', 'input', 'button', 'select', 'textarea', 'svg', 'video', 'audio', 'canvas', 'form',
	'noscript', 'head', 'title', 'template' ];
    const ATTRS = [ 'align', 'valign', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'width', 'height', 'bgcolor', 'color', 'face', 'size', 'src', 'href',
	'style', 'alt', 'nobr' ];
    //the repeating row's attributes (only on tr)
    const ROW_ATTRS = [ 'data-each', 'data-as' ];
    const STYLES = [ 'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'width', 'height', 'line-height',
	'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'vertical-align', 'text-transform', 'letter-spacing', 'margin-left', 'text-indent', 'padding',
	'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ];

    const DEFAULT_PAGE = [ 'format' => 'A4', 'orientation' => 'P',
	//34, 24, 16, 16, 10 and 18 mm
	'margins' => [ 'top' => 96.38, 'bottom' => 68.03, 'left' => 45.35, 'right' => 45.35, 'header' => 28.35, 'footer' => 51.02 ],
	'font' => 'dejavusans', 'size' => 10.0, 'indent' => 28.35, 'showHeader' => true, 'showFooter' => true ];

    //the config field holding the document (the designer edits it)
    public static function schema(array $field = []) {
	return array_merge([
	    'key' => 'design',
	    'type' => 'array',
	    'label' => 'Document',
	    'required' => true,
	    'default' => [ 'page' => self::DEFAULT_PAGE, 'header' => '', 'body' => '', 'footer' => '', 'sample' => '' ],
	    'ui' => [ 'type' => 'pdfdesigner' ],
	], $field);
    }

    public static function normalize($design) {
	$d = json_decode(\util\JSON::encode($design), true);
	$d = is_array($d) ? $d : [];
	$p = is_array($d['page'] ?? null) ? $d['page'] : [];
	$m = is_array($p['margins'] ?? null) ? $p['margins'] : [];
	$def = self::DEFAULT_PAGE;
	$len = fn($v, $dflt, $max) => is_numeric($v) ? round(max(0.0, min($max, (float)$v)), 2) : $dflt;
	$format = strtoupper((string)($p['format'] ?? ''));
	$out = [ 'page' => [
	    'format' => isset(self::FORMATS[$format]) ? $format : $def['format'],
	    'orientation' => strtoupper((string)($p['orientation'] ?? '')) === 'L' ? 'L' : 'P',
	    'margins' => [],
	    'font' => isset(self::FONTS[$p['font'] ?? '']) ? $p['font'] : $def['font'],
	    'size' => is_numeric($p['size'] ?? null) ? round(max(4.0, min(72.0, (float)$p['size'])), 1) : $def['size'],
	    'indent' => $len($p['indent'] ?? null, $def['indent'], 150),
	    'showHeader' => ($p['showHeader'] ?? true) !== false,
	    'showFooter' => ($p['showFooter'] ?? true) !== false,
	] ];
	foreach($def['margins'] as $k => $v)
	    $out['page']['margins'][$k] = $len($m[$k] ?? null, $v, 500);
	foreach(self::SECTIONS as $s)
	    $out[$s] = self::clean(mb_substr(is_string($d[$s] ?? null) ? $d[$s] : '', 0, self::MAX_HTML));
	$out['sample'] = mb_substr(is_string($d['sample'] ?? null) ? $d['sample'] : '', 0, self::MAX_SAMPLE);
	return $out;
    }

    //the page's width and height (pt) of a normalized design
    public static function size(array $design) {
	[ $w, $h ] = self::FORMATS[$design['page']['format']];
	return $design['page']['orientation'] === 'L' ? [ $h, $w ] : [ $w, $h ];
    }

    //the sections printed: [ name => html ] (a header / footer switched off is not)
    public static function sections(array $design) {
	return [ 'header' => $design['page']['showHeader'] ? $design['header'] : '', 'body' => $design['body'], 'footer' => $design['page']['showFooter'] ? $design['footer'] : '' ];
    }

    //the sample variables (an object), or null with a message
    public static function sample(array $design) {
	$text = trim($design['sample']);
	if ($text === '')
	    return [ [], null ];
	$v = json_decode($text, true);
	if (!is_array($v) || array_is_list($v) && $v !== [])
	    return [ null, json_last_error() !== JSON_ERROR_NONE ? 'Sample data is not JSON: ' . json_last_error_msg() : 'Sample data must be a JSON object' ];
	return [ $v, null ];
    }

    //what is wrong with normalized design $design (in workflow $wf: its images files of the workflow's project): [ message ]
    public static function problems(array $design, $wf = null) {
	$problems = [];
	[ $w, $h ] = self::size($design);
	$m = $design['page']['margins'];
	if ($w - $m['left'] - $m['right'] < 36)
	    $problems[] = 'the left and right margins leave no room for the content';
	if ($h - $m['top'] - $m['bottom'] < 36)
	    $problems[] = 'the top and bottom margins leave no room for the body';
	if (trim(strip_tags($design['body'], '<img><table><hr>')) === '')
	    $problems[] = 'the body is empty';
	foreach(self::sections($design) as $name => $html) {
	    if ($html === '')
		continue;
	    foreach(self::sectionProblems($html) as $p)
		$problems[] = "{$name}: {$p}";
	    foreach(self::imageIds($html) as $id) {
		$file = $wf ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $wf->aid, 'metadata.project' => $wf->project ]) : null;
		if ($wf && !$file)
		    $problems[] = "{$name}: an image is not a file of this project";
		elseif ($file && !in_array($file->imageMimeType(), self::IMAGE_TYPES, true))
		    $problems[] = "{$name}: use PNG, JPEG or GIF images";
	    }
	}
	[ , $error ] = self::sample($design);
	if ($error !== null)
	    $problems[] = $error;
	return $problems;
    }

    //the expressions, conditions and repeating rows of one section's HTML
    private static function sectionProblems($html) {
	$problems = [];
	$check = function($code, $what) use (&$problems) {
	    foreach(\util\Expression::check(\sys\J\Template::OPEN . $code . \sys\J\Template::CLOSE) as $p)
		if ($p['severity'] === 'error')
		    $problems[] = "{$what} `" . mb_substr(trim($code), 0, 60) . "`: {$p['message']}";
	};
	foreach(\util\PdfDocument::rows($html) as $row) {
	    if (trim($row['each']) === '')
		$problems[] = 'a repeating row has no list';
	    else
		$check($row['each'], 'repeating row');
	    if (!\util\Validator::isIdentifier($row['as']))
		$problems[] = 'a repeating row\'s item name must be letters, digits or underscore, not starting with a digit';
	}
	$opens = preg_match_all('/\{\{#if\s/', $html);
	$closes = preg_match_all('/\{\{\/if\s*\}\}/', $html);
	if ($opens !== $closes)
	    $problems[] = "the conditions do not match: {$opens} {{#if …}} and {$closes} {{/if}}";
	preg_match_all('/\{\{#if\s+(.*?)\}\}/s', $html, $conds);
	foreach($conds[1] as $c) {
	    if (trim($c) === '')
		$problems[] = 'a condition is empty';
	    else
		$check(html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'condition');
	}
	$rest = \util\PdfDocument::withoutMarkers($html);
	foreach(\sys\J\Template::parts($rest) as $p) {
	    if (!$p['closed']) {
		$problems[] = 'a {{ is not closed';
		break;
	    }
	    $check(html_entity_decode(substr($rest, $p['start'], $p['end'] - $p['start']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'expression');
	}
	return $problems;
    }

    //the ids of the project files of the images in $html
    public static function imageIds($html) {
	preg_match_all('~<img\b[^>]*\bsrc="/file/content/([0-9a-f]{24})"~i', $html, $m);
	return array_values(array_unique($m[1]));
    }

    //$html reduced to TAGS / ATTRS / STYLES; images only of the Files, links only http(s), mailto or an expression; the text (and {{ }}) kept
    public static function clean($html) {
	if (trim($html) === '')
	    return '';
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
	libxml_clear_errors();
	$root = $doc->getElementById('__root');
	if (!$root)
	    return '';
	self::cleanChildren($root);
	$out = '';
	foreach($root->childNodes as $c)
	    $out .= $doc->saveHTML($c);
	//an expression in a link (href="{{ ... }}") comes back URL-encoded from saveHTML: restored
	$out = preg_replace_callback('/%7B%7B(.*?)%7D%7D/i', fn($m) => '{{' . rawurldecode($m[1]) . '}}', $out);
	return trim($out);
    }

    private static function cleanChildren(\DOMNode $node) {
	foreach(iterator_to_array($node->childNodes) as $c) {
	    if ($c instanceof \DOMText)
		continue;
	    if (!($c instanceof \DOMElement)) {
		$node->removeChild($c);
		continue;
	    }
	    $tag = strtolower($c->tagName);
	    if (in_array($tag, self::DROP, true)) {
		$node->removeChild($c);
		continue;
	    }
	    self::cleanChildren($c);
	    if (!in_array($tag, self::TAGS, true)) {
		while ($c->firstChild)
		    $node->insertBefore($c->firstChild, $c);
		$node->removeChild($c);
		continue;
	    }
	    foreach(iterator_to_array($c->attributes) as $a) {
		$name = strtolower($a->name);
		$value = trim($a->value);
		if ($tag === 'tr' && in_array($name, self::ROW_ATTRS, true)) {
		    $c->setAttribute($name, mb_substr($value, 0, 2000));
		} elseif (!in_array($name, self::ATTRS, true)) {
		    $c->removeAttribute($a->name);
		} elseif ($name === 'style') {
		    $kept = [];
		    foreach(explode(';', $a->value) as $decl) {
			[ $prop, $val ] = array_map('trim', explode(':', $decl, 2) + [ 1 => '' ]);
			$prop = strtolower($prop);
			if ($prop === 'font-family' && !isset(self::FONTS[strtolower(trim($val, " \"'"))]))
			    continue;
			if (in_array($prop, self::STYLES, true) && $val !== '' && !preg_match('/url\s*\(|expression\s*\(|[<>"\\\\]/i', $val))
			    $kept[] = $prop . ':' . $val;
		    }
		    if ($kept)
			$c->setAttribute('style', implode(';', $kept));
		    else
			$c->removeAttribute('style');
		} elseif ($name === 'face') {
		    if (!isset(self::FONTS[strtolower($value)]))
			$c->removeAttribute('face');
		} elseif ($name === 'href') {
		    if (!preg_match('~^(https?://|mailto:)~i', $value) && !str_starts_with($value, \sys\J\Template::OPEN))
			$c->removeAttribute('href');
		} elseif ($name === 'src') {
		    if ($tag !== 'img' || !preg_match(self::IMAGE_SRC, $value))
			$c->removeAttribute('src');
		}
	    }
	    //an image without a file of the Files is dropped
	    if ($tag === 'img' && !$c->hasAttribute('src'))
		$node->removeChild($c);
	}
    }
}
