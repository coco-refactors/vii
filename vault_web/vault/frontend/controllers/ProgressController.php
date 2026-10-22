<?php

namespace frontend\controllers;

use common\models\Quest;
use common\models\Task;
use common\models\UserTask;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/** Self-reported completion, using the existing user_task storage. */
class ProgressController extends \yii\rest\Controller
{
    public function init()
    {
        parent::init();
        \Yii::$app->user->enableSession = false;
        \Yii::$app->user->enableAutoLogin = false;
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => \frontend\components\ProgressBearerAuth::class,
        ];
        return $behaviors;
    }

    protected function verbs()
    {
        return ['quest' => ['GET', 'POST']];
    }

    public function actionQuest($quest_id)
    {
        if (!ctype_digit((string) $quest_id) || (int) $quest_id < 1) {
            throw new BadRequestHttpException('quest_id must be a positive integer.');
        }
        if (!Quest::findOne(['id' => $quest_id, 'is_active' => 1])) {
            throw new NotFoundHttpException('Active quest not found.');
        }
        $userId = \Yii::$app->user->id;
        if (!$userId) {
            throw new UnauthorizedHttpException();
        }
        if (\Yii::$app->request->isPost) {
            $body = \Yii::$app->request->getBodyParams();
            if (!isset($body['task_id'], $body['is_complete']) ||
                !is_int($body['task_id']) || !is_bool($body['is_complete']) ||
                array_diff(array_keys($body), ['task_id', 'is_complete'])) {
                throw new BadRequestHttpException('Expected only task_id (integer) and is_complete (boolean).');
            }
            // Serialize writes from this endpoint without changing the legacy schema.
            \Yii::$app->db->transaction(function () use ($quest_id, $userId, $body) {
                \Yii::$app->db->createCommand('SELECT id FROM {{%quest}} WHERE id = :id FOR UPDATE',
                    [':id' => $quest_id])->queryScalar();
                if (!Task::findOne(['id' => $body['task_id'], 'quest_id' => $quest_id, 'is_active' => 1])) {
                    throw new NotFoundHttpException('Active task does not belong to this quest.');
                }
                $records = UserTask::findAll(['user_id' => $userId, 'task_id' => $body['task_id']]);
                if (!$records) {
                    $record = new UserTask();
                    $record->user_id = $userId;
                    $record->task_id = $body['task_id'];
                    $records = [$record];
                }
                foreach ($records as $record) {
                    $record->is_complete = $body['is_complete'] ? 1 : 0;
                    if (!$record->save()) {
                        throw new \yii\web\ServerErrorHttpException('Could not save progress.');
                    }
                }
            });
        }
        $taskIds = Task::find()->select('id')->where(['quest_id' => $quest_id, 'is_active' => 1])->column();
        $completed = (int) UserTask::find()->where([
            'user_id' => $userId, 'task_id' => $taskIds, 'is_complete' => 1,
        ])->select('task_id')->distinct()->count();
        $total = count($taskIds);
        return ['data' => [
            'user_id' => (int) $userId, 'quest_id' => (int) $quest_id,
            'completed_tasks' => $completed, 'total_tasks' => $total,
            'is_complete' => $total > 0 && $completed === $total,
            'progress_percent' => $total ? round(100 * $completed / $total, 2) : 0,
        ]];
    }
}
