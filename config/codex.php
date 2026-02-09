<?php

return [
    // Codex API base URL (handlers append /responses, /embeddings, etc.)
    'url' => env('CODEX_URL', 'https://chatgpt.com/backend-api/codex'),

    // OAuth callback port for browser PKCE flow (CLI login)
    'oauth_port' => env('CODEX_OAUTH_PORT', 9876),

    // OAuth callback route for web-based login
    'callback_route' => env('CODEX_CALLBACK_ROUTE', '/auth/codex/callback'),

    // Token table name
    'table' => env('CODEX_TOKEN_TABLE', 'codex_tokens'),
];
