<?php

return [
    'account_providers' => ['google', 'spotify'],
    'max_services_per_turn' => 2,
    'max_list_items' => 15,
    'max_content_characters' => 12000,
    'connect_timeout' => 5,
    'timeout' => 15,

    'providers' => [
        'google' => [
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'revoke_url' => 'https://oauth2.googleapis.com/revoke',
            'identity_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ],
        'microsoft' => [
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'identity_url' => 'https://graph.microsoft.com/v1.0/me',
        ],
        'spotify' => [
            'authorize_url' => 'https://accounts.spotify.com/authorize',
            'token_url' => 'https://accounts.spotify.com/api/token',
            'identity_url' => 'https://api.spotify.com/v1/me',
        ],
        'notion' => [
            'authorize_url' => 'https://api.notion.com/v1/oauth/authorize',
            'token_url' => 'https://api.notion.com/v1/oauth/token',
            'revoke_url' => 'https://api.notion.com/v1/oauth/revoke',
            'notion_version' => '2026-03-11',
        ],
        'slack_oauth' => [
            'authorize_url' => 'https://slack.com/oauth/v2/authorize',
            'token_url' => 'https://slack.com/api/oauth.v2.access',
            'revoke_url' => 'https://slack.com/api/auth.revoke',
            'identity_url' => 'https://slack.com/api/auth.test',
        ],
        'dropbox' => [
            'authorize_url' => 'https://www.dropbox.com/oauth2/authorize',
            'token_url' => 'https://api.dropboxapi.com/oauth2/token',
            'revoke_url' => 'https://api.dropboxapi.com/2/auth/token/revoke',
            'identity_url' => 'https://api.dropboxapi.com/2/users/get_current_account',
        ],
        'github_app' => [
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'revoke_url' => 'https://api.github.com/applications',
            'identity_url' => 'https://api.github.com/user',
        ],
    ],
];
