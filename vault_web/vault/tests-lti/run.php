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

function http($method, $url, $fields = [], $headers = [], $basicUser = null)
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new \RuntimeException('Could not initialize test HTTP request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    if ($basicUser !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':');
    }

    $body = curl_exec($ch);

    if ($body === false) {
        throw new \RuntimeException(
            'Test HTTP request failed with cURL error ' . curl_errno($ch)
        );
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, json_decode($body, true)];
}

function freePort()
{
    $server = @stream_socket_server(
        'tcp://127.0.0.1:0',
        $errorNumber,
        $errorMessage
    );

    if ($server === false) {
        throw new \RuntimeException(
            "Could not reserve a test port: {$errorNumber} {$errorMessage}"
        );
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);

    if (
        $address === false ||
        !preg_match('/:(\d+)$/', $address, $matches)
    ) {
        throw new \RuntimeException('Could not determine the reserved test port.');
    }

    return (int)$matches[1];
}

function startServer($port, array $arguments, array $environment = [])
{
    $logFile = tempnam(sys_get_temp_dir(), 'vii-server-');

    if ($logFile === false) {
        throw new \RuntimeException('Could not create a test server log.');
    }

    $command = array_merge(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}"],
        $arguments
    );

    $baseEnvironment = getenv();

    if (!is_array($baseEnvironment)) {
        $baseEnvironment = [];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname(__DIR__),
        array_merge($baseEnvironment, $environment),
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        @unlink($logFile);
        throw new \RuntimeException('Could not start the test server.');
    }

    fclose($pipes[0]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $status = proc_get_status($process);

        if (!$status['running']) {
            $output = trim((string)@file_get_contents($logFile));
            proc_close($process);
            @unlink($logFile);

            throw new \RuntimeException(
                "Test server stopped during startup: {$output}"
            );
        }

        $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);

        if (is_resource($connection)) {
            fclose($connection);

            return [
                'process' => $process,
                'logFile' => $logFile,
            ];
        }

        usleep(100000);
    }

    proc_terminate($process);
    proc_close($process);

    $output = trim((string)@file_get_contents($logFile));
    @unlink($logFile);

    throw new \RuntimeException(
        "Test server did not become ready: {$output}"
    );
}

function stopServer(array $server)
{
    if (
        isset($server['process']) &&
        is_resource($server['process'])
    ) {
        $status = proc_get_status($server['process']);

        if ($status['running']) {
            proc_terminate($server['process']);

            for ($attempt = 0; $attempt < 20; $attempt++) {
                usleep(50000);
                $status = proc_get_status($server['process']);

                if (!$status['running']) {
                    break;
                }
            }

            if ($status['running']) {
                proc_terminate($server['process'], 9);
            }
        }

        proc_close($server['process']);
    }

    if (
        isset($server['logFile']) &&
        is_string($server['logFile'])
    ) {
        @unlink($server['logFile']);
    }
}

function saveFixtureModel($model, $label)
{
    if (!$model->save()) {
        throw new \RuntimeException(
            $label . ' could not be saved: ' .
            json_encode($model->getErrors())
        );
    }
}

function createFixture()
{
    $transaction = Yii::$app->db->beginTransaction();

    try {
        $userTypeId = (int)Yii::$app->db->createCommand(
            'SELECT id FROM {{%user_type}} ORDER BY id LIMIT 1'
        )->queryScalar();

        $taskTypeId = (int)Yii::$app->db->createCommand(
            'SELECT id FROM {{%task_type}} ORDER BY id LIMIT 1'
        )->queryScalar();

        if ($userTypeId <= 0 || $taskTypeId <= 0) {
            throw new \RuntimeException(
                'Starter user and task types must be loaded before testing.'
            );
        }

        $suffix = bin2hex(random_bytes(6));
        $password = 'test-password-' . $suffix;

        $user = new User();
        $user->user_type_id = $userTypeId;
        $user->username = 'lti-test-' . $suffix;
        $user->email = 'lti-test-' . $suffix . '@example.test';
        $user->status = User::STATUS_ACTIVE;
        $user->auth_key = Yii::$app->security->generateRandomString();
        $user->setPassword($password);
        saveFixtureModel($user, 'Test user');

        $quest = new Quest();
        $quest->code = 'lti-' . $suffix;
        $quest->name = 'LTI Test Quest ' . $suffix;
        $quest->description = 'Temporary Sprint 4 integration fixture';
        $quest->is_active = 1;
        saveFixtureModel($quest, 'Test quest');

        $taskIds = [];

        foreach (
            [
                ['First test task', '<p>First test prompt</p>', 'private-answer-one'],
                ['Second test task', '<p>Second test prompt</p>', 'private-answer-two'],
            ] as $index => $values
        ) {
            $task = new Task();
            $task->quest_id = $quest->id;
            $task->task_type_id = $taskTypeId;
            $task->title = $values[0];
            $task->body = $values[1];
            $task->answer = $values[2];
            $task->auto_complete = 0;
            $task->is_active = 1;
            $task->sort_order = $index + 1;
            saveFixtureModel($task, 'Test task');

            $taskIds[] = (int)$task->id;
        }

        $transaction->commit();

        return [
            'userId' => (int)$user->id,
            'username' => $user->username,
            'password' => $password,
            'questId' => (int)$quest->id,
            'questName' => $quest->name,
            'taskIds' => $taskIds,
        ];
    } catch (\Throwable $error) {
        $transaction->rollBack();
        throw $error;
    }
}

