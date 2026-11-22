<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'llm' => [
        'base_url' => env('LLM_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('LLM_MODEL', 'gemma4:e2b'),
        'api_key' => env('LLM_API_KEY', 'ollama'),
        'timeout' => (int) env('LLM_TIMEOUT', 120),
    ],

    'mcp_fiscal' => [
        'tools_url' => env('MCP_FISCAL_TOOLS_URL', 'http://127.0.0.1:8002/mcp'),
        'tabelas_db' => env('MCP_TABELAS_DB', '/var/www/mcp-fiscal-brasil/src/mcp_fiscal_brasil/tabelas/data/tabelas_fiscais.db'),
        'timeout' => (int) env('MCP_FISCAL_TIMEOUT', 10),
    ],

];
