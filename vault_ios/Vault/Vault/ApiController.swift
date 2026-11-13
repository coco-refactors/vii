//
//  ApiController.swift
//  Vault
//
//  Created by Carl Burnstein on 3/13/19.
//  Copyright © 2019 CASLS.
//

import Foundation
import Alamofire
import SwiftyJSON
import NVActivityIndicatorView

enum Router: URLRequestConvertible {
    case search(query: String, page: Int)
    case authenticate(username: String, password: String, type: String, access_token: String)
    case signUp(email: String, password1: String, password2: String)
    case saveUserTask(user_task_id: Int, userTask: UserTask)
    case resetQuest(quest_id: Int)
    case downloadQuest(quest_id: Int)
    case downloadQuestBy(code: String)
    case syncUserTasks(userTasks: [String])
    
    case getQuests
    
    
    // TODO: Set this to your self-hosted VAuLT backend URL before building.
    // Example: "https://vault-api.your-domain.example"
    static var serverURLString = "https://vii-api-production-ba707185.up.railway.app"
    static let baseURLString = serverURLString + "/api"
    static let perPage = 50
    
    // MARK: URLRequestConvertible
    
    func asURLRequest() throws -> URLRequest {
        let result: (path: String, parameters: Parameters) = {
            switch self {
            case let .search(query, page) where page > 0:
                return ("/search", ["q": query, "offset": Router.perPage * page])
            case let .search(query, _):
                return ("/search", ["q": query])
            case let .authenticate(username, password, type, access_token):
                return ("/authenticate", ["username": username, "password": password, "type": type, "access_token": access_token])
            case let .signUp(email, password1, password2):
                return ("/sign-up", ["email": email, "password1": password1, "password2": password2])
            case let .saveUserTask(user_task_id, userTask):
                return ("/save-user-task", ["id": user_task_id, "UserTask": userTask.toJSON()])
            case let .resetQuest(quest_id):
                return ("/reset-quest", ["quest_id": quest_id])
            case let .downloadQuest(quest_id):
                return ("/download-quest", ["quest_id": quest_id])
            case let .downloadQuestBy(code):
                return ("/download-quest", ["code": code])
            case let .syncUserTasks(userTasks):
                return ("/sync-user-tasks", ["userTasks": userTasks])
            case .getQuests:
                return ("/get-quests",[:])
            
            }
        }()
        
        let url = try Router.baseURLString.asURL()
        let urlRequest =  try URLRequest(url: url.appendingPathComponent(result.path), method: .post)
        
        return try URLEncoding.default.encode(urlRequest, with: result.parameters)
    }
}

class ApiController {
    static let appDelegate = UIApplication.shared.delegate as! AppDelegate
    
    static let activityIndicatorVC : ActivityIndicatorViewController = {
        let storyboard = UIStoryboard(name: "Main", bundle: nil)
        let aivc = storyboard.instantiateViewController(withIdentifier: "ActivityIndicatorViewController") as! ActivityIndicatorViewController
        let x = (UIScreen.main.bounds.size.width / 2) - 75
        let y = (UIScreen.main.bounds.size.height / 2) - 75
        aivc.view.frame = CGRect.init(x: x, y: y, width: 150, height: 150)
        return aivc
    }()

    // By default, every host gets standard TLS certificate validation.
    // If you're developing against a local instance of the backend without
    // TLS (e.g. a self-signed cert on "https://vault.localhost"), replace the
    // line below with:
    //   let evaluators: [String: ServerTrustEvaluating] = ["vault.localhost": DisabledTrustEvaluator()]
    // NEVER disable trust evaluation for a real/production host.
    private static var Manager: Session = {
        let evaluators: [String: ServerTrustEvaluating] = [:]
        let configuration = URLSessionConfiguration.af.default
        // allHostsMustBeEvaluated defaults to true, which rejects every host that has
        // no entry in `evaluators` (noRequiredEvaluator) — with the empty list above,
        // that is every request. false gives unlisted hosts the system's standard check.
        let serverTrustManager = ServerTrustManager(allHostsMustBeEvaluated: false, evaluators: evaluators)
        return Session(configuration: configuration, serverTrustManager: serverTrustManager)
    }()
    
    public static func startActivityIndicator(){
        if let vc = UIApplication.getPresentedViewController(){
            vc.view.addSubview(self.activityIndicatorVC.view)
        }
        self.activityIndicatorVC.activityIndicatorView.startAnimating()
    }
    
