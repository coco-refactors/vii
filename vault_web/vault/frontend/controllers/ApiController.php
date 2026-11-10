<?php

namespace frontend\controllers;

use yii\filters\auth\HttpBasicAuth;
use common\models\User;
use common\models\Oauth;
use common\models\UserType;
use common\models\Quest;
use common\models\UserTask;
use yii\helpers\ArrayHelper;
use common\models\TaskMedia;
use common\models\Media;
use common\models\TaskMeta;
use common\models\ArTarget;
use common\models\UserEvent;
use common\models\RequiredTask;
use common\models\QuestAttempt;
use common\components\GatewayHook;

class ApiController extends \yii\web\Controller
{
	public $enableCsrfValidation = false; // without this you'll get, 'Unable to verify your data submission.'
	
	/**
	 * This set's the response format for ALL actions in this controller to be JSON.
	 * 
	 * {@inheritDoc}
	 * @see \yii\web\Controller::beforeAction()
	 */
	public function beforeAction($action){
		\Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
		return parent::beforeAction($action);
	}
	
	public function behaviors() {
		return [
				'corsFilter' => [
					'class' => \yii\filters\Cors::class,
					'cors' => [
						'Origin' => ['https://editor.swagger.io'],
						'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
						'Access-Control-Request-Headers' => ['*'],
						'Access-Control-Allow-Credentials' => true,
						'Access-Control-Max-Age' => 86400,
					],
				],
				'authenticator'=>[
						'class' => HttpBasicAuth::className(),
						'except' => ['authenticate','reset-lost-password', 'sign-up'],
				],
		];
	}
	
	/**
	 * Creates and saves a new Oauth record. On failure, logs the validation errors via
	 * Yii::error() and returns null — callers decide whether that's fatal for their flow.
	 *
	 * @param int $user_id
	 * @param string $source e.g. "basic", "guest", "Apple", "Google"
	 * @param string $uid
	 * @param string|null $access_token pass null to generate a new random token
	 * @param string $expires_in
	 * @return Oauth|null the saved record (re-fetched, to match existing JSON output), or null on failure
	 */
	private function createOauthEntry($user_id, $source, $uid, $access_token = null, $expires_in = "")
	{
		$oauth = new Oauth();
		$oauth->access_token = $access_token !== null ? $access_token : \Yii::$app->security->generateRandomString();
		$oauth->user_id = $user_id;
		$oauth->source = $source;
		$oauth->uid = $uid;
		$oauth->state = "active";
		$oauth->token_type = "Bearer";
		$oauth->expires_in = $expires_in;
		if(!$oauth->save()){
			\Yii::error("Failed to save Oauth record for user_id={$user_id}, source={$source}: " . json_encode($oauth->getErrors()), __METHOD__);
			return null;
		}

		//Fix for JSON output of a new record
		return Oauth::findOne($oauth->id);
	}

