<?php

return [
    /*
    |--------------------------------------------------------------------------
    | XPOS Auth Token
    |--------------------------------------------------------------------------
    |
    | Your XPOS auth token from xpos.dev dashboard. Enables longer sessions,
    | reserved subdomains, and custom domains.
    |
    */
    'token' => env('XPOS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | XPOS Server Configuration
    |--------------------------------------------------------------------------
    |
    | The XPOS tunnel server to connect to. You shouldn't need to change this
    | unless you're running your own XPOS server.
    |
    */
    'server' => env('XPOS_SERVER', 'go.xpos.dev'),
    'ssh_port' => env('XPOS_SSH_PORT', 443),

    /*
    |--------------------------------------------------------------------------
    | Default Development Server Port
    |--------------------------------------------------------------------------
    |
    | The default port to use when starting the Laravel development server.
    | If this port is busy, the next available port will be used.
    |
    */
    'default_port' => env('XPOS_DEFAULT_PORT', 8000),

    /*
    |--------------------------------------------------------------------------
    | Auto-configure TrustProxies
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will automatically configure Laravel to trust
    | the XPOS proxy, ensuring asset URLs use HTTPS correctly.
    |
    */
    'trust_proxies' => env('XPOS_TRUST_PROXIES', true),
];