    public static func stopActivityIndicator(){
        //Stop animating the spinner
        self.activityIndicatorVC.activityIndicatorView.stopAnimating()
        //remove the activityIndicatorViewController's view
        self.activityIndicatorVC.view.removeFromSuperview()
        //Reset the text label and hide it
        self.activityIndicatorVC.textLabel.text = ""
        self.activityIndicatorVC.textLabel.isHidden = true
    }
    
    static func authenticate(username: String, password: String, type: String, access_token: String, completion:@escaping (Bool, User?, String?) -> Void){
        self.startActivityIndicator()
        Manager.request(Router.authenticate(username: username, password: password, type: type, access_token: access_token)).responseJSON { response in
            self.stopActivityIndicator()
            if let jsonValue = response.value{
                let json = JSON(jsonValue) //Use SwiftyJSON to parse the json object to a dictionary
                if(json["returnCode"] == 1){
                    //Error occured
                    let errMsg = json["returnCodeDescription"].stringValue;
                    completion(false, nil, errMsg)
                }else{
                    let user = User(JSONString: json["data"]["user"].rawString()!) //Map JSON to object using ObjectMapper
                    let oauth = Oauth(JSONString: json["data"]["oauth"].rawString()!) //Map JSON to object using ObjectMapper
                    user?.oauth = oauth //Tie user and oauth to eachother with the relationship
                    
                    let ctx = (UIApplication.shared.delegate as! AppDelegate).persistentContainer.viewContext
                    do {
                        try ctx.save() //Save core data context
                        appDelegate.user = user
                        completion(true, user, nil)
                    } catch {
                        completion(false, nil, "Error saving the managed object context.")
                        debugLog("Error saving the managed object context.")
                    }
                }
            }
        }
    }
    
    static func signUp(email: String, password1: String, password2: String, completion:@escaping (Bool, User?, String?) -> Void){
        self.startActivityIndicator()
        Manager.request(Router.signUp(email: email, password1: password1, password2: password2)).responseJSON { response in
            self.stopActivityIndicator()
            if let jsonValue = response.value{
                let json = JSON(jsonValue) //Use SwiftyJSON to parse the json object to a dictionary
                if(json["returnCode"] == 1){
                    //Error occured
                    let errMsg = json["returnCodeDescription"].stringValue;
                    completion(false, nil, errMsg)
                }else{
                    let user = User(JSONString: json["data"]["user"].rawString()!) //Map JSON to object using ObjectMapper
                    let oauth = Oauth(JSONString: json["data"]["oauth"].rawString()!) //Map JSON to object using ObjectMapper
                    user?.oauth = oauth //Tie user and oauth to eachother with the relationship
                    
                    let ctx = (UIApplication.shared.delegate as! AppDelegate).persistentContainer.viewContext
                    do {
                        try ctx.save() //Save core data context
                        appDelegate.user = user
                        completion(true, user, nil)
                    } catch {
                        completion(false, nil, "Error saving the managed object context.")
                        debugLog("Error saving the managed object context.")
                    }
                }
            }
        }
    }
    
    static func getQuests(completion:@escaping (Bool, [Quest]?, String?) -> Void){
        let user = appDelegate.getUser() //Get a fresh copy of the user object.
        if let access_token = user?.oauth?.access_token{
            self.startActivityIndicator()
            Manager.request(Router.getQuests)
                .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                .responseJSON { response in
                    DispatchQueue.main.async {
                        self.stopActivityIndicator()
                        if let jsonValue = response.value {
                            let json = JSON(jsonValue)
                            if(json["returnCode"] == 1){
                                let errMsg = json["returnCodeDescription"].stringValue
                                completion(false, nil, errMsg)
                            }else{
                                var quests : [Quest] = []
                                let questsJson = json["data"]["quests"].arrayValue
                                for t in questsJson {
                                    if let quest = Quest(JSONString: t.rawString()!) {
                                        if let questCD = self.saveQuestToCoreData(quest: quest, questJSONString: t.rawString()!){
                                            quests.append(questCD)
                                        }
                                    }
                                }
                                if let finalQuests = Quest.getAll(){
                                    completion(true, finalQuests, nil)
                                }else{
                                    completion(true, quests, nil)
                                }
                            }
                        }else{
                            completion(false, nil, "Error completing network request.")
                        }
                    }
                }
        }else{
            completion(false, nil, "Not authenticated.")
        }
    }
    