function cleanupFixture(array $fixture)
{
    $transaction = Yii::$app->db->beginTransaction();

    try {
        if (!empty($fixture['questId'])) {
            Quest::deleteAll(['id' => (int)$fixture['questId']]);
        }

        if (!empty($fixture['userId'])) {
            User::deleteAll(['id' => (int)$fixture['userId']]);
        }

        $transaction->commit();
    } catch (\Throwable $error) {
        $transaction->rollBack();
        throw $error;
    }
}

section('Fake gateway test server');

$fakeGatewayDirectory =
    sys_get_temp_dir() . '/vii-fake-gateway-' . bin2hex(random_bytes(6));
$fakeGatewayServer = null;

if (!mkdir($fakeGatewayDirectory, 0700, true)) {
    throw new \RuntimeException('Could not create the fake gateway directory.');
}

try {
    if (
        file_put_contents($fakeGatewayDirectory . '/mode', '200') === false
    ) {
        throw new \RuntimeException('Could not configure the fake gateway.');
    }

    $fakeGatewayPort = freePort();
    $fakeGatewayServer = startServer(
        $fakeGatewayPort,
        ['tests-lti/fake-gateway.php'],
        ['FAKE_GW_DIR' => $fakeGatewayDirectory]
    );

    [$status, $response] = http(
        'POST',
        "http://127.0.0.1:{$fakeGatewayPort}/hooks/attempts",
        ['probe' => 'ready']
    );

    check(
        $status === 200 &&
        is_array($response) &&
        ($response['ok'] ?? false) === true,
        'fake gateway accepts an HTTP request'
    );

    $recordFiles = glob($fakeGatewayDirectory . '/req-*.json') ?: [];
    sort($recordFiles);

    check(
        count($recordFiles) === 1,
        'fake gateway records one request'
    );

    $record = count($recordFiles) === 1
        ? json_decode((string)file_get_contents($recordFiles[0]), true)
        : null;

    check(
        is_array($record) &&
        ($record['path'] ?? null) === '/hooks/attempts' &&
        ($record['body'] ?? null) === 'probe=ready',
        'fake gateway records the request path and body'
    );
} finally {
    if (is_array($fakeGatewayServer)) {
        stopServer($fakeGatewayServer);
    }

    foreach (glob($fakeGatewayDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($fakeGatewayDirectory);
}

section('Protected gateway experiences endpoint');

$gatewayKey = str_repeat('k', 40);
$viiPort = freePort();
$viiServer = null;
$fixture = createFixture();

try {
    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ],
        [
            'GATEWAY_API_KEY' => $gatewayKey,
            'GATEWAY_HOOK_URL' => 'http://127.0.0.1/unused',
            'GATEWAY_HOOK_SECRET' => str_repeat('s', 40),
        ]
    );

    $experiencesUrl =
        "http://127.0.0.1:{$viiPort}/gateway/experiences";

    [$status, $response] = http('GET', $experiencesUrl);

    check(
        $status === 401 &&
        ($response['returnCode'] ?? null) === 1,
        'experiences rejects a missing gateway key'
    );

    [$status, $response] = http(
        'GET',
        $experiencesUrl,
        [],
        ['X-Gateway-Key: wrong-key']
    );

    check(
        $status === 401 &&
        ($response['returnCode'] ?? null) === 1,
        'experiences rejects an incorrect gateway key'
    );

    [$status, $response] = http(
        'GET',
        $experiencesUrl,
        [],
        ['X-Gateway-Key: ' . $gatewayKey]
    );

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        is_array($response['data']['experiences'] ?? null),
        'experiences accepts the configured gateway key'
    );

    $fixtureExperiences = array_values(array_filter(
        $response['data']['experiences'],
        function ($experience) use ($fixture) {
            return (int)($experience['id'] ?? 0) === $fixture['questId'];
        }
    ));

    check(
        count($fixtureExperiences) === 1 &&
        $fixtureExperiences[0]['name'] === $fixture['questName'] &&
        array_keys($fixtureExperiences[0]) === [
            'id',
            'name',
            'description',
        ],
        'experiences returns the active fixture with public fields only'
    );

    [$status, $response] = http(
        'POST',
        $experiencesUrl,
        [],
        ['X-Gateway-Key: ' . $gatewayKey]
    );

    check(
        $status === 405,
        'experiences rejects non-GET requests'
    );

} finally {
    try {
        if (is_array($viiServer)) {
            stopServer($viiServer);
        }
    } finally {
        cleanupFixture($fixture);
    }
}

