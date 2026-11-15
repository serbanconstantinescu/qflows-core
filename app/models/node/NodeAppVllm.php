<?php

/*
    vLLM - an AI step (NodeAppAi: prompt, tools, agent loop) on a vLLM server (local models, `vllm serve`), through its OpenAI-compatible API: the
    OpenAI step's requests (NodeAppOpenAI), with a vLLM server credential (models\credential\CredentialVllm, provides `vllm`). The model is the one the server
    serves (its Hugging Face id or --served-model-name; Test on the credential lists it). Tools need the server started with --enable-auto-tool-choice and a
    --tool-call-parser; Max tokens plus the prompt must fit its --max-model-len, or it refuses the request
*/

namespace models\node;

class NodeAppVllm extends NodeAppOpenAI {
    public static $audience = 'advanced';
    public static $name = 'vllm';
    public static $label = 'vLLM';
    public static $icon = 'bi-cpu';

    const CAPABILITY = 'vllm';
    const PROVIDER = \data\AiProviders::DATA[self::CAPABILITY]['name'];
    //a local server: a long prompt on a local model can take minutes
    const DEFAULT_TIMEOUT = 900;
    const MAX_TIMEOUT = 900;
}
