<?php
/**
 * Installed over backend/config/main-local.php at image build time.
 */

return [
    'components' => [
        'request' => [
            // `./init` generates a fresh key on every run, which would invalidate every
            // identity and CSRF cookie on each deploy. Set COOKIE_VALIDATION_KEY to a
            // stable value; the build-time fallback only keeps local runs working.
            'cookieValidationKey' => getenv('COOKIE_VALIDATION_KEY')
                ?: trim(@file_get_contents('/etc/vault-cookie-key')),
            // The container is only reachable through the platform's proxy, so its
            // X-Forwarded-* headers are the only source of the real scheme and client IP.
            'trustedHosts' => ['any'],
        ],
    ],
];