check(
    Quest::findOne($fixture['questId']) === null &&
    User::findOne($fixture['userId']) === null,
    'temporary integration fixtures are removed'
);

section('Authenticated attempt start and resume');

$fixture = createFixture();
$viiPort = freePort();
$viiServer = null;

try {
    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $fixture['username'],
            'password' => $fixture['password'],
        ]
    );

    $accessToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        $accessToken !== '',
        'fixture user authenticates and receives an access token'
    );

    if ($accessToken === '') {
        throw new \RuntimeException(
            'Authentication did not return the token required for attempt tests.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $firstAttempt = $response['data']['attempt'] ?? [];

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        ($response['data']['isNew'] ?? null) === true &&
        ($firstAttempt['number'] ?? null) === 1 &&
        ($firstAttempt['status'] ?? null) === QuestAttempt::STATUS_OPEN,
        'first start creates attempt one'
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $resumedAttempt = $response['data']['attempt'] ?? [];

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        ($response['data']['isNew'] ?? null) === false &&
        ($resumedAttempt['id'] ?? null) === ($firstAttempt['id'] ?? null),
        'second start resumes the existing open attempt'
    );

    $storedAttempts = QuestAttempt::find()
        ->where([
            'user_id' => $fixture['userId'],
            'quest_id' => $fixture['questId'],
        ])
        ->all();

    check(
        count($storedAttempts) === 1 &&
        $storedAttempts[0]->status === QuestAttempt::STATUS_OPEN &&
        (int)$storedAttempts[0]->attempt_number === 1,
        'resume does not create a duplicate attempt'
    );
} finally {
    try {
        if (is_array($viiServer)) {
            stopServer($viiServer);
        }
    } finally {
        cleanupFixture($fixture);
    }
}

section('Attempt progress and completion hooks');

$fakeGatewayDirectory =
    sys_get_temp_dir() . '/vii-start-hook-' . bin2hex(random_bytes(6));
$fixture = null;
$fakeGatewayServer = null;
$viiServer = null;
$hookSecret = str_repeat('h', 40);

if (!mkdir($fakeGatewayDirectory, 0700, true)) {
    throw new \RuntimeException('Could not create the hook test directory.');
}

