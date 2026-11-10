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

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);