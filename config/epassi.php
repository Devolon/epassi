<?php

return [
    'redirect_url' => env('EPASSI_REDIRECT_URL', 'https://services.epassi.fi/e_payments/v2'),
    'mac_key' => env('EPASSI_MAC_KEY', 'testmackey'),
    'account_id' => env('EPASSI_ACCOUNT_ID', '151082'),
];
