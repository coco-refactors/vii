<?php

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\helpers\FileHelper;

class Media extends \common\models\base\MediaBase
{
    /**
     * Extensions the app will actually serve (matches the web server's own
     * media FilesMatch allowlist) — anything else is rejected on upload.
     */
    const ALLOWED_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp4', 'mpeg4', 'mov', 'm4v', 'webm',
        'mp3', 'm4a',
    ];

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
	    	return array_merge(parent::behaviors(),[
    			[
    				'class' => TimestampBehavior::className(),
    				'attributes' => [
    					ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
   					ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
   				],
    				'value' => new Expression('NOW()'),
	    		],
	    	]);
    }
    
    public function delete(){
		//Delete the actual file from disk
		if(file_exists($this->uri)){
			unlink($this->uri);
		}
		
		if(!parent::delete()){
			return false;
		}else{
			return true;
		}
	}
	
	public static function saveMedia($uploadedFile, $user_id = null){
		//This should be an the UploadedFile class object passed in to this function.
		$fileNameExploded = explode(".", $uploadedFile->name);
		$type = explode("/", $uploadedFile->type);
		$basicType = $type[0];
		$mimeType = $uploadedFile->type;
        
		//find the original file's extension
		$extension = strtolower(end($fileNameExploded));

		//Reject file types we don't serve/support - the client controls both
		//the filename and the reported MIME type, so neither can be trusted alone.
		if (!in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
			return false;
		}

		if($user_id != null){
			$newFileName = $user_id . "_" . date('Y-m-d-His') . "_" . rand(100,999);	
		}else{
			$newFileName = "0000_" . date('Y-m-d-His') . "_" . rand(100,999);
		}
		//expand the directory structure to include a sub-dir layer for YYYYMM/DD/ to alleviate bloated directories over time.
		$year = date('Y');
		$month = date('m');
		$dayOfMonth = date('d');

		//Create the upload directory for the image.
		$newFilePath = \Yii::getAlias('@app/../common/web/uploads/' . $user_id . '/' . $basicType . '/' . $year . '/' . $month . '/' . $dayOfMonth . '/');
		if(FileHelper::createDirectory($newFilePath)){
			
		}else{
			return false;
		}
		
		$uri = $newFilePath . $newFileName  . "." . $extension;
		$url = '/common/uploads/' . $user_id . '/'  . $basicType . '/' . $year . '/' . $month . '/' . $dayOfMonth . '/' . $newFileName  . "." . $extension; 
		//copy the original image to the upload director / evidence type
		if($uploadedFile->saveAs($uri)){
			// MOV (QuickTime) movie files are not playable on Android devices. Convert them to .mp4
    		if (strtoupper($extension) == 'MOV') {
	    		// convert file to mp4
	    		$execCmd = "/usr/bin/ffmpeg -probesize 500000000 -analyzeduration 2000000000 -i " . escapeshellarg($newFilePath . 		$newFileName . "." . $extension) . " -vcodec copy -acodec copy " . escapeshellarg($newFilePath . $newFileName . ".mp4");
	   			$exRet = exec($execCmd);
	   			// delete old file
	   			if(file_exists($newFilePath . $newFileName . "." . $extension)){
	   				unlink($newFilePath . $newFileName . "." . $extension);
	   			}
	   			// reassign file extension
				$extension = 'mp4';
				$mimeType = "video/mp4";
				$uri = $newFilePath . $newFileName  . "." . $extension;
				$url = '/common/uploads/' . $user_id . '/'  . $basicType . '/' . $year . '/' . $month . '/' . $dayOfMonth . '/' . $newFileName  . "." . $extension; 
			}else if(strtoupper($extension) == "JPG"){
				$exif = @exif_read_data($uri);
				if (!empty($exif['Orientation'])){
					$image = imagecreatefromjpeg($uri);
		       		$ort = $exif['Orientation'];
		          	switch ($ort) {
		                case 3:
		                    $image = imagerotate($image, 180, 0);
		                    break;
		
		                case 6:
		                    $image = imagerotate($image, -90, 0);
		                    break;
		
		                case 8:
		                    $image = imagerotate($image, 90, 0);
		                    break;
		            }
		            imagejpeg($image,$uri, 90); /*IF FOUND ORIENTATION THEN ROTATE IMAGE IN */
		        }
			}
			
			if($basicType == "audio" || $basicType == "video"){
				// Initialize getID3 engine
	        	$getID3 = new \getID3();
	        	$fileInfo = $getID3->analyze($uri);
	        	$duration = $fileInfo["playtime_seconds"];
	        	$file_size = $fileInfo["filesize"];
			}else{
				$duration = NULL;
        		$file_size = filesize($uri);
			}
			
			//Create a Media entry in the database.
			$newMedia = new Media();
			$newMedia->title = $newFileName  . "." . $extension;
			$newMedia->uri = $uri;
			$newMedia->url = $url;
			$newMedia->type = $basicType;
			$newMedia->mime_type = $mimeType;
			$newMedia->duration = $duration;
			$newMedia->file_size = $file_size;
			if($newMedia->save()){
				if($user_id != null){
					$basicType = ucfirst($basicType);
					UserEvent::saveUserEvent($user_id, UserEvent::TYPE_MEDIA_SAVE, "Media file was uploaded in Media model. Media ID={$newMedia->id}");
				}else{
					UserEvent::saveUserEvent(1, UserEvent::TYPE_MEDIA_SAVE, "Media file was uploaded in Media model. UNKNOWN USER Media ID={$newMedia->id}");
				}
				return $newMedia;  //Returns the newly created Media record.
			}else{
				if($user_id != null){
					$basicType = ucfirst($basicType);
					UserEvent::saveUserEvent($user_id, UserEvent::TYPE_MEDIA_SAVE, "FAILED: to upload media file in Media model. Media ID={$newMedia->id}");
				}else{
					UserEvent::saveUserEvent(1, UserEvent::TYPE_MEDIA_SAVE, "Media file was not uploaded. UNKNOWN USER Media ID={$newMedia->id}");
				}
				return false;
			}
		}else{
			return false;
		}
		
		return false;
	}
}