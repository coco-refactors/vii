<?php

/**
 * Sprint 4 integration tests. Run against the local demo database only.
 */

use common\components\AttemptRules;
use common\components\GatewayHook;
use common\components\HookSigner;
use common\models\Quest;
use common\models\QuestAttempt;
use common\models\Task;
use common\models\User;

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php',
    require $root . '/common/config/main-local.php',
    require $root . '/console/config/main.php',
    require $root . '/console/config/main-local.php'
);

new yii\console\Application($config);

$failures = 0;
$passed = 0;
$failedLabels = [];

function check($condition, $label)
{
    global $failures, $passed, $failedLabels;

    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failures++;
        $failedLabels[] = $label;
        echo "  FAIL {$label}\n";
    }
}

function section($name)
{
    echo "\n{$name}\n";
}

section('Database and model bootstrap');

check(
    Yii::$app->db->getTableSchema(QuestAttempt::tableName(), true) !== null,
    'quest_attempt model resolves its database table'
);


section('Score and retry rules');

check(
    AttemptRules::shareOf(2, 3) === 0.6667,
    '2 of 3 completed tasks scores 0.6667'
);

check(
    AttemptRules::shareOf(3, 3) === 1.0 &&
    AttemptRules::shareOf(0, 3) === 0.0,
    'all tasks scores 1; no completed tasks scores 0'
);

check(
    AttemptRules::shareOf(5, 0) === 0.0 &&
    AttemptRules::shareOf(9, 3) === 1.0,
    'zero total is handled and scores are capped at 1'
);

check(
    array_map(
        [AttemptRules::class, 'backoffSeconds'],
        range(1, 6)
    ) === [60, 120, 300, 600, 1800, 3600],
    'retry delays are 1, 2, 5, 10, 30 minutes, then hourly'
);

check(
    AttemptRules::isRetryable(0) &&
    AttemptRules::isRetryable(503) &&
    AttemptRules::isRetryable(429),
    'network failures, 503 and 429 can be retried'
);

check(
    !AttemptRules::isRetryable(400) &&
    !AttemptRules::isRetryable(401) &&
    !AttemptRules::isRetryable(410),
    '400, 401 and 410 are permanent failures'
);


section('Hook signatures');

$testSecret = str_repeat('s', 40);
$testNow = 1700000000;
$testTimestamp = (string)$testNow;
$testBody = '{"a":1}';
$testSignature = HookSigner::sign($testSecret, $testTimestamp, $testBody);

check(
    $testSignature ===
        'v1=4ab1624864e534a353376c6a31fafddc42734bc3665577aa2f32a854ef0645e2',
    'signature matches an independently calculated HMAC'
);

check(
    HookSigner::verify(
        $testSecret, $testTimestamp, $testBody, $testSignature, $testNow
    ),
    'valid signature is accepted'
);

check(
    !HookSigner::verify(
        $testSecret, $testTimestamp, '{"a":2}', $testSignature, $testNow
    ),
    'changed body is rejected'
);

check(
    !HookSigner::verify(
        $testSecret, $testTimestamp, $testBody,
        'v1=' . str_repeat('0', 64), $testNow
    ),
    'forged signature is rejected'
);

check(
    !HookSigner::verify(
        $testSecret, $testTimestamp, $testBody, $testSignature, $testNow + 301
    ) &&
    !HookSigner::verify(
        $testSecret, $testTimestamp, $testBody, $testSignature, $testNow - 301
    ),
    'timestamps outside the five-minute window are rejected'
);

$shortSecretRejected = false;

try {
    HookSigner::sign('short', $testTimestamp, $testBody);
} catch (\InvalidArgumentException $e) {
    $shortSecretRejected = true;
}

check(
    $shortSecretRejected,
    'secrets shorter than 32 characters are rejected'
);

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);