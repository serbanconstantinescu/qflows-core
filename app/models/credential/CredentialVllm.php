<?php

/*
    a vLLM server (local models, an OpenAI-compatible API), for the vLLM step.
    As an OpenAI API credential (CredentialOpenAI: base URL + optional API key, sent as `Authorization: Bearer`;
    Test lists the models), with vLLM's address as the default.
    Provides `vllm` (the vLLM step) and `http` (the HTTP Request step)
*/

namespace models\credential;

class CredentialVllm extends \models\credential\CredentialOpenAI {
    public static $label = 'vLLM server';
    public static $icon = 'bi-cpu';
    public static $group = 'AI';
    public static $provides = [ 'vllm', 'http' ];

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) === 'baseUrl') {
		$f['default'] = \data\AiProviders::DATA['vllm']['url'];
		$f['help'] = 'The server\'s OpenAI-compatible API, up to the version (requests go to <URL>/chat/completions, <URL>/models); a vLLM server: http://<host>:8000/v1';
	    }
	    if (($f['key'] ?? null) === 'apiKey')
		$f['help'] = 'Only when the server requires one (started with --api-key or VLLM_API_KEY)';
	}
	unset($f);
	return $fields;
    }
}