    static func downloadQuest(quest_id: Int, completion:@escaping (Bool, String?, Quest?) -> Void) {
        let user = appDelegate.getUser() //Get a fresh copy of the user object.
        if let access_token = user?.oauth?.access_token{
            
            self.activityIndicatorVC.textLabel.text = "Downloading..."
            self.activityIndicatorVC.textLabel.isHidden = false
            self.startActivityIndicator()
            Manager.request(Router.downloadQuest(quest_id: quest_id))
                .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                    .responseJSON { response in
                    DispatchQueue.main.async {
                        if let jsonValue = response.value {
                            let json = JSON(jsonValue)
                            if(json["returnCode"] == 1){
                                self.stopActivityIndicator()
                                let errMsg = json["returnCodeDescription"].stringValue
                                completion(false, errMsg, nil)
                            }else{
                                if let jsonString = json["data"]["quest"].rawString(), let quest = Quest(JSONString: jsonString) {
                                    if let questCD = self.saveQuestToCoreData(quest: quest, questJSONString: jsonString){
                                        self.activityIndicatorVC.textLabel.text = "Downloading media..."
                                        DispatchQueue.global(qos: .userInitiated).async {
                                            self.downloadQuestMedia(quest: questCD) {
                                                DispatchQueue.main.async {
                                                    self.stopActivityIndicator()
                                                    completion(true, "Success", questCD)
                                                }
                                            }
                                        }
                                    }else{
                                        self.stopActivityIndicator()
                                        completion(false, "Something went wrong saving the quest.", nil)
                                    }
                                }else{
                                    self.stopActivityIndicator()
                                    completion(false, "Something went wrong downloading the quest.", nil)
                                }
                            }
                        }else{
                            self.stopActivityIndicator()
                            completion(false, "Error completing network request.", nil)
                        }
                    }
                }
        }else{
            completion(false, "Something went wrong.", nil)
        }
    }
    
    static func downloadQuestByCode(code: String, completion:@escaping (Bool, String?, Quest?) -> Void) {
            let user = appDelegate.getUser() //Get a fresh copy of the user object.
            if let access_token = user?.oauth?.access_token{
                
                self.activityIndicatorVC.textLabel.text = "Downloading..."
                self.activityIndicatorVC.textLabel.isHidden = false
                self.startActivityIndicator()
                Manager.request(Router.downloadQuestBy(code: code))
                    .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                    .responseJSON { response in
                        DispatchQueue.main.async {
                            if let jsonValue = response.value {
                                let json = JSON(jsonValue)
                                if(json["returnCode"] == 1){
                                    self.stopActivityIndicator()
                                    let errMsg = json["returnCodeDescription"].stringValue
                                    completion(false, errMsg, nil)
                                }else{
                                    if let jsonString = json["data"]["quest"].rawString(), let quest = Quest(JSONString: jsonString) {
                                        if let questCD = self.saveQuestToCoreData(quest: quest, questJSONString: jsonString){
                                            self.activityIndicatorVC.textLabel.text = "Downloading media..."
                                            DispatchQueue.global(qos: .userInitiated).async {
                                                self.downloadQuestMedia(quest: questCD) {
                                                    DispatchQueue.main.async {
                                                        self.stopActivityIndicator()
                                                        completion(true, "Success", questCD)
                                                    }
                                                }
                                            }
                                        }else{
                                            self.stopActivityIndicator()
                                            completion(false, "Something went wrong saving the quest.", nil)
                                        }
                                    }else{
                                        self.stopActivityIndicator()
                                        completion(false, "Something went wrong downloading the quest.", nil)
                                    }
                                }
                            }else{
                                self.stopActivityIndicator()
                                let errMsg = response.error.map { ($0 as NSError).code == NSURLErrorTimedOut ? "Request timed out. Please try again." : $0.localizedDescription } ?? "Error completing network request."
                                completion(false, errMsg, nil)
                            }
                        }
                }
            }else{
                completion(false, "Something went wrong.", nil)
            }
        }
    
