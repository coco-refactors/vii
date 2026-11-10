<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;
use common\components\GatewayHook;

/**
 * Resend pending finished-attempt hooks whose retry time has arrived.
 */
class GatewayHookController extends Controller
{
    public function actionResend($limit = 50)
    {
        $limit = filter_var(
            $limit,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($limit === false) {
            $this->stderr("The resend limit must be a positive integer.\n");
            return ExitCode::USAGE;
        }

        if (!GatewayHook::isConfigured()) {
            $this->stderr(
                "GATEWAY_HOOK_URL / GATEWAY_HOOK_SECRET are missing or invalid.\n"
            );
            return ExitCode::CONFIG;
        }

        list($due, $confirmed) = GatewayHook::resendDue($limit);

        $this->stdout(
            date('c') . " resend: {$due} due, {$confirmed} confirmed\n"
        );

        return ExitCode::OK;
    }
}