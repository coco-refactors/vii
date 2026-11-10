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
}