    /// Downloads all quest media on a background thread. Call completion on main when done (e.g. to stop spinner and proceed).
    static func downloadQuestMedia(quest: Quest, completion: (() -> Void)? = nil) {
        // Must run off main to avoid deadlock (ApiController.downloadData uses semaphore; Alamofire callbacks run on main).
        if let questMedia = quest.media {
            questMedia.downloadData()
        }
        if let pagingImage = quest.pagingImage {
            pagingImage.downloadData()
        }
        if let tasks = quest.tasks {
            for t in tasks {
                let task = t as! Task
                if let media = task.media {
                    for m in media {
                        (m as! Media).downloadData()
                    }
                }
                if let taskMetas = task.taskMetas {
                    for tm in taskMetas {
                        let taskMeta = tm as! TaskMeta
                        if let taskMetaMedia = taskMeta.media {
                            taskMetaMedia.downloadData()
                        }
                    }
                }
                if let arTargets = task.arTargets {
                    for at in arTargets {
                        let arTarget = at as! ArTarget
                        if let arTargetMedia = arTarget.media { arTargetMedia.downloadData() }
                        if let arTargetOverlayMedia = arTarget.overlayMedia { arTargetOverlayMedia.downloadData() }
                        if let arTargetAudioMedia = arTarget.audioMedia { arTargetAudioMedia.downloadData() }
                    }
                }
                if let correctResponseImageMedia = task.correctResponseImageMedia { correctResponseImageMedia.downloadData() }
                if let incorrectResponseImageMedia = task.incorrectResponseImageMedia { incorrectResponseImageMedia.downloadData() }
            }
        }
        completion?()
    }
    