try {
    $fixture = createFixture();

    if (
        file_put_contents($fakeGatewayDirectory . '/mode', '200') === false
    ) {
        throw new \RuntimeException('Could not configure the fake gateway.');
    }

    $fakeGatewayPort = freePort();
    $viiPort = freePort();

    $fakeGatewayServer = startServer(
        $fakeGatewayPort,
        ['tests-lti/fake-gateway.php'],
        ['FAKE_GW_DIR' => $fakeGatewayDirectory]
    );

    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ],
        [
            'GATEWAY_API_KEY' => str_repeat('k', 40),
            'GATEWAY_HOOK_URL' =>
                "http://127.0.0.1:{$fakeGatewayPort}/hooks/attempts",
            'GATEWAY_HOOK_SECRET' => $hookSecret,
        ]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $fixture['username'],
            'password' => $fixture['password'],
        ]
    );

    $accessToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    if ($status !== 200 || $accessToken === '') {
        throw new \RuntimeException(
            'Could not authenticate the progress-hook fixture.'
        );
    }

    $launchRef = 'launch-' . bin2hex(random_bytes(8));

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        [
            'quest_id' => $fixture['questId'],
            'launch_ref' => $launchRef,
        ],
        [],
        $accessToken
    );

    $attemptData = $response['data']['attempt'] ?? [];
    $attemptId = (int)($attemptData['id'] ?? 0);

    check(
        $status === 200 &&
        ($response['data']['isNew'] ?? null) === true &&
        $attemptId > 0,
        'launch reference creates a new first attempt'
    );

    $storedAttempt = QuestAttempt::findOne($attemptId);

    check(
        $storedAttempt !== null &&
        (int)$storedAttempt->progress_sent === 1,
        'successful started hook is marked as sent'
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        [
            'quest_id' => $fixture['questId'],
            'launch_ref' => $launchRef,
        ],
        [],
        $accessToken
    );

    check(
        $status === 200 &&
        ($response['data']['isNew'] ?? null) === false &&
        (int)($response['data']['attempt']['id'] ?? 0) === $attemptId,
        'resuming the attempt does not create another attempt'
    );

    $recordFiles = glob($fakeGatewayDirectory . '/req-*.json') ?: [];
    sort($recordFiles);

    check(
        count($recordFiles) === 1,
        'first-attempt progress hook is delivered exactly once'
    );

    $record = count($recordFiles) === 1
        ? json_decode((string)file_get_contents($recordFiles[0]), true)
        : null;

    $hookBody = is_array($record)
        ? (string)($record['body'] ?? '')
        : '';
    $hookPayload = json_decode($hookBody, true);

    check(
        is_array($hookPayload) &&
        ($record['path'] ?? null) === '/hooks/attempts' &&
        ($hookPayload['event'] ?? null) === 'attempt.started' &&
        ($hookPayload['launchRef'] ?? null) === $launchRef &&
        ($hookPayload['viiUsername'] ?? null) === $fixture['username'] &&
        (int)($hookPayload['questId'] ?? 0) === $fixture['questId'] &&
        (int)($hookPayload['attempt']['number'] ?? 0) === 1 &&
        ($hookPayload['activityProgress'] ?? null) === 'Started' &&
        !array_key_exists('score', $hookPayload) &&
        !array_key_exists('content', $hookPayload),
        'started hook contains progress metadata without score content'
    );

    check(
        is_array($record) &&
        HookSigner::verify(
            $hookSecret,
            (string)($record['timestamp'] ?? ''),
            $hookBody,
            (string)($record['signature'] ?? '')
        ),
        'started hook signature validates against its exact body'
    );

    foreach ($recordFiles as $file) {
        @unlink($file);
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/download-quest',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $downloadedTasks = $response['data']['quest']['tasks'] ?? [];
    $userTaskIds = array_map(
        function ($task) {
            return (int)($task['userTask']['id'] ?? 0);
        },
        $downloadedTasks
    );

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        count($downloadedTasks) === 2 &&
        min($userTaskIds) > 0,
        'downloading the quest creates both user task records'
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/save-user-task',
        [
            'id' => $userTaskIds[0],
            'UserTask' => [
                'id' => $userTaskIds[0],
                'task_id' => $fixture['taskIds'][0],
                'is_complete' => 1,
                'response_text' => 'First integration response',
            ],
        ],
        [],
        $accessToken
    );

    $storedAttempt = QuestAttempt::findOne($attemptId);
    $completionRecords =
        glob($fakeGatewayDirectory . '/req-*.json') ?: [];

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        $storedAttempt !== null &&
        $storedAttempt->status === QuestAttempt::STATUS_OPEN &&
        count($completionRecords) === 0,
        'completing one task leaves the attempt open without a finish hook'
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/save-user-task',
        [
            'id' => $userTaskIds[1],
            'UserTask' => [
                'id' => $userTaskIds[1],
                'task_id' => $fixture['taskIds'][1],
                'is_complete' => 1,
                'response_text' => 'Second integration response',
            ],
        ],
        [],
        $accessToken
    );

    $storedAttempt = QuestAttempt::findOne($attemptId);

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        $storedAttempt !== null &&
        $storedAttempt->status === QuestAttempt::STATUS_FINISHED &&
        (int)$storedAttempt->tasks_completed === 2 &&
        (int)$storedAttempt->tasks_total === 2 &&
        (float)$storedAttempt->score === 1.0 &&
        $storedAttempt->hook_status === QuestAttempt::HOOK_CONFIRMED,
        'completing all active tasks finishes and scores the attempt'
    );

    $completionRecords =
        glob($fakeGatewayDirectory . '/req-*.json') ?: [];
    sort($completionRecords);

    check(
        count($completionRecords) === 1,
        'completion hook is delivered exactly once'
    );

    $completionRecord = count($completionRecords) === 1
        ? json_decode(
            (string)file_get_contents($completionRecords[0]),
            true
        )
        : null;

    $completionBody = is_array($completionRecord)
        ? (string)($completionRecord['body'] ?? '')
        : '';
    $completionPayload = json_decode($completionBody, true);
    $completionTasks =
        $completionPayload['content']['tasks'] ?? [];

    check(
        is_array($completionPayload) &&
        ($completionPayload['event'] ?? null) === 'attempt.finished' &&
        ($completionPayload['activityProgress'] ?? null) === 'Completed' &&
        (float)($completionPayload['score'] ?? -1) === 1.0 &&
        ($completionPayload['content']['questName'] ?? null) ===
            $fixture['questName'] &&
        (int)($completionPayload['content']['tasksCompleted'] ?? 0) === 2 &&
        (int)($completionPayload['content']['tasksTotal'] ?? 0) === 2 &&
        count($completionTasks) === 2 &&
        ($completionTasks[0]['response'] ?? null) ===
            'First integration response' &&
        ($completionTasks[1]['response'] ?? null) ===
            'Second integration response',
        'completion hook contains the final score and readable responses'
    );

    check(
        strpos($completionBody, 'private-answer-one') === false &&
        strpos($completionBody, 'private-answer-two') === false &&
        !array_key_exists('answer', $completionTasks[0] ?? []) &&
        !array_key_exists('answer', $completionTasks[1] ?? []),
        'completion hook excludes private task answers'
    );

    check(
        is_array($completionRecord) &&
        HookSigner::verify(
            $hookSecret,
            (string)($completionRecord['timestamp'] ?? ''),
            $completionBody,
            (string)($completionRecord['signature'] ?? '')
        ),
        'completion hook signature validates against its exact body'
    );

} finally {
    if (is_array($viiServer)) {
        stopServer($viiServer);
    }

    if (is_array($fakeGatewayServer)) {
        stopServer($fakeGatewayServer);
    }

    if (is_array($fixture)) {
        cleanupFixture($fixture);
    }

    foreach (glob($fakeGatewayDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($fakeGatewayDirectory);
}

section('Hook retry and terminal failure delivery');

$retryDirectory =
    sys_get_temp_dir() . '/vii-retry-hook-' . bin2hex(random_bytes(6));
$fixture = null;
$fakeGatewayServer = null;
$hookSecret = str_repeat('r', 40);

$originalHookUrl = Yii::$app->params['gateway.hookUrl'];
$originalHookSecret = Yii::$app->params['gateway.hookSecret'];

if (!mkdir($retryDirectory, 0700, true)) {
    throw new \RuntimeException('Could not create the retry test directory.');
}

try {
    $fixture = createFixture();

    if (file_put_contents($retryDirectory . '/mode', '503') === false) {
        throw new \RuntimeException('Could not configure retry mode.');
    }

    $fakeGatewayPort = freePort();
    $fakeGatewayServer = startServer(
        $fakeGatewayPort,
        ['tests-lti/fake-gateway.php'],
        ['FAKE_GW_DIR' => $retryDirectory]
    );

    Yii::$app->params['gateway.hookUrl'] =
        "http://127.0.0.1:{$fakeGatewayPort}/hooks/attempts";
    Yii::$app->params['gateway.hookSecret'] = $hookSecret;

    foreach ($fixture['taskIds'] as $index => $taskId) {
        $userTask = new \common\models\UserTask();
        $userTask->user_id = $fixture['userId'];
        $userTask->task_id = $taskId;
        $userTask->is_complete = 1;
        $userTask->response_text = 'Retry response ' . ($index + 1);
        saveFixtureModel($userTask, 'Retry user task');
    }

    [$retryAttempt] = QuestAttempt::start(
        $fixture['userId'],
        $fixture['questId'],
        'retry-' . bin2hex(random_bytes(8))
    );

    $retryAttempt = $retryAttempt->finish();
    $firstDelivery = GatewayHook::sendFinished($retryAttempt);
    $retryAttempt->refresh();

    check(
        $firstDelivery === false &&
        $retryAttempt->hook_status === QuestAttempt::HOOK_PENDING &&
        (int)$retryAttempt->hook_tries === 1 &&
        $retryAttempt->next_retry_at !== null &&
        strtotime($retryAttempt->next_retry_at) > time(),
        '503 delivery remains pending with retry backoff'
    );

    if (file_put_contents($retryDirectory . '/mode', '200') === false) {
        throw new \RuntimeException('Could not configure success mode.');
    }

    $retryAttempt->finished_at = '2000-01-01 00:00:00';
    $retryAttempt->next_retry_at = date('Y-m-d H:i:s', time() - 5);

    if (
        !$retryAttempt->save(
            false,
            ['finished_at', 'next_retry_at', 'updated_at']
        )
    ) {
        throw new \RuntimeException('Could not make the retry immediately due.');
    }

    [$due, $confirmed] = GatewayHook::resendDue(1);
    $retryAttempt->refresh();

    check(
        $due === 1 &&
        $confirmed === 1 &&
        $retryAttempt->hook_status === QuestAttempt::HOOK_CONFIRMED &&
        (int)$retryAttempt->hook_tries === 2 &&
        $retryAttempt->next_retry_at === null,
        'due 503 delivery is resent and confirmed'
    );

    $retryRecords = glob($retryDirectory . '/req-*.json') ?: [];
    sort($retryRecords);

    $firstRetryRecord = count($retryRecords) >= 1
        ? json_decode((string)file_get_contents($retryRecords[0]), true)
        : null;
    $secondRetryRecord = count($retryRecords) >= 2
        ? json_decode((string)file_get_contents($retryRecords[1]), true)
        : null;

    $firstRetryPayload = is_array($firstRetryRecord)
        ? json_decode((string)$firstRetryRecord['body'], true)
        : null;
    $secondRetryPayload = is_array($secondRetryRecord)
        ? json_decode((string)$secondRetryRecord['body'], true)
        : null;

    check(
        count($retryRecords) === 2 &&
        is_array($firstRetryPayload) &&
        is_array($secondRetryPayload) &&
        ($firstRetryPayload['deliveryId'] ?? null) !==
            ($secondRetryPayload['deliveryId'] ?? null),
        'retry uses a new delivery identifier'
    );

    if (file_put_contents($retryDirectory . '/mode', '410') === false) {
        throw new \RuntimeException('Could not configure terminal failure mode.');
    }

    [$terminalAttempt] = QuestAttempt::start(
        $fixture['userId'],
        $fixture['questId'],
        'terminal-' . bin2hex(random_bytes(8))
    );

    \common\models\UserTask::updateAll(
        [
            'is_complete' => 1,
            'response_text' => 'Terminal failure response',
        ],
        [
            'user_id' => $fixture['userId'],
            'task_id' => $fixture['taskIds'],
        ]
    );

    $terminalAttempt = $terminalAttempt->finish();
    $terminalDelivery = GatewayHook::sendFinished($terminalAttempt);
    $terminalAttempt->refresh();

    check(
        $terminalDelivery === false &&
        $terminalAttempt->hook_status === QuestAttempt::HOOK_FAILED &&
        (int)$terminalAttempt->hook_tries === 1 &&
        $terminalAttempt->next_retry_at === null,
        '410 delivery becomes a permanent failure'
    );

    $terminalIsRetryable = QuestAttempt::find()
        ->where([
            'id' => $terminalAttempt->id,
            'hook_status' => QuestAttempt::HOOK_PENDING,
        ])
        ->andWhere([
            'or',
            ['next_retry_at' => null],
            ['<=', 'next_retry_at', date('Y-m-d H:i:s')],
        ])
        ->exists();

    check(
        $terminalIsRetryable === false,
        'permanent hook failure is excluded from retries'
    );
} finally {
    Yii::$app->params['gateway.hookUrl'] = $originalHookUrl;
    Yii::$app->params['gateway.hookSecret'] = $originalHookSecret;

    if (is_array($fakeGatewayServer)) {
        stopServer($fakeGatewayServer);
    }

    if (is_array($fixture)) {
        cleanupFixture($fixture);
    }

    foreach (glob($retryDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($retryDirectory);
}

section('User task ownership enforcement');

$ownerFixture = null;
$attackerFixture = null;
$viiServer = null;

try {
    $ownerFixture = createFixture();
    $attackerFixture = createFixture();

    $ownedUserTask = new \common\models\UserTask();
    $ownedUserTask->user_id = $ownerFixture['userId'];
    $ownedUserTask->task_id = $ownerFixture['taskIds'][0];
    $ownedUserTask->is_complete = 0;
    $ownedUserTask->response_text = 'Original owner response';
    saveFixtureModel($ownedUserTask, 'Owned user task');

    $viiPort = freePort();
    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $attackerFixture['username'],
            'password' => $attackerFixture['password'],
        ]
    );

    $attackerToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    if ($status !== 200 || $attackerToken === '') {
        throw new \RuntimeException(
            'Could not authenticate the ownership-test user.'
        );
    }

    [$status] = http(
        'POST',
        $apiUrl . '/save-user-task',
        [
            'id' => $ownedUserTask->id,
            'UserTask' => [
                'id' => $ownedUserTask->id,
                'user_id' => $attackerFixture['userId'],
                'task_id' => $ownerFixture['taskIds'][0],
                'is_complete' => 1,
                'response_text' => 'Unauthorized direct update',
            ],
        ],
        [],
        $attackerToken
    );

    $ownedUserTask->refresh();

    check(
        $status === 403 &&
        (int)$ownedUserTask->user_id === $ownerFixture['userId'] &&
        (int)$ownedUserTask->is_complete === 0 &&
        $ownedUserTask->response_text === 'Original owner response',
        'save-user-task rejects updates to another user task'
    );

    $queuedUpdate = json_encode([
        'id' => 'ownership-test-update',
        'json' => json_encode([
            'id' => (int)$ownedUserTask->id,
            'user_id' => $attackerFixture['userId'],
            'task_id' => $ownerFixture['taskIds'][0],
            'is_complete' => 1,
            'response_text' => 'Unauthorized sync update',
        ]),
    ]);

    [$status] = http(
        'POST',
        $apiUrl . '/sync-user-tasks',
        ['userTasks' => [$queuedUpdate]],
        [],
        $attackerToken
    );

    $ownedUserTask->refresh();

    check(
        $status === 403 &&
        (int)$ownedUserTask->user_id === $ownerFixture['userId'] &&
        (int)$ownedUserTask->is_complete === 0 &&
        $ownedUserTask->response_text === 'Original owner response',
        'sync-user-tasks rejects updates to another user task'
    );
} finally {
    if (is_array($viiServer)) {
        stopServer($viiServer);
    }

    if (is_array($attackerFixture)) {
        cleanupFixture($attackerFixture);
    }

    if (is_array($ownerFixture)) {
        cleanupFixture($ownerFixture);
    }
}

section('Quest reset and gateway attempt state');

$fixture = null;
$viiServer = null;
$gatewayKey = str_repeat('g', 40);

try {
    $fixture = createFixture();
    $viiPort = freePort();

    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ],
        ['GATEWAY_API_KEY' => $gatewayKey]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $fixture['username'],
            'password' => $fixture['password'],
        ]
    );

    $accessToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    if ($status !== 200 || $accessToken === '') {
        throw new \RuntimeException(
            'Could not authenticate the reset-test user.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    if (
        $status !== 200 ||
        ($response['data']['isNew'] ?? null) !== true
    ) {
        throw new \RuntimeException('Could not create the reset-test attempt.');
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/download-quest',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    if ($status !== 200 || ($response['returnCode'] ?? null) !== 0) {
        throw new \RuntimeException('Could not create reset-test task progress.');
    }

    \common\models\UserTask::updateAll(
        [
            'is_complete' => 1,
            'response_text' => 'Progress before reset',
        ],
        [
            'user_id' => $fixture['userId'],
            'task_id' => $fixture['taskIds'],
        ]
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/reset-quest',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $attempts = QuestAttempt::find()
        ->where([
            'user_id' => $fixture['userId'],
            'quest_id' => $fixture['questId'],
        ])
        ->orderBy(['attempt_number' => SORT_ASC])
        ->all();

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        count($attempts) === 2 &&
        $attempts[0]->status === QuestAttempt::STATUS_ABANDONED &&
        (int)$attempts[0]->attempt_number === 1 &&
        $attempts[1]->status === QuestAttempt::STATUS_OPEN &&
        (int)$attempts[1]->attempt_number === 2,
        'reset abandons attempt one and opens attempt two'
    );

    $userTasks = \common\models\UserTask::find()
        ->where([
            'user_id' => $fixture['userId'],
            'task_id' => $fixture['taskIds'],
        ])
        ->all();

    $progressWasReset =
        count($userTasks) === 2 &&
        count(array_filter(
            $userTasks,
            function ($userTask) {
                return
                    (int)$userTask->is_complete !== 0 ||
                    $userTask->response_text !== null;
            }
        )) === 0;

    check(
        $progressWasReset,
        'reset clears completion state and responses'
    );

    $stateUrl =
        "http://127.0.0.1:{$viiPort}/gateway/attempt-state?" .
        http_build_query([
            'username' => $fixture['username'],
            'quest_id' => $fixture['questId'],
        ]);

    [$status, $response] = http(
        'GET',
        $stateUrl,
        [],
        ['X-Gateway-Key: ' . $gatewayKey]
    );

    $latestState = $response['data']['latest'] ?? null;

    check(
        $status === 200 &&
        ($response['data']['attempts'] ?? null) === 2 &&
        is_array($latestState) &&
        ($latestState['number'] ?? null) === 2 &&
        ($latestState['status'] ?? null) === QuestAttempt::STATUS_OPEN &&
        !array_key_exists('score', $latestState),
        'gateway attempt state reports attempt two without a score'
    );
} finally {
    if (is_array($viiServer)) {
        stopServer($viiServer);
    }

    if (is_array($fixture)) {
        cleanupFixture($fixture);
    }
}

