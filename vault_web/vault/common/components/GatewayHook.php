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
}