//
//  AppDelegate.swift
//  Vault
//
//  Created by Carl Burnstein on 3/13/19.
//  Copyright © 2019 CASLS.
//

import UIKit
import CoreData
// Google Sign-In is switched off for now (see the note in the Podfile).
#if canImport(GoogleSignIn)
import GoogleSignIn
#endif
import SideMenu

@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    var window: UIWindow?
    var user: User?


    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        // Override point for customization after application launch.
        
        // TODO: Set these to your own Google Cloud OAuth client IDs (see
        // client_secret.json.example / google_credentials.json.example in vault_web
        // for the matching backend-side setup). clientID is this app's iOS OAuth
        // client; serverClientID is the web client used by your backend.
        #if canImport(GoogleSignIn)
        GIDSignIn.sharedInstance().clientID = "YOUR_IOS_CLIENT_ID.apps.googleusercontent.com"
        GIDSignIn.sharedInstance().serverClientID = "YOUR_SERVER_CLIENT_ID.apps.googleusercontent.com";
        #endif
        debugLog("Documents Directory: ", FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).last ?? "Not Found!")

        let storyboard = UIStoryboard(name: "Main", bundle: nil)
        SideMenuManager.default.leftMenuNavigationController = storyboard.instantiateViewController(withIdentifier: "CustomSideMenuNavigationController") as? CustomSideMenuNavigationController

        return true
    }
    
    func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey : Any] = [:]) -> Bool {
        #if canImport(GoogleSignIn)
        return GIDSignIn.sharedInstance().handle(url)
        #else
        return false
        #endif
    }

    func applicationWillResignActive(_ application: UIApplication) {
        // Sent when the application is about to move from active to inactive state. This can occur for certain types of temporary interruptions (such as an incoming phone call or SMS message) or when the user quits the application and it begins the transition to the background state.
        // Use this method to pause ongoing tasks, disable timers, and invalidate graphics rendering callbacks. Games should use this method to pause the game.
    }

    func applicationDidEnterBackground(_ application: UIApplication) {
        // Use this method to release shared resources, save user data, invalidate timers, and store enough application state information to restore your application to its current state in case it is terminated later.
        // If your application supports background execution, this method is called instead of applicationWillTerminate: when the user quits.
    }

    func applicationWillEnterForeground(_ application: UIApplication) {
        // Called as part of the transition from the background to the active state; here you can undo many of the changes made on entering the background.
    }

    func applicationDidBecomeActive(_ application: UIApplication) {
        // Restart any tasks that were paused (or not yet started) while the application was inactive. If the application was previously in the background, optionally refresh the user interface.
    }

    func applicationWillTerminate(_ application: UIApplication) {
        // Called when the application is about to terminate. Save data if appropriate. See also applicationDidEnterBackground:.
        // Saves changes in the application's managed object context before the application terminates.
        self.saveContext()
    }

    // MARK: - Core Data stack

    lazy var persistentContainer: NSPersistentContainer = {
        /*
         The persistent container for the application. This implementation
         creates and returns a container, having loaded the store for the
         application to it. This property is optional since there are legitimate
         error conditions that could cause the creation of the store to fail.
        */
        let container = NSPersistentContainer(name: "Vault")
        container.loadPersistentStores(completionHandler: { (storeDescription, error) in
            if let error = error as NSError? {
                // Replace this implementation with code to handle the error appropriately.
                // fatalError() causes the application to generate a crash log and terminate. You should not use this function in a shipping application, although it may be useful during development.
                 
                /*
                 Typical reasons for an error here include:
                 * The parent directory does not exist, cannot be created, or disallows writing.
                 * The persistent store is not accessible, due to permissions or data protection when the device is locked.
                 * The device is out of space.
                 * The store could not be migrated to the current model version.
                 Check the error message to determine what the actual problem was.
                 */
                fatalError("Unresolved error \(error), \(error.userInfo)")
            }
        })
        return container
    }()

    // MARK: - Core Data Saving support

    func saveContext () {
        let context = persistentContainer.viewContext
        if context.hasChanges {
            do {
                try context.save()
            } catch {
                // Replace this implementation with code to handle the error appropriately.
                // fatalError() causes the application to generate a crash log and terminate. You should not use this function in a shipping application, although it may be useful during development.
                let nserror = error as NSError
                fatalError("Unresolved error \(nserror), \(nserror.userInfo)")
            }
        }
    }
    
    func getUser() -> User!{
        let fetchRequest = NSFetchRequest<User>(entityName: "User")
        let fetchedData = try! persistentContainer.viewContext.fetch(fetchRequest)
        if (!fetchedData.isEmpty) {
            var user : User!
            for i in 0..<fetchedData.count {
                user = fetchedData[i]
            }
            self.user = user //set the AppDelegate user object
            return user
        }
        else {
            return nil
        }
    }
    
    func deleteAllData() {
        let context = persistentContainer.viewContext
        var fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "ArTarget")
        var request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting ArTarget objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "TaskMeta")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting TaskMeta objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "Media")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting Media objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "UserTask")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting UserTask objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "Task")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting Task objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "Quest")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting Quest objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "Oauth")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting Oauth objects")
        }
        
        fetch = NSFetchRequest<NSFetchRequestResult>(entityName: "User")
        request = NSBatchDeleteRequest(fetchRequest: fetch)
        do {
            try context.execute(request)
            try context.save()
        } catch {
            print ("There was an error deleting User objects")
        }
    }

}

extension UIApplication{
    class func getPresentedViewController() -> UIViewController? {
        var presentViewController = UIApplication.shared.keyWindow?.rootViewController
        while let pVC = presentViewController?.presentedViewController
        {
            presentViewController = pVC
        }
        
        return presentViewController
    }
}
