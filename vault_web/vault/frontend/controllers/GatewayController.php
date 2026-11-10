<?php

namespace frontend\controllers;

use Yii;
use yii\filters\VerbFilter;
use yii\web\Response;
use common\models\Quest;

/**
 * Read-only endpoints for the Canvas gateway.
 * Every request requires the configured X-Gateway-Key
 */
class GatewayController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => ['experiences' => ['GET']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $expected = (string)(Yii::$app->params['gateway.apiKey'] ?? '');
        $given = (string)Yii::$app->request->headers->get('X-Gateway-Key', '');

        if (strlen($expected) < 32) {
            Yii::$app->response->statusCode = 503;
            Yii::$app->response->data = [
                'returnCode' => 1,
                'returnCodeDescription' => 'Gateway access is not configured.',
                'data' => [],
            ];
            return false;
        }

        if (!hash_equals($expected, $given)) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = [
                'returnCode' => 1,
                'returnCodeDescription' => 'Invalid gateway key.',
                'data' => [],
            ];
            return false;
        }

        return parent::beforeAction($action);
    }

    public function actionExperiences()
    {
        $quests = Quest::find()
            ->select(['id', 'name', 'description'])
            ->where(['is_active' => 1])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        $experiences = array_map(function ($q) {
            return [
                'id' => (int)$q['id'],
                'name' => (string)$q['name'],
                'description' => (string)$q['description'],
            ];
        }, $quests);

        return [
            'returnCode' => 0,
            'returnCodeDescription' => 'success',
            'data' => ['experiences' => $experiences],
        ];
    }
}