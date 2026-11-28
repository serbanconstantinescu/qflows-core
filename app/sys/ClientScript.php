<?php

namespace sys;

class ClientScript {

    // JavaScript code blocks
    public $scripts = [];

    // JavaScript files
    public $scriptFiles = [];


    public function addPageScript($script, $options = []) {
	$this->scripts[] = [ 'script' => $script, 'options' => $options ];
    }

    public function getPageScripts() {
	return $this->scripts;
    }

    public function renderPageScripts() {
	if (empty($this->scripts))
	    return null;

	$out = [];
	foreach($this->scripts as $desc) {
	    $type = $desc['options']['type'] ?? 'text/javascript';
	    $out[] = "<script type='$type'>/*<![CDATA[*/{$desc['script']}/*]]>*/</script>";
	}
	return implode("\n", $out);
    }

    public function addPageScriptFile($scriptUrl, $options = [], $prepend = false) {
	if ($prepend)
	    array_unshift($this->scriptFiles, [ 'url' => $scriptUrl, 'options' => $options ]);
	else 
	    $this->scriptFiles[] = [ 'url' => $scriptUrl, 'options' => $options ];
    }

    public function renderPageScriptFiles() {
	if (empty($this->scriptFiles))
	    return null;

	$out = [];
	foreach($this->scriptFiles as $desc) {
	    $out[] = \V::app()->html->script($desc['url'], $desc['options']);
	}

	return implode("\n", $out);
    }
}