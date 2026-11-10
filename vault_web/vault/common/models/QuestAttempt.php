<?php

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;

use common\components\AttemptRules;

/**
 * One user's attempt at a quest.
 */
class QuestAttempt extends ActiveRecord
{
    const STATUS_OPEN = 'open';
    const STATUS_FINISHED = 'finished';
    const STATUS_ABANDONED = 'abandoned';

    const HOOK_NONE = 'none';
    const HOOK_PENDING = 'pending';
    const HOOK_CONFIRMED = 'confirmed';
    const HOOK_FAILED = 'failed';

    const LAUNCH_REF_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    public static function tableName()
    {
        return 'quest_attempt';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::className(),
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
                ],
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['user_id', 'quest_id', 'attempt_number'], 'required'],
            [['user_id', 'quest_id', 'attempt_number', 'tasks_completed', 'tasks_total', 'progress_sent', 'hook_tries'], 'integer'],
            [['score'], 'number', 'min' => 0, 'max' => 1],
            [['launch_ref'], 'match', 'pattern' => self::LAUNCH_REF_PATTERN],
            [['status'], 'in', 'range' => [self::STATUS_OPEN, self::STATUS_FINISHED, self::STATUS_ABANDONED]],
            [['hook_status'], 'in', 'range' => [self::HOOK_NONE, self::HOOK_PENDING, self::HOOK_CONFIRMED, self::HOOK_FAILED]],
            [['last_hook_error'], 'string', 'max' => 255],
            [['started_at', 'finished_at', 'next_retry_at', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'user_id']);
    }

    public function getQuest()
    {
        return $this->hasOne(Quest::className(), ['id' => 'quest_id']);
    }

    /** Find the user's latest open attempt for this quest. */
    public static function findOpen($userId, $questId)
    {
        return self::find()
            ->where([
                'user_id' => $userId,
                'quest_id' => $questId,
                'status' => self::STATUS_OPEN,
            ])
            ->orderBy(['attempt_number' => SORT_DESC])
            ->one();
    }

    /** State shared with the gateway and app, without the score. */
    public function toStateArray()
    {
        return [
            'id' => (int)$this->id,
            'number' => (int)$this->attempt_number,
            'status' => $this->status,
            'startedAt' => self::iso($this->started_at),
            'finishedAt' => self::iso($this->finished_at),
        ];
    }

    public static function iso($datetime)
    {
        return $datetime ? date('c', strtotime($datetime)) : null;
    }

    /** Reset active tasks for this user and clear their previous answers. */
    public static function resetTasks($userId, $questId)
    {
        $activeTaskIds = Task::find()
            ->select('id')
            ->where(['quest_id' => $questId, 'is_active' => 1])
            ->column();

        if (!$activeTaskIds) {
            return 0;
        }

        return UserTask::updateAll(
            [
                'is_complete' => 0,
                'response_text' => null,
                'updated_at' => new Expression('NOW()'),
            ],
            ['user_id' => $userId, 'task_id' => $activeTaskIds]
        );
    }

    /** Return [completed, total] active task counts for this user's quest. */
    public static function taskCounts($userId, $questId)
    {
        $row = \Yii::$app->db->createCommand(
            "SELECT
                (SELECT COUNT(*) FROM task
                    WHERE task.quest_id = :q1 AND task.is_active = 1) AS total,
                (SELECT COUNT(*) FROM user_task
                    JOIN task ON task.id = user_task.task_id
                    WHERE user_task.user_id = :u
                    AND task.quest_id = :q2 AND task.is_active = 1
                    AND user_task.is_complete = 1) AS completed",
            [':q1' => $questId, ':q2' => $questId, ':u' => $userId]
        )->queryOne();

        return [(int)$row['completed'], (int)$row['total']];
    }

    /**
     * Resume an open attempt or create the next numbered attempt.
     * Returns [QuestAttempt $attempt, bool $isNew].
     */
    public static function start($userId, $questId, $launchRef = null, $resetTasks = true)
    {
        return self::getDb()->transaction(function ($db) use (
            $userId, $questId, $launchRef, $resetTasks
        ) {
            $user = $db->createCommand(
                'SELECT id FROM {{%user}} WHERE id = :id FOR UPDATE',
                [':id' => $userId]
            )->queryScalar();

            if ($user === false) {
                throw new \RuntimeException('Attempt user was not found.');
            }

            $open = self::findOpen($userId, $questId);
            if ($open !== null) {
                if ($launchRef !== null && $open->launch_ref !== $launchRef) {
                    $open->launch_ref = $launchRef;
                    if (!$open->save(false, ['launch_ref', 'updated_at'])) {
                        throw new \RuntimeException('Could not update attempt launch reference.');
                    }
                }

                return [$open, false];
            }

            $previous = (int)self::find()
                ->where(['user_id' => $userId, 'quest_id' => $questId])
                ->max('attempt_number');

            list($completed) = self::taskCounts($userId, $questId);

            $attempt = new self();
            $attempt->user_id = $userId;
            $attempt->quest_id = $questId;
            $attempt->attempt_number = $previous + 1;
            $attempt->launch_ref = $launchRef;
            $attempt->status = self::STATUS_OPEN;
            $attempt->started_at = date('Y-m-d H:i:s');

            if (!$attempt->save(false)) {
                throw new \RuntimeException('Could not create attempt.');
            }

            if ($resetTasks && ($previous > 0 || $completed > 0)) {
                self::resetTasks($userId, $questId);
            }

            return [$attempt, true];
        });
    }

    /**
     * Preserve the open attempt as abandoned, reset task progress,
     * and create the next attempt with the same launch reference.
     */
    public static function restart($userId, $questId)
    {
        return self::getDb()->transaction(function ($db) use ($userId, $questId) {
            $user = $db->createCommand(
                'SELECT id FROM {{%user}} WHERE id = :id FOR UPDATE',
                [':id' => $userId]
            )->queryScalar();

            if ($user === false) {
                throw new \RuntimeException('Attempt user was not found.');
            }

            $open = self::findOpen($userId, $questId);
            $launchRef = null;

            if ($open !== null) {
                $launchRef = $open->launch_ref;
                $open->status = self::STATUS_ABANDONED;

                if (!$open->save(false, ['status', 'updated_at'])) {
                    throw new \RuntimeException('Could not abandon previous attempt.');
                }
            }

            self::resetTasks($userId, $questId);

            return self::start($userId, $questId, $launchRef, false);
        });
    }

    /** Save the finished attempt's task counts and completion score. */
    public function finish()
    {
        return self::getDb()->transaction(function ($db) {
            $user = $db->createCommand(
                'SELECT id FROM {{%user}} WHERE id = :id FOR UPDATE',
                [':id' => $this->user_id]
            )->queryScalar();

            if ($user === false || !$this->refresh()) {
                throw new \RuntimeException('Attempt or user was not found.');
            }

            if ($this->status === self::STATUS_FINISHED) {
                return $this;
            }

            if ($this->status !== self::STATUS_OPEN) {
                throw new \RuntimeException('Only an open attempt can be finished.');
            }

            list($completed, $total) = self::taskCounts(
                $this->user_id,
                $this->quest_id
            );

            $this->tasks_completed = $completed;
            $this->tasks_total = $total;
            $this->score = number_format(
                AttemptRules::shareOf($completed, $total),
                4,
                '.',
                ''
            );
            $this->status = self::STATUS_FINISHED;
            $this->finished_at = date('Y-m-d H:i:s');

            if ($this->launch_ref) {
                $this->hook_status = self::HOOK_PENDING;
                $this->next_retry_at = $this->finished_at;
            }

            if (!$this->save(false)) {
                throw new \RuntimeException('Could not finish attempt.');
            }

            return $this;
        });
    }
}