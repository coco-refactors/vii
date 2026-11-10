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
}