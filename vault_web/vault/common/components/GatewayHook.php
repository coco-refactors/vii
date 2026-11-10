<?php

namespace common\components;

use Yii;
use common\models\QuestAttempt;

/**
 * Builds and sends attempt events to the Canvas gateway.
 */
class GatewayHook
{
    public static function isConfigured()
    {
        $params = Yii::$app->params;
        $url = $params['gateway.hookUrl'] ?? '';
        $secret = $params['gateway.hookSecret'] ?? '';

        return is_string($url) &&
            $url !== '' &&
            is_string($secret) &&
            strlen($secret) >= 32;
    }

    /** Build the event payload, including readable work for finished attempts. */
    public static function buildPayload(QuestAttempt $attempt, $event)
    {
        $payload = [
            'event' => $event,
            'launchRef' => $attempt->launch_ref,
            'viiUsername' => $attempt->user->username,
            'questId' => (int)$attempt->quest_id,
            'attempt' => [
                'id' => (int)$attempt->id,
                'number' => (int)$attempt->attempt_number,
                'startedAt' => QuestAttempt::iso($attempt->started_at),
                'finishedAt' => QuestAttempt::iso($attempt->finished_at),
            ],
            'activityProgress' => $event === 'attempt.finished' ? 'Completed' : 'Started',
        ];

        if ($event !== 'attempt.finished') {
            return $payload;
        }

        $rows = Yii::$app->db->createCommand(
            "SELECT task.title, task.body, task_type.name AS type,
                    user_task.response_text,
                    COALESCE(user_task.is_complete, 0) AS is_complete
                FROM task
                LEFT JOIN task_type ON task_type.id = task.task_type_id
                LEFT JOIN user_task
                    ON user_task.task_id = task.id AND user_task.user_id = :u
                WHERE task.quest_id = :q AND task.is_active = 1
                ORDER BY task.sort_order ASC, task.id ASC",
            [':u' => $attempt->user_id, ':q' => $attempt->quest_id]
        )->queryAll();

        $payload['score'] = (float)$attempt->score;
        $payload['content'] = [
            'questName' => $attempt->quest->name,
            'tasksCompleted' => (int)$attempt->tasks_completed,
            'tasksTotal' => (int)$attempt->tasks_total,
            'tasks' => array_map(function ($r) {
                return [
                    'title' => (string)$r['title'],
                    'prompt' => strip_tags((string)$r['body']),
                    'type' => (string)$r['type'],
                    'response' => $r['response_text'] === null
                        ? null
                        : (string)$r['response_text'],
                    'completed' => (bool)$r['is_complete'],
                ];
            }, $rows),
        ];

        return $payload;
    }

    /** Return [HTTP status, short diagnostic]; status 0 means delivery failed. */
    public static function post(array $payload)
    {
        if (!self::isConfigured()) {
            return [0, 'Hook is not configured'];
        }

        if (!function_exists('curl_init')) {
            return [0, 'cURL extension is unavailable'];
        }

        // A fresh delivery ID prevents retries from sharing a signature.
        $payload['deliveryId'] = bin2hex(random_bytes(8));
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($body === false) {
            return [422, 'Hook JSON encoding failed'];
        }

        $timestamp = (string)time();
        $signature = HookSigner::sign(
            Yii::$app->params['gateway.hookSecret'],
            $timestamp,
            $body
        );

        $ch = curl_init(Yii::$app->params['gateway.hookUrl']);
        if ($ch === false) {
            return [0, 'Could not initialize hook request'];
        }

        $configured = curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Vii-Timestamp: ' . $timestamp,
                'X-Vii-Signature: ' . $signature,
            ],
        ]);

        if (!$configured) {
            return [0, 'Could not configure hook request'];
        }

        $response = curl_exec($ch);
        $status = $response === false
            ? 0
            : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $response === false
            ? 'cURL error ' . curl_errno($ch)
            : 'HTTP ' . $status;

        return [$status, $error];
    }

    /** Deliver a pending finished hook and save its delivery status. */
    public static function sendFinished(QuestAttempt $attempt)
    {
        if (!self::isConfigured()) {
            return false;
        }

        return QuestAttempt::getDb()->transaction(function ($db) use ($attempt) {
            $id = $db->createCommand(
                'SELECT id FROM `quest_attempt` WHERE id = :id FOR UPDATE',
                [':id' => $attempt->id]
            )->queryScalar();

            if ($id === false || !$attempt->refresh() ||
                $attempt->hook_status !== QuestAttempt::HOOK_PENDING) {
                return false;
            }

            if ($attempt->next_retry_at &&
                strtotime($attempt->next_retry_at) > time()) {
                return false;
            }

            list($status, $error) = self::post(
                self::buildPayload($attempt, 'attempt.finished')
            );
            $attempt->hook_tries = (int)$attempt->hook_tries + 1;

            if ($status >= 200 && $status < 300) {
                $attempt->hook_status = QuestAttempt::HOOK_CONFIRMED;
                $attempt->next_retry_at = null;
                $attempt->last_hook_error = null;
            } elseif (AttemptRules::isRetryable($status) &&
                $attempt->hook_tries < AttemptRules::MAX_HOOK_TRIES) {
                $attempt->next_retry_at = date(
                    'Y-m-d H:i:s',
                    time() + AttemptRules::backoffSeconds($attempt->hook_tries)
                );
                $attempt->last_hook_error = $error;

                Yii::warning(
                    "Gateway hook for attempt {$attempt->id} not delivered ({$error}); retry scheduled.",
                    __METHOD__
                );
            } else {
                $attempt->hook_status = QuestAttempt::HOOK_FAILED;
                $attempt->next_retry_at = null;
                $attempt->last_hook_error = $error;

                Yii::error(
                    "Gateway hook for attempt {$attempt->id} failed ({$error}) after {$attempt->hook_tries} tries.",
                    __METHOD__
                );
            }

            if (!$attempt->save(false, [
                'hook_status',
                'hook_tries',
                'next_retry_at',
                'last_hook_error',
                'updated_at',
            ])) {
                throw new \RuntimeException('Could not save hook delivery status.');
            }

            return $attempt->hook_status === QuestAttempt::HOOK_CONFIRMED;
        });
    }

    /** Send a best-effort progress notification for the first attempt only. */
    public static function sendStarted(QuestAttempt $attempt)
    {
        if (!self::isConfigured()) {
            return false;
        }

        return QuestAttempt::getDb()->transaction(function ($db) use ($attempt) {
            $id = $db->createCommand(
                'SELECT id FROM `quest_attempt` WHERE id = :id FOR UPDATE',
                [':id' => $attempt->id]
            )->queryScalar();

            if ($id === false || !$attempt->refresh()) {
                return false;
            }

            if (!$attempt->launch_ref ||
                (int)$attempt->attempt_number !== 1 ||
                $attempt->progress_sent ||
                $attempt->status !== QuestAttempt::STATUS_OPEN) {
                return false;
            }

            list($status) = self::post(
                self::buildPayload($attempt, 'attempt.started')
            );

            if ($status < 200 || $status >= 300) {
                return false;
            }

            $attempt->progress_sent = 1;
            if (!$attempt->save(false, ['progress_sent', 'updated_at'])) {
                throw new \RuntimeException('Could not save started hook status.');
            }

            return true;
        });
    }
}