<?php
/**
 * Build-time smoke test. Run from /var/www/html.
 */

chdir('/var/www/html');

require 'vendor/autoload.php';
require 'vendor/yiisoft/yii2/Yii.php';
require 'common/config/bootstrap.php';

$failures = [];

foreach (['backend', 'frontend'] as $app) {
    require "{$app}/config/bootstrap.php";

    $config = yii\helpers\ArrayHelper::merge(
        require 'common/config/main.php',
        require 'common/config/main-local.php',
        require "{$app}/config/main.php",
        require "{$app}/config/main-local.php"
    );

    if (!is_file("{$app}/web/index.php")) {
        $failures[] = "{$app}: web/index.php was not generated";
    }
    if (empty($config['components']['db']['dsn'])) {
        $failures[] = "{$app}: no database DSN in merged config";
    }
    if (empty($config['components']['request']['cookieValidationKey'])) {
        $failures[] = "{$app}: cookieValidationKey is empty";
    }
}

if ($failures) {
    fwrite(STDERR, "\n  Config verification FAILED:\n    " . implode("\n    ", $failures) . "\n\n");
    exit(1);
}

echo "  Config chain verified for backend and frontend.\n";
