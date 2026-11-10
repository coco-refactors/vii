<?php
return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'user.passwordResetTokenExpire' => 3600,

    // Canvas gateway integration. Read secrets from the environment
    'gateway.apiKey' => getenv('GATEWAY_API_KEY') ?: '',
    'gateway.hookUrl' => getenv('GATEWAY_HOOK_URL') ?: '',
    'gateway.hookSecret' => getenv('GATEWAY_HOOK_SECRET') ?: '',
];
