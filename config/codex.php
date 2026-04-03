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

    // Include organization claims in the returned ID token.
    'id_token_add_organizations' => env('CODEX_ID_TOKEN_ADD_ORGANIZATIONS', true),

    // Codex-specific request metadata used by first-party clients.
    'originator' => env('CODEX_ORIGINATOR', 'prism-codex'),
    'user_agent' => env('CODEX_USER_AGENT', 'prism-codex'),
];
