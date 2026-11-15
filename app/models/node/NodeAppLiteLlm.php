<?php

/*
    LiteLLM - an AI step (NodeAppAi: prompt, tools, agent loop) on a LiteLLM proxy (one OpenAI-compatible API in front of the models it is configured with), through
    that API: the OpenAI step's requests (NodeAppOpenAI), with a LiteLLM proxy credential (models\credential\CredentialLiteLlm, provides `litellm`). The model is a
    model_name of the proxy's model_list (Test on the credential lists them)
*/

namespace models\node;

class NodeAppLiteLlm extends NodeAppOpenAI {
    public static $audience = 'advanced';
    public static $name = 'litellm';
    public static $label = 'LiteLLM';
    public static $icon = 'bi-cpu';

    const CAPABILITY = 'litellm';
    const PROVIDER = \data\AiProviders::DATA[self::CAPABILITY]['name'];
    //a local server: a long prompt on a local model can take minutes
    const DEFAULT_TIMEOUT = 900;
    const MAX_TIMEOUT = 900;
}