section('Successful task synchronization');

$fixture = null;
$viiServer = null;

try {
    $fixture = createFixture();
    $viiPort = freePort();

    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $fixture['username'],
            'password' => $fixture['password'],
        ]
    );

    $accessToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    if ($status !== 200 || $accessToken === '') {
        throw new \RuntimeException(
            'Could not authenticate the synchronization-test user.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $attemptId = (int)($response['data']['attempt']['id'] ?? 0);

    if ($status !== 200 || $attemptId <= 0) {
        throw new \RuntimeException(
            'Could not start the synchronization-test attempt.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/download-quest',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $downloadedTasks = $response['data']['quest']['tasks'] ?? [];

    if ($status !== 200 || count($downloadedTasks) !== 2) {
        throw new \RuntimeException(
            'Could not download synchronization-test tasks.'
        );
    }

    $syncItems = [];

    foreach ($downloadedTasks as $index => $task) {
        $userTaskId = (int)($task['userTask']['id'] ?? 0);

        $syncItems[] = json_encode([
            'id' => 'sync-' . $userTaskId,
            'json' => json_encode([
                'id' => $userTaskId,
                'user_id' => $fixture['userId'],
                'task_id' => $fixture['taskIds'][$index],
                'is_complete' => 1,
                'response_text' =>
                    'Synchronized response ' . ($index + 1),
            ]),
        ]);
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/sync-user-tasks',
        ['userTasks' => $syncItems],
        [],
        $accessToken
    );

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        ($response['data']['tasksNotSaved'] ?? null) === [],
        'sync-user-tasks saves both task updates'
    );

    $attempt = QuestAttempt::findOne($attemptId);

    check(
        $attempt !== null &&
        $attempt->status === QuestAttempt::STATUS_FINISHED &&
        (int)$attempt->tasks_completed === 2 &&
        (int)$attempt->tasks_total === 2 &&
        (float)$attempt->score === 1.0 &&
        $attempt->hook_status === QuestAttempt::HOOK_NONE,
        'successful synchronization finishes and scores the attempt'
    );

    $savedTasks = \common\models\UserTask::find()
        ->where([
            'user_id' => $fixture['userId'],
            'task_id' => $fixture['taskIds'],
        ])
        ->orderBy(['task_id' => SORT_ASC])
        ->all();

    check(
        count($savedTasks) === 2 &&
        (int)$savedTasks[0]->user_id === $fixture['userId'] &&
        (int)$savedTasks[1]->user_id === $fixture['userId'] &&
        (int)$savedTasks[0]->is_complete === 1 &&
        (int)$savedTasks[1]->is_complete === 1 &&
        $savedTasks[0]->response_text === 'Synchronized response 1' &&
        $savedTasks[1]->response_text === 'Synchronized response 2',
        'synchronized task progress remains owned by the authenticated user'
    );
} finally {
    if (is_array($viiServer)) {
        stopServer($viiServer);
    }

    if (is_array($fixture)) {
        cleanupFixture($fixture);
    }
}

section('Explicit partial attempt completion');

$fixture = null;
$viiServer = null;

try {
    $fixture = createFixture();
    $viiPort = freePort();

    $viiServer = startServer(
        $viiPort,
        [
            '-t',
            $root . '/frontend/web',
            $root . '/frontend/web/index.php',
        ]
    );

    $apiUrl = "http://127.0.0.1:{$viiPort}/api";

    [$status, $response] = http(
        'POST',
        $apiUrl . '/authenticate',
        [
            'type' => 'basic',
            'username' => $fixture['username'],
            'password' => $fixture['password'],
        ]
    );

    $accessToken =
        (string)($response['data']['oauth']['access_token'] ?? '');

    if ($status !== 200 || $accessToken === '') {
        throw new \RuntimeException(
            'Could not authenticate the explicit-finish test user.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/start-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $attemptId = (int)($response['data']['attempt']['id'] ?? 0);

    if ($status !== 200 || $attemptId <= 0) {
        throw new \RuntimeException(
            'Could not start the explicit-finish test attempt.'
        );
    }

    [$status, $response] = http(
        'POST',
        $apiUrl . '/download-quest',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    if ($status !== 200 || ($response['returnCode'] ?? null) !== 0) {
        throw new \RuntimeException(
            'Could not create explicit-finish task progress.'
        );
    }

    \common\models\UserTask::updateAll(
        [
            'is_complete' => 1,
            'response_text' => 'Only one task completed',
        ],
        [
            'user_id' => $fixture['userId'],
            'task_id' => $fixture['taskIds'][0],
        ]
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/finish-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    $attemptState = $response['data']['attempt'] ?? [];

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 0 &&
        ($attemptState['status'] ?? null) === QuestAttempt::STATUS_FINISHED &&
        (float)($attemptState['score'] ?? -1) === 0.5,
        'finish-attempt returns a finished partial-score state'
    );

    $storedAttempt = QuestAttempt::findOne($attemptId);

    check(
        $storedAttempt !== null &&
        $storedAttempt->status === QuestAttempt::STATUS_FINISHED &&
        (int)$storedAttempt->tasks_completed === 1 &&
        (int)$storedAttempt->tasks_total === 2 &&
        (float)$storedAttempt->score === 0.5 &&
        $storedAttempt->hook_status === QuestAttempt::HOOK_NONE,
        'explicit finish stores the partial completion score'
    );

    [$status, $response] = http(
        'POST',
        $apiUrl . '/finish-attempt',
        ['quest_id' => $fixture['questId']],
        [],
        $accessToken
    );

    check(
        $status === 200 &&
        ($response['returnCode'] ?? null) === 1 &&
        ($response['data'] ?? null) === [],
        'finish-attempt rejects a quest with no open attempt'
    );
} finally {
    if (is_array($viiServer)) {
        stopServer($viiServer);
    }

    if (is_array($fixture)) {
        cleanupFixture($fixture);
    }
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