    static func saveQuestToCoreData(quest: Quest, questJSONString:String) -> Quest!{
        let ctx = (UIApplication.shared.delegate as! AppDelegate).persistentContainer.viewContext
        let exists : Bool = Quest.doesQuestExist(id: quest.id)
        //If the quest does not yet exist, go ahead and create a new one.
        if(!exists){
            //If there were tasks that got mapped from JSON, loop through and save each one.
            if let tasks = quest.tasks {
                for x in tasks{
                    let tsk = x as! Task
                    tsk.quest = quest  //Assign the task to the specific quest object
                    ctx.insert(tsk) //Insert into context
                    if let userTask = tsk.userTask{
                        ctx.insert(userTask)
                        userTask.task = tsk
                        if let user = appDelegate.getUser(){
                            userTask.user = user
                        }
                    }
                    //Loop through the media entities and insert them into the context
                    if let media = tsk.media{
                        for m in media{
                            let med = m as! Media
                            ctx.insert(med)
                            med.addToTasks(tsk)
                        }
                    }
                    
                    //Loop through the taskMeta entities and insert them into the context
                    if let taskMetas = tsk.taskMetas{
                        for tm in taskMetas{
                            let taskMeta = tm as! TaskMeta
                            ctx.insert(taskMeta)
                            taskMeta.task = tsk
                            if let taskMetaMedia = taskMeta.media{
                                ctx.insert(taskMetaMedia)
                                taskMeta.media = taskMetaMedia
                            }
                        }
                    }
                    
                    //Loop through the arTarget entities and insert them into the context
                    if let arTargets = tsk.arTargets{
                        for at in arTargets{
                            let arTarget = at as! ArTarget
                            ctx.insert(arTarget)
                            arTarget.task = tsk
                            if let arTargetMedia = arTarget.media{
                                ctx.insert(arTargetMedia)
                                arTarget.media = arTargetMedia
                            }
                            if let arTargetOverlayMedia = arTarget.overlayMedia{
                                ctx.insert(arTargetOverlayMedia)
                                arTarget.overlayMedia = arTargetOverlayMedia
                            }
                            if let arTargetAudioMedia = arTarget.audioMedia{
                                ctx.insert(arTargetAudioMedia)
                                arTarget.audioMedia = arTargetAudioMedia
                            }
                        }
                    }
                    
                    //Loop through the requiredTask entities and insert them into the context
                    if let requiredTasks = tsk.requiredTasks{
                        for rt in requiredTasks{
                            let requiredTask = rt as! RequiredTask
                            ctx.insert(requiredTask)
                            requiredTask.parentTask = tsk
                        }
                    }
                    
                    //Get the correct response image media object
                    if let correctResponseImageMedia = tsk.correctResponseImageMedia{
                        ctx.insert(correctResponseImageMedia)
                    }
                    //Get the incorrect response image media object
                    if let incorrectResponseImageMedia = tsk.incorrectResponseImageMedia{
                        ctx.insert(incorrectResponseImageMedia)
                    }
                }
            }
            
            //Download the quest media.
            if let questMedia = quest.media{
                ctx.insert(questMedia)
            }
            if let pagingImage = quest.pagingImage{
                ctx.insert(pagingImage)
            }
            
            //Get the questUserAccess entites and insert into context
            if let questUserAccesses = quest.questUserAccesses{
                for qua in questUserAccesses as NSSet{
                    let questUserAccess = qua as! QuestUserAccess
                    questUserAccess.quest = quest
                    ctx.insert(questUserAccess)
                }
            }
            
            //Add the new quest to the core data context.
            ctx.insert(quest)
            appDelegate.saveContext() //Save the objects to the core data context.
            
            return quest
        }else{
            if let existingQuest = Quest.get(id: quest.id, context: ctx){
                //Update the existing Quest object
                existingQuest.update(JSONString: questJSONString)
                
                if let jsonQuest = Quest(JSONString: questJSONString){
                    if(jsonQuest.tasks!.count > 0){
                        if let tasks = existingQuest.tasks {
                            //Remove any existing tasks from the quest.
                            for x in tasks as NSSet{
                                let task = x as! Task
                                if let userTask = task.userTask{
                                    ctx.delete(userTask) //Delete the userTask and remove it from the task object.
                                }
                                
                                //remove any existing media
                                if let media = task.media{
                                    for m in media{
                                        let med = m as! Media
                                        ctx.delete(med)
                                    }
                                }
                                
                                //remove any existing taskMetas
                                if let taskMetas = task.taskMetas{
                                    for tm in taskMetas{
                                        let taskMeta = tm as! TaskMeta
                                        ctx.delete(taskMeta)
                                    }
                                }
                                
                                //remove any existing arTargets
                                if let arTargets = task.arTargets{
                                    for at in arTargets{
                                        let arTarget = at as! ArTarget
                                        ctx.delete(arTarget)
                                    }
                                }
                                
                                //remove any existing required Tasks
                                if let requiredTasks = task.requiredTasks{
                                    for rt in requiredTasks{
                                        let requiredTask = rt as! RequiredTask
                                        ctx.delete(requiredTask)
                                    }
                                }
                                
                                //Remove the correct response image media object
                                if let correctResponseImageMedia = task.correctResponseImageMedia{
                                    ctx.delete(correctResponseImageMedia)
                                }
                                //Remove the incorrect response image media object
                                if let incorrectResponseImageMedia = task.incorrectResponseImageMedia{
                                    ctx.delete(incorrectResponseImageMedia)
                                }
                                ctx.delete(task) //Delete the old version of the task objects
                            }
                            appDelegate.saveContext() //Save the removal changes right away
                            //Loop through the JSON Quest.tasks and create the new ones.
                            if let newTasks = quest.tasks{
                                for x in newTasks{
                                    let tsk = x as! Task
                                    ctx.insert(tsk)  //Insert the new task into the context
                                    tsk.quest = existingQuest //Associate the task to the specific quest object
                                    existingQuest.addToTasks(tsk)
                                    if let userTask = tsk.userTask{
                                        ctx.insert(userTask)
                                        userTask.task = tsk
                                        if let user = appDelegate.getUser(){
                                            userTask.user = user
                                        }
                                    }
                                    //Loop through the media entities and insert them into the context
                                    if let media = tsk.media{
                                        for m in media{
                                            let med = m as! Media
                                            ctx.insert(med)
                                            med.addToTasks(tsk)
                                        }
                                    }
                                    
                                    //Loop through the taskMeta entities and insert them into the context
                                    if let taskMetas = tsk.taskMetas{
                                        for tm in taskMetas{
                                            let taskMeta = tm as! TaskMeta
                                            ctx.insert(taskMeta)
                                            taskMeta.task = tsk
                                            if let taskMetaMedia = taskMeta.media{
                                                ctx.insert(taskMetaMedia)
                                                taskMeta.media = taskMetaMedia
                                            }
                                        }
                                    }
                                    
                                    //Loop through the arTarget entities and insert them into the context
                                    if let arTargets = tsk.arTargets{
                                        for at in arTargets{
                                            let arTarget = at as! ArTarget
                                            ctx.insert(arTarget)
                                            arTarget.task = tsk
                                            if let arTargetMedia = arTarget.media{
                                                ctx.insert(arTargetMedia)
                                                arTarget.media = arTargetMedia
                                            }
                                            if let arTargetOverlayMedia = arTarget.overlayMedia{
                                                ctx.insert(arTargetOverlayMedia)
                                                arTarget.overlayMedia = arTargetOverlayMedia
                                            }
                                            if let arTargetAudioMedia = arTarget.audioMedia{
                                                ctx.insert(arTargetAudioMedia)
                                                arTarget.audioMedia = arTargetAudioMedia
                                            }
                                        }
                                    }
                                    
                                    //Loop through the requiredTask entities and insert them into the context
                                    if let requiredTasks = tsk.requiredTasks{
                                        for rt in requiredTasks{
                                            let requiredTask = rt as! RequiredTask
                                            ctx.insert(requiredTask)
                                            requiredTask.parentTask = tsk
                                        }
                                    }
                                    
                                    //Get the correct response image media object
                                    if let correctResponseImageMedia = tsk.correctResponseImageMedia{
                                        ctx.insert(correctResponseImageMedia)
                                    }
                                    //Get the incorrect response image media object
                                    if let incorrectResponseImageMedia = tsk.incorrectResponseImageMedia{
                                        ctx.insert(incorrectResponseImageMedia)
                                    }
                                }
                            }
                        }
                    }
                }
                
                
                appDelegate.saveContext()
                return existingQuest
            }
        }
        
        return nil
    }
    
