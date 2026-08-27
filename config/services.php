<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Browsershot (CarrefourDiscountScraper)
    |--------------------------------------------------------------------------
    |
    | Puppeteer's own bundled-Chromium download only runs as an npm
    | postinstall script, and this project disables those project-wide
    | (see .npmrc's `ignore-scripts`) — so it never happens automatically.
    | Point at a real Chrome/Chromium binary instead: leave unset locally
    | to auto-detect the puppeteer-managed one Browsershot already knows
    | how to find once `npx puppeteer browsers install chrome-headless-shell`
    | has been run once, or set these explicitly for an environment with
    | its own system Chromium (a deploy step, CI, ...).
    |
    */

    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
    ],

];
