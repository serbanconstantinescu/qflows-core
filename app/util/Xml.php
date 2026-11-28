<?php

/*
    CLD: 2026-11-28T16:00:00+02:00: XML as PHP arrays (for JSON): Xml::toArray($xml, $options) gives [ <root name> => <its value> ]; used by the XML to JSON step (NodeAppXmlJson) and the
	clients reading XML answers (S3, SAP, Sophos, Namecheap, BNR, ANAF).
	An element: its text when it has no attributes and no child elements ('' when empty), else an object: the attributes as <prefix><name>, the child elements by name
	(one that comes again: a list of them, in their order), its own text (when not only whitespace) as <textKey>. CDATA is text; comments and
	processing instructions left out; namespace declarations (xmlns) not kept.
	$options: attributes (true; false: left out), attributePrefix ('@'; '' allowed: attributes and children share the keys), textKey ('#text'), lists (names of
	elements always as a list, even one), trim (true: texts trimmed; false: kept as they are, e.g. S3 keys), types (numbers and true / false as such; numbers with a leading 0, e.g. 007, stay texts), stripNamespaces (element /
	attribute names without their ns: prefix).
	Reading an answer (an element of toArray with the default keys): Xml::children($el, $name) the elements $name always as a list, Xml::text($el) its text,
	Xml::attributes($el) its attributes without the @.
	Safe: no network (LIBXML_NONET), no external entities, documents declaring entities (<!ENTITY) refused; at most MAX_BYTES. \RuntimeException when the XML is not valid.
*/

namespace util;

class Xml {
    const MAX_BYTES = 20 * 1024 * 1024;
    const NUMBER = '/^-?(0|[1-9]\d{0,14})(\.\d+)?([eE][+-]?\d+)?$/';

    public static function toArray($xml, array $options = []) {
	$xml = (string)$xml;
	if (trim($xml) === '')
	    throw new \RuntimeException('no XML (empty)');
	if (strlen($xml) > self::MAX_BYTES)
	    throw new \RuntimeException('the XML is larger than ' . (self::MAX_BYTES / 1048576) . ' MB');
	if (stripos($xml, '<!ENTITY') !== false)
	    throw new \RuntimeException('entity declarations (<!ENTITY ...>) are not supported');
	$o = [
	    'attributes' => (bool)($options['attributes'] ?? true),
	    'attributePrefix' => (string)($options['attributePrefix'] ?? '@'),
	    'textKey' => (string)($options['textKey'] ?? '#text'),
	    'lists' => array_fill_keys(array_map('strval', (array)($options['lists'] ?? [])), true),
	    'trim' => (bool)($options['trim'] ?? true),
	    'types' => !empty($options['types']),
	    'stripNamespaces' => !empty($options['stripNamespaces']),
	];
	$prev = libxml_use_internal_errors(true);
	libxml_clear_errors();
	$doc = new \DOMDocument();
	$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
	$error = libxml_get_errors()[0] ?? null;
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if (!$ok || !$doc->documentElement)
	    throw new \RuntimeException('not valid XML' . ($error ? ': ' . trim($error->message) . " (line {$error->line})" : ''));
	$root = $doc->documentElement;
	return [ self::nameOf($root, $o) => self::element($root, $o) ];
    }

    //the elements $name of element $el (of toArray, the default keys): always a list (one: [ it ], none or no $el: [])
    public static function children($el, $name) {
	$v = is_array($el) ? ($el[$name] ?? null) : null;
	return $v === null ? [] : (is_array($v) && array_is_list($v) ? $v : [ $v ]);
    }

    //the text of element $el: itself when it is a text, else its #text ('' when none)
    public static function text($el) {
	return is_array($el) ? (string)($el['#text'] ?? '') : (string)$el;
    }

    //the attributes of element $el, without their @: [ name => value ]
    public static function attributes($el) {
	$a = [];
	foreach(is_array($el) && !array_is_list($el) ? $el : [] as $k => $v) {
	    if (is_string($k) && $k !== '' && $k[0] === '@')
		$a[substr($k, 1)] = $v;
	}
	return $a;
    }

    private static function element(\DOMElement $el, array $o) {
	$value = [];
	foreach($o['attributes'] ? $el->attributes : [] as $a)
	    $value[$o['attributePrefix'] . self::nameOf($a, $o)] = self::scalar($a->value, $o);
	$lists = [];
	$text = '';
	foreach($el->childNodes as $c) {
	    if ($c instanceof \DOMElement) {
		$k = self::nameOf($c, $o);
		$v = self::element($c, $o);
		if (isset($lists[$k]))
		    $value[$k][] = $v;
		elseif (array_key_exists($k, $value) || isset($o['lists'][$k])) {
		    //the second one (or an element always listed): a list from now on
		    $value[$k] = array_key_exists($k, $value) ? [ $value[$k], $v ] : [ $v ];
		    $lists[$k] = true;
		} else
		    $value[$k] = $v;
	    } elseif ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE)
		$text .= $c->nodeValue;
	}
	$kept = $o['trim'] ? trim($text) : $text;
	if (!$value)
	    return self::scalar($kept, $o);
	if (trim($text) !== '')
	    $value[$o['textKey']] = self::scalar($kept, $o);
	return $value;
    }

    private static function nameOf(\DOMNode $n, array $o) {
	return $o['stripNamespaces'] && $n->localName !== null ? $n->localName : $n->nodeName;
    }

    private static function scalar($s, array $o) {
	if (!$o['types'])
	    return $s;
	if ($s === 'true' || $s === 'false')
	    return $s === 'true';
	if (preg_match(self::NUMBER, $s, $m))
	    return ($m[2] ?? '') !== '' || ($m[3] ?? '') !== '' ? (float)$s : (int)$s;
	return $s;
    }
}