    static func downloadData(url: String) -> String! {
        let fullUrl = Router.serverURLString + url
        let destination: DownloadRequest.Destination = { _, _ in
            let documentsURL = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
            let fileUrlArray = url.components(separatedBy: "/")
            let fileURL: URL
            if let name = fileUrlArray.last, !name.isEmpty {
                fileURL = documentsURL.appendingPathComponent(name)
            } else {
                fileURL = documentsURL.appendingPathComponent(String.randomString(length: 8))
            }
            return (fileURL, [.removePreviousFile, .createIntermediateDirectories])
        }
        var resultPath: String?
        var resultError: AFError?
        let semaphore = DispatchSemaphore(value: 0)
        Manager.download(fullUrl, to: destination).response { response in
            switch response.result {
            case .success:
                resultPath = response.fileURL?.path
            case .failure(let error):
                resultError = error
            }
            semaphore.signal()
        }
        _ = semaphore.wait(timeout: .distantFuture)
        if let error = resultError {
            debugLog("Failed with error: \(error)")
            return nil
        }
        return resultPath
    }
    
    static func saveUserTask(user_task_id: Int, userTask: UserTask, completion:@escaping (Bool, String?, UserTask?) -> Void) {
        let user = appDelegate.getUser() //Get a fresh copy of the user object.
        if let access_token = user?.oauth?.access_token{
            self.activityIndicatorVC.textLabel.text = "Saving..."
            self.activityIndicatorVC.textLabel.isHidden = false
            self.startActivityIndicator()
            Manager.request(Router.saveUserTask(user_task_id: user_task_id, userTask: userTask))
                .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                .responseJSON { response in
                    self.stopActivityIndicator()
                    if let jsonValue = response.value {
                        let json = JSON(jsonValue) //Use SwiftyJSON to parse the json object to a dictionary
                        if(json["returnCode"] == 1){
                            //Error occured
                            let errMsg = json["returnCodeDescription"].stringValue;
                            completion(false, errMsg, nil)
                        }else{
                            //No errors.
                            let ctx = (UIApplication.shared.delegate as! AppDelegate).persistentContainer.viewContext
                            //Map JSON to object using ObjectMapper
                            if let userTask = UserTask(JSONString: json["data"]["userTask"].rawString()!) {
                                //If there's an existing one, delete it and replace it.
                                if let existingUserTask = UserTask.get(id: userTask.id, context: ctx){
                                    ctx.insert(userTask) //Insert the new one
                                    userTask.user = existingUserTask.user //Assign the user to the new userTask
                                    //If the task exists
                                    if let task = existingUserTask.task{
                                        //assign the new userTask to this task
                                        task.userTask = userTask
                                    }
                                    ctx.delete(existingUserTask)  //Delete the old one
                                }else{
                                    ctx.insert(userTask) //Insert the new one
                                    userTask.user = user
                                    //There is not an existing one.
                                    if let task = Task.get(id: userTask.task_id, context: ctx){
                                        task.userTask = userTask //Link the new userTask with the Task.
                                    }
                                }
                                //Save context
                                appDelegate.saveContext()
                                completion(true, "Succes", userTask)
                            }
                        }
                    }
                    completion(false, "Error completing network request.", nil)
            }
        }
    }
    
