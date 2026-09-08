<?php

return [
    'client_id' => env('QUICKBOOKS_CLIENT_ID', ''),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET', ''),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI', ''),
    'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'), // sandbox or production
    'scope' => 'com.intuit.quickbooks.accounting',
    'base_url' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox') === 'production'
        ? 'https://quickbooks.api.intuit.com'
        : 'https://sandbox-quickbooks.api.intuit.com',
    'oauth_url' => 'https://appcenter.intuit.com/connect/oauth2',
    'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
];
