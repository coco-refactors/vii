<?php

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;

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
}