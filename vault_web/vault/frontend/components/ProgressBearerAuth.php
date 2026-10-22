<?php
namespace frontend\components;

class ProgressBearerAuth extends \yii\filters\auth\HttpBearerAuth
{
    public function authenticate($user, $request, $response)
    {
        $header = $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            $oauth = \common\models\Oauth::findOne(['access_token' => $matches[1], 'state' => 'active']);
            $identity = $oauth ? \common\models\User::findIdentity($oauth->user_id) : null;
            if ($identity) {
                $user->switchIdentity($identity);
                return $identity;
            }
        }
        $this->challenge($response);
        $this->handleFailure($response);
        return null;
    }
}
