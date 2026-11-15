<?php

/*
    a LiteLLM proxy (an OpenAI-compatible API in front of the models it is configured with), for the LiteLLM step.
    As an OpenAI API credential (CredentialOpenAI: base URL + optional API key, sent as `Authorization: Bearer`;
    Test lists the models), with LiteLLM's address as the default.
    Provides `litellm` (the LiteLLM step) and `http` (the HTTP Request step)
*/

namespace models\credential;

class CredentialLiteLlm extends \models\credential\CredentialOpenAI {
    public static $label = 'LiteLLM proxy';
    public static $icon = 'bi-cpu';
    public static $group = 'AI';
    public static $provides = [ 'litellm', 'http' ];

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) === 'baseUrl') {
		$f['default'] = \data\AiProviders::DATA['litellm']['url'];
		$f['help'] = 'The proxy\'s OpenAI-compatible API, up to the version (requests go to <URL>/chat/completions, <URL>/models); a LiteLLM proxy: http://<host>:4000/v1';
	    }
	    if (($f['key'] ?? null) === 'apiKey')
		$f['help'] = 'Its master key or a virtual key (sk-...), when the proxy has a master_key';
	}
	unset($f);
	return $fields;
    }
}
