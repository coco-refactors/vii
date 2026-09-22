<?php
/**
 * Installed over frontend/config/main-local.php at image build time.
 */

return [
    'components' => [
        'request' => [
            // See docker-build/config/backend-main.php for why these two are set.
            'cookieValidationKey' => getenv('COOKIE_VALIDATION_KEY')
                ?: trim(@file_get_contents('/etc/vault-cookie-key')),
            'trustedHosts' => ['any'],
        ],
    ],
];