    static func resetQuest(quest_id: Int, completion:@escaping (Bool, String?, Quest?) -> Void) {
        let user = appDelegate.getUser() //Get a fresh copy of the user object.
        if let access_token = user?.oauth?.access_token{
            self.activityIndicatorVC.textLabel.text = "Resetting..."
            self.activityIndicatorVC.textLabel.isHidden = false
            self.startActivityIndicator()
            Manager.request(Router.resetQuest(quest_id: quest_id))
                .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                .responseJSON { response in
                    self.stopActivityIndicator()
                    if let jsonValue = response.value {
                        let json = JSON(jsonValue) //Use SwiftyJSON to parse the json object to a dictionary
                        if(json["returnCode"] == 1){
                            //Error occured
                            let errMsg = json["returnCodeDescription"].stringValue;
                            completion(false, errMsg, nil)
                        }else{
                            //No errors.
                            let jsonString = json["data"]["quest"].rawString()!
                            //Map JSON to object using ObjectMapper
                            if let quest = Quest(JSONString: jsonString) {
                                if let questCD = self.saveQuestToCoreData(quest: quest, questJSONString: jsonString){
                                    self.downloadQuestMedia(quest: questCD)
                                    completion(true, "Success", questCD)
                                }else{
                                    completion(false, "Something went wrong saving the quest.", nil)
                                }
                            }
                        }
                    }else{
                        completion(false, "Error completing network request.", nil)
                    }
            }
        }
    }
    
    static func saveUserTaskToSyncQueue(userTask: UserTask){
        let jsonStr = userTask.toJSONString(prettyPrint: true)
        let ctx = appDelegate.persistentContainer.viewContext
        let utSync = UserTaskSyncQueue(context: ctx)
        utSync.id = userTask.id
        utSync.json = jsonStr
        appDelegate.saveContext()
    }
    
    static func syncQueue(){
        //Get all the objects in the queue
        if let userTasksToSync = UserTaskSyncQueue.getAll(){
            if(userTasksToSync.count > 0){
                //Sync them to the server via synchronous network requests. (So wait for each request to finish one at a time)
                let user = appDelegate.getUser() //Get a fresh copy of the user object.
                if let access_token = user?.oauth?.access_token{
                    
                    var userTasks : [String] = []
                    for utts in userTasksToSync{
                        if let str = utts.toJSONString(){
                            userTasks.append(str)
                        }
                    }
                    
                    Manager.request(Router.syncUserTasks(userTasks: userTasks))
                        .authenticate(username: access_token, password: "") //this adds authentication headers with the access_token
                        .responseJSON { response in
                            if let jsonValue = response.value {
                                let json = JSON(jsonValue) //Use SwiftyJSON to parse the json object to a dictionary
                                if(json["returnCode"] == 1){
                                    //Error occured
                                    let errMsg = json["returnCodeDescription"].stringValue;
                                    debugLog(errMsg)
                                }else{
                                    //Remove the current Queue
                                    for utsq in userTasksToSync{
                                        //Remove each object
                                        appDelegate.persistentContainer.viewContext.delete(utsq)
                                    }
                                    appDelegate.saveContext()
                                    
                                    //Reset the queue to the userTask objects that were not saved.
                                    let tasksNotSaved = json["data"]["tasksNotSaved"].arrayValue
                                    for ut in tasksNotSaved{
                                        let userTaskObj = ut.dictionaryValue
                                        let userTaskJSONString = ut.rawString()
                                        let userTaskSyncQueue = UserTaskSyncQueue(context: appDelegate.persistentContainer.viewContext)
                                        userTaskSyncQueue.id = userTaskObj["id"]!.int16!
                                        userTaskSyncQueue.json = userTaskJSONString
                                    }
                                    appDelegate.saveContext()
                                }
                            }else{
                                debugLog("Something went wrong trying to sync data")
                            }
                    }
                }
            }
        }
    }
}