	public function actionAuthenticate()
	{
			$username = \Yii::$app->request->post("username");
			$password = \Yii::$app->request->post("password");
			$type = \Yii::$app->request->post("type"); //Should be basic or oauth.
			$access_token = \Yii::$app->request->post("access_token");
				
			if($type == "basic"){
				//Find the user by the username
				$user = User::findByUsername($username);
				if($user == NULL){
					return [
							'returnCode'=>1, 
							'returnCodeDescription'=>"User not found.", 
							'data'=>[], 
						];
				}
				
				//Validate their password is correct
				if(!$user->validatePassword($password)){
					return [
							'returnCode'=>1, 
							'returnCodeDescription'=>"Incorrect password.", 
							'data'=>[], 
						];
					}
					
				//Check if this user already has an oauth entry
				$oauth = Oauth::findOne(["user_id"=>$user->id, "source"=>"basic"]);
				if($oauth == NULL){
					$oauth = $this->createOauthEntry($user->id, "basic", (string)$user->id);
				}

				UserEvent::saveUserEvent($user->id, UserEvent::TYPE_LOGIN, "Basic log in");
			}
			else if ($type == "guest")	{
				//Check if there is an existing user with this email ($username should be a UDID)
				$user = User::findByUsername($username);
				if($user == NULL){
					//No user account at all, create a new one.
					$user = new User();
					$user->user_type_id = UserType::USER;
					$user->username = $username; //Username is the email address.
					$user->email = $username."@example.com";
					//User will have to reset their password if they want to use standard (basic) authentication.
					$user->setPassword(\Yii::$app->security->generateRandomString(16));//Generate a random string for the password and hash it!
					$user->status = User::STATUS_ACTIVE;
					if(!$user->save()){
						//Abort, user could not be saved.
						return [
								'returnCode'=>1, 
								'returnCodeDescription'=>"User could not be created.", 
								'data'=>[], 
							];
					}
				}

				//Check if this user already has an oauth entry
				$oauth = Oauth::findOne(["user_id"=>$user->id, "source"=>"guest"]);
				if($oauth == NULL){
					$oauth = $this->createOauthEntry($user->id, "guest", (string)$user->id);
				}

				UserEvent::saveUserEvent($user->id, UserEvent::TYPE_GUEST_LOGIN, "Guest log in");
			}
			else if ($type == "Apple")	{
				//Check if there is an existing user with this email ($username should be an email address)
				// Check for email in JWT in $access_token first 
				if($access_token != NULL)	{
					// Note: this decodes the JWT payload but does not verify its signature against
					// Apple's published keys (https://appleid.apple.com/auth/keys).
					$jwt = json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $access_token)[1]))));
					if($jwt == NULL){
						return [
								'returnCode'=>1, 
								'returnCodeDescription'=>"JWT not found.", 
								'data'=>[], 
							];
					}
					$username = $jwt->email;

					if($username == NULL){
						return [
								'returnCode'=>1, 
								'returnCodeDescription'=>"Email not found.", 
								'data'=>[], 
							];
					}
				}
				$user = User::findByUsername($username);
				if($user == NULL){
					//No user account at all, create a new one.
					$user = new User();
					$user->user_type_id = UserType::USER;
					$user->username = $username; //Username is the email address.
					$user->email = $username;
					//User will have to reset their password if they want to use standard (basic) authentication.
					$user->setPassword(\Yii::$app->security->generateRandomString(16));//Generate a random string for the password and hash it!
					$user->status = User::STATUS_ACTIVE;
					if(!$user->save()){
						//Abort, user could not be saved.
						return [
								'returnCode'=>1, 
								'returnCodeDescription'=>"User could not be created.", 
								'data'=>[], 
							];
					}
				}

				//Check if there is an existing oauth entry with this user ($uid should be $user->id)
				$oauth = Oauth::findOne(["user_id"=>$user->id, "source"=>"Apple"]);
				if($oauth == NULL){
					$oauth = $this->createOauthEntry($user->id, "Apple", (string)$user->id);

					UserEvent::saveUserEvent($user->id, UserEvent::TYPE_APPLE_SIGN_UP, "Apple sign up");
				}
				
				UserEvent::saveUserEvent($user->id, UserEvent::TYPE_APPLE_LOGIN, "Apple log in");
			}
			else if($type == "Google"){
				//Check the ID Token (aka $access_token) and validate
				$googleUser = self::checkGoogleAccessToken($access_token);
				if($googleUser == false){
					//Abort, ID Token was not valid
					return [
							'returnCode'=>1, 
							'returnCodeDescription'=>"Google ID token not valid.", 
							'data'=>[], 
						];
				}else{
					//$googleUser should be an array of the Google user object
					$uid = $googleUser["sub"];
					//Check if this user already has an oauth entry by looking up the access_token
					$oauth = Oauth::findOne(["access_token"=>$access_token, "uid"=>$uid, "source"=>"Google"]);
					if($oauth == NULL){
						//No existing oauth login
						//Double check if there is an existing user with this email ($username should be an email address)
						$user = User::findByUsername($username);
						if($user == NULL){
							//No user account at all, create a new one.
							$user = new User();
							$user->user_type_id = UserType::USER;
							$user->username = $username; //Username is the email address.
							$user->email = $username;
							//User will have to reset their password if they want to use standard (basic) authentication.
							$user->setPassword(\Yii::$app->security->generateRandomString(16));//Generate a random string for the password and hash it!
							$user->status = User::STATUS_ACTIVE;
							if(!$user->save()){
								//Abort, user could not be saved.
								return [
										'returnCode'=>1, 
										'returnCodeDescription'=>"User could not be created.", 
										'data'=>[], 
									];
							}
							
							$oauth = $this->createOauthEntry($user->id, "Google", $uid, $access_token, (string)$googleUser["exp"]);

							UserEvent::saveUserEvent($user->id, UserEvent::TYPE_GOOGLE_SIGN_UP, "Google sign up");
						}else{
							//A user account exists BUT no oauth entry yet.
							$oauth = $this->createOauthEntry($user->id, "Google", $uid, $access_token, (string)$googleUser["exp"]);

							UserEvent::saveUserEvent($user->id, UserEvent::TYPE_GOOGLE_LOGIN, "Google log in");
						}
					}else{
						//There is an existing login with an Oauth entry that matches Google.
						$user = $oauth->user;
						UserEvent::saveUserEvent($user->id, UserEvent::TYPE_GOOGLE_LOGIN, "Google log in");
					}
				}
			}else{
				//Find the user by the access_token
				$user = User::findIdentityByAccessToken($username);
				if($user == NULL){
					return [
							'returnCode'=>1, 
							'returnCodeDescription'=>"User not found.", 
							'data'=>[], 
						];
				}
				
				$oauth = Oauth::findOne(["access_token"=>$access_token]);
				UserEvent::saveUserEvent($user->id, UserEvent::TYPE_LOGIN, "Basic log in");
			}
				
			//Has successfully authenticated and returns the access_token
			//The access_token is either the Google generated token OR the token generated after a successful Basic login.
			return [
					'returnCode'=>0, 
					'returnCodeDescription'=>"success", 
					'data'=>[
						"user"=>$user,
						"oauth"=>$oauth
					], 
			];
	}
	
	public function actionSignUp(){
			$email = \Yii::$app->request->post("email");
			$password1 = \Yii::$app->request->post("password1");
			$password2 = \Yii::$app->request->post("password2");
			
			$user = User::findByUsername($email);
			if($user != NULL){
				return [
						'returnCode'=>1, 
						'returnCodeDescription'=>"User already exists.", 
						'data'=>[], 
					];
			}
			
			if($password1 != $password2){
				return [
						'returnCode'=>1, 
						'returnCodeDescription'=>"Passwords don't match.", 
						'data'=>[], 
					];
			}
			
			//Create the new user account.
			$user = new User();
			$user->user_type_id = UserType::USER;
			$user->username = $email;
			$user->email = $email;
			$user->setPassword($password1);
			$user->status = User::STATUS_ACTIVE;
			if(!$user->save()){
				return [
						'returnCode'=>1, 
						'returnCodeDescription'=>"Could not create new user.", 
						'data'=>[], 
					];
			}
			
			$oauth = $this->createOauthEntry($user->id, "basic", (string)$user->id);
			if($oauth == NULL){
				return [
							'returnCode'=>1,
							'returnCodeDescription'=>"Could not save oauth entry for new user.",
							'data'=>[],
						];
			}

			UserEvent::saveUserEvent($user->id, UserEvent::TYPE_SIGN_UP, "Basic sign up");

			return [
					'returnCode'=>0, 
					'returnCodeDescription'=>"success", 
					'data'=>[
						'user'=>$user,
						'oauth'=>$oauth
					], 
			];
	}

	public function actionSaveUserTask()
	{
			$id = \Yii::$app->request->post("id");
			
			$userTask = UserTask::findOne($id);
			if($userTask == NULL){
				$userTask = new UserTask();
			}
			
			if ($userTask->load(\Yii::$app->request->post()) && $userTask->save()) {
				UserEvent::saveUserEvent($userTask->user_id, UserEvent::TYPE_SAVE_USER_TASK, "User task was saved/updated. user_task->id = {$userTask->id}");
				self::finishAttemptsFor($userTask->user_id, [$userTask->task_id]);
				return [
						'returnCode'=>0, 
						'returnCodeDescription'=>"Success", 
						'data'=>[
							"userTask"=>$userTask
						], 
				];
			}
			

			return [
					'returnCode'=>1, 
					'returnCodeDescription'=>"Error: Could not save user task", 
					'data'=>[], 
			];
	}

	public function actionGetQuests()
	{
		$user_id = \Yii::$app->user->id; //Get the user_id from the session user.
		$quests = Quest::find()
							->with("media")
							->with("pagingImage")
							->with(["questUserAccesses" => function (\yii\db\ActiveQuery $query) {
								$query->andWhere(['user_id'=>\Yii::$app->user->id]);
							}])
							->join("JOIN","task","task.quest_id = quest.id")
							->join("LEFT JOIN","user_task","user_task.task_id = task.id AND user_task.user_id = :user_id", [":user_id" => $user_id])
							->asArray(true) //Return results set as an array for easy JSON serialization
							->all();

		foreach($quests as $i => $questArray){
			$quests[$i]["is_complete"] = Quest::isComplete($questArray["id"], $user_id);
		}

		UserEvent::saveUserEvent($user_id, UserEvent::TYPE_GET_TASK_LISTS, "Get task lists with user data.");
		return [
				'returnCode'=>0, 
				'returnCodeDescription'=>"success", 
				'data'=>["quests"=>$quests], 
		];
	}

	/**
	 * getTaskJSON()
	 * 
	 * @var $task Array() = the task array
	 * @var $user_id Int = the user's id
	 * 
	 * return the task array with all the associated values.
	 */
	private static function getTaskJSON($task, $user_id){
		$userTask = UserTask::findOne(["task_id"=>$task["id"], "user_id"=>$user_id]);
		if($userTask == NULL){
			$userTask = new UserTask();
			$userTask->task_id = $task["id"];
			$userTask->user_id = $user_id;
			$userTask->is_complete = 0;
			if(!$userTask->save()){
			}
		}
		$task["userTask"] = $userTask->toArray();
		
		$task["media"] = []; //Init the media array
		//Get the Task media objects
		$taskMedia = TaskMedia::findAll(["task_id"=>$task["id"]]);
		foreach($taskMedia as $tm){
			$media = $tm->media;
			$task["media"][] = $media->toArray();
		}
		
		$task["taskMetas"] = []; //Init the taskMetas array
		//Get the TaskMeta objects
		$taskMetas = TaskMeta::findAll(["task_id"=>$task["id"]]);
		foreach($taskMetas as $tm){
			$taskMeta = $tm->toArray();
			//If the taskMeta has key = AR_TARGET_ID
			//Download include the media object.
			if($taskMeta["key"] == TaskMeta::KEY_AR_TARGET_ID){
				$mediaId = intval($taskMeta["value"]);
				$media = Media::findOne($mediaId);
				if($media != NULL){
					$taskMeta["media"] = $media->toArray();
				}
			}else{
				$taskMeta["media"] = NULL;
			}
			$task["taskMetas"][] = $taskMeta;
		}
		
		$task["arTargets"] = []; //Init the task ARTargets array
		//Get the TaskMeta objects
		$arTargets = ArTarget::findAll(["task_id"=>$task["id"]]);
		foreach($arTargets as $arTarget){
			$arTarget = $arTarget->toArray();
			
			$mediaId = $arTarget["media_id"];
			$media = Media::findOne($mediaId);
			if($media != NULL){
				$arTarget["media"] = $media->toArray();
			}else{
				$arTarget["media"] = NULL;
			}

			if($arTarget["overlay_media_id"] != NULL){
				$overlayMedia = Media::findOne($arTarget["overlay_media_id"]);
				if($overlayMedia != NULL){
					$arTarget["overlayMedia"] = $overlayMedia->toArray();
				}else{
					$arTarget["overlayMedia"] = NULL;
				}
			}else{
				$arTarget["overlayMedia"] = NULL;
			}

			if($arTarget["audio_media_id"] != NULL){
				$audioMedia = Media::findOne($arTarget["audio_media_id"]);
				if($audioMedia != NULL){
					$arTarget["audioMedia"] = $audioMedia->toArray();
				}else{
					$arTarget["audioMedia"] = NULL;
				}
			}else{
				$arTarget["audioMedia"] = NULL;
			}
			
			$task["arTargets"][] = $arTarget;
		}

		//Get the required tasks for this Task
		$task["requiredTasks"] = RequiredTask::find()->where(["parent_task_id"=>$task["id"]])->asArray(true)->all();
		
		//Get the correct response image and include it if it exists.
		$correctResponseImage = Media::find()->where(["id"=>$task["correct_response_image_id"]])->asArray()->one();
		$task["correctResponseImage"] = $correctResponseImage;
		//Get the incorrect response image and include it if it exists.
		$incorrectResponseImage = Media::find()->where(["id"=>$task["incorrect_response_image_id"]])->asArray()->one();
		$task["incorrectResponseImage"] = $incorrectResponseImage;
		
		//Set the default response text if they aren't already set
		if($task["correct_response_text"] == ""){
			$task["correct_response_text"] = "Great work!";
		}
		if($task["incorrect_response_text"] == ""){
			$task["incorrect_response_text"] = "Try again!";
		}

		return $task;
	}

	public function checkGoogleAccessToken($accessToken){
		$client = new \Google_Client();
		$client->setAuthConfig('../config/client_secret.json');

		$payload = $client->verifyIdToken($accessToken);
		if ($payload) {
			return $payload;
		}
		return false;
	}

	public function actionResetQuest(){
		$quest_id = \Yii::$app->request->post("quest_id");
		if($quest_id == NULL || $quest_id == ""){
			return [
				'returnCode'=>1, 
				'returnCodeDescription'=>"Invalid parameter: quest_id", 
				'data'=>[], 
			];
		}

		$user_id = \Yii::$app->user->id; //Get the user_id from the session user.

		$quest = Quest::findOne($quest_id);
		if($quest == NULL){
			return [
				'returnCode'=>1, 
				'returnCodeDescription'=>"Error: Quest was not found.", 
				'data'=>[], 
			];	
		}

		// Preserve the previous attempt and reset progress for a new one.
        QuestAttempt::restart($user_id, $quest->id);

		$questArray = Quest::find()
								->where(["quest.id"=>$quest_id])
								->with(["tasks"=> function ($query) {
									$query->andWhere(['task.is_active' => 1])
										->orderBy('task.sort_order ASC');

								}]) //Use eager-loading to include the related tasks
								->asArray(true)
								->one();
		foreach($questArray["tasks"] as $i => $task){
			$questArray["tasks"][$i] = self::getTaskJSON($task, $user_id);
		}

		$questArray["is_complete"] = Quest::isComplete($questArray["id"], $user_id);

		return [
			'returnCode'=>0, 
			'returnCodeDescription'=>"success", 
			'data'=>["quest"=>$questArray], 
		];
	}

	public function actionDownloadQuest(){
		$quest_id = \Yii::$app->request->post("quest_id");
		$code = \Yii::$app->request->post("code");

		if(($quest_id == NULL || $quest_id == "") && ($code == NULL || $code == "")){

			return [
				'returnCode'=>1, 
				'returnCodeDescription'=>"Invalid parameters: quest_id OR code must be valid", 
				'data'=>[], 
			];
		}

		$user_id = \Yii::$app->user->id; //Get the user_id from the session user.

		$quest = NULL;
		if($quest_id != NULL && $quest_id != ""){
			$quest = Quest::findOne($quest_id);
			if($quest == NULL){
				return [
					'returnCode'=>1, 
					'returnCodeDescription'=>"Error: Quest was not found.", 
					'data'=>[], 
				];	
			}
		}else if($code != NULL && $code != ""){
			$quest = Quest::find()->where(["code"=>$code])->one();
			if($quest == NULL){
				return [
					'returnCode'=>1, 
					'returnCodeDescription'=>"Error: No quest found with the provided start code.", 
					'data'=>[], 
				];	
			}
		}

		$questArray = Quest::find();

		if($quest_id != NULL && $quest_id != ""){
			$questArray = $questArray->where(["quest.id"=>$quest_id]);
		}else if($code != NULL && $code != ""){
			$questArray = $questArray->where(["quest.code"=>$code]);
		}

		$questArray = $questArray->with(["tasks"=> function ($query) {
									$query->andWhere(['task.is_active' => 1])
										->orderBy('task.sort_order ASC');

								}]) //Use eager-loading to include the related tasks
								->asArray(true)
								->one();
		
		foreach($questArray["tasks"] as $i => $task){
			$questArray["tasks"][$i] = self::getTaskJSON($task, $user_id);
		}

		$questArray["is_complete"] = $quest->isComplete($quest->id, $user_id);

		return [
			'returnCode'=>0, 
			'returnCodeDescription'=>"success", 
			'data'=>["quest"=>$questArray], 
		];
	}

	public function actionSyncUserTasks(){
		$userTasks = \Yii::$app->request->post("userTasks");
		if($userTasks == NULL || $userTasks == ""){
			return [
				'returnCode'=>1, 
				'returnCodeDescription'=>"Invalid parameter: userTasks", 
				'data'=>[], 
			];
		}

		// Handle both single string (Swagger UI) and array (actual usage)
		if(!is_array($userTasks)){
			$userTasks = [$userTasks];
		}

		$tasksNotSaved = [];
		$savedTaskIds = [];
		foreach($userTasks as $utsq){
			$json = json_decode($utsq);
			$userTaskId = $json->id;
			$jsonUserTask = json_decode($json->json,true);
			$userTask = UserTask::findOne($jsonUserTask["id"]);
			if($userTask == NULL){
				$userTask = new UserTask();
			}
			if ($userTask->load(["FormName"=>$jsonUserTask], "FormName")) {
				if($userTask->created_at == "" || $userTask->created_at == "0000-00-00 00:00:00" || $userTask->created_at == null){
					$userTask->created_at = date("Y-m-d H:i:s");
				}
				$userTask->is_complete = intval($userTask->is_complete);
				if($userTask->save()){
					if ((int)$userTask->user_id === (int)\Yii::$app->user->id) {
						$savedTaskIds[] = $userTask->task_id;
					}
					UserEvent::saveUserEvent($userTask->user_id, UserEvent::TYPE_SAVE_USER_TASK, "User task was saved/updated. user_task->id = {$userTask->id}");
				}else{
					$tasksNotSaved[] = $userTask;
				}
			}else{
				$tasksNotSaved[] = $userTask;
			}
		}

		self::finishAttemptsFor(\Yii::$app->user->id, $savedTaskIds);

		return [
			'returnCode'=>0, 
			'returnCodeDescription'=>"Success", 
			'data'=>[
				"tasksNotSaved"=>$tasksNotSaved
			], 
		];
	}

	/**
     * Start or resume an attempt for the authenticated user.
     * Call before download-quest because a new attempt can reset progress.
     */
    public function actionStartAttempt()
    {
        if (!\Yii::$app->request->isPost) {
            throw new \yii\web\MethodNotAllowedHttpException('Use POST for this endpoint.');
        }

        $questId = filter_var(
            \Yii::$app->request->post('quest_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($questId === false) {
            return [
                'returnCode' => 1,
                'returnCodeDescription' => 'Invalid parameter: quest_id',
                'data' => [],
            ];
        }

        $launchRef = \Yii::$app->request->post('launch_ref');
        $launchRef = ($launchRef === null || $launchRef === '') ? null : $launchRef;

        if ($launchRef !== null && (
            !is_string($launchRef) ||
            !preg_match(QuestAttempt::LAUNCH_REF_PATTERN, $launchRef)
        )) {
            return [
                'returnCode' => 1,
                'returnCodeDescription' => 'Invalid parameter: launch_ref',
                'data' => [],
            ];
        }

        $quest = Quest::findOne(['id' => $questId, 'is_active' => 1]);
        if ($quest === null) {
            return [
                'returnCode' => 1,
                'returnCodeDescription' => 'Error: Quest was not found.',
                'data' => [],
            ];
        }

        list($attempt, $isNew) = QuestAttempt::start(
            \Yii::$app->user->id,
            $quest->id,
            $launchRef
        );

		if ($isNew) {
			GatewayHook::sendStarted($attempt);
		}

        return [
            'returnCode' => 0,
            'returnCodeDescription' => 'success',
            'data' => [
                'attempt' => $attempt->toStateArray(),
                'isNew' => $isNew,
            ],
        ];
    }

	 /** Check affected quests and deliver newly finished attempts. */
    private static function finishAttemptsFor($userId, $taskIds)
    {
        if ((int)$userId !== (int)\Yii::$app->user->id || empty($taskIds)) {
            return;
        }

        $questIds = \common\models\Task::find()
            ->select('quest_id')
            ->distinct()
            ->where(['id' => $taskIds])
            ->column();

        foreach ($questIds as $questId) {
            $attempt = QuestAttempt::finishIfComplete($userId, $questId);

            if ($attempt !== null) {
                GatewayHook::sendFinished($attempt);
            }
        }
    }
}