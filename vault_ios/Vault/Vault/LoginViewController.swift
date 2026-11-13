//
//  ViewController.swift
//  Vault
//
//  Created by Carl Burnstein on 3/13/19.
//  Copyright © 2019 CASLS.
//

import UIKit
// Google Sign-In is switched off for now (see the note in the Podfile). Everything that
// needs the SDK sits behind `#if canImport(GoogleSignIn)`, so it compiles again once the
// pod is back; the two Google buttons stay in the storyboard, hidden.
#if canImport(GoogleSignIn)
import GoogleSignIn
#endif
import AuthenticationServices
import SideMenu

@available(iOS 13.0, *)
class LoginViewController: UIViewController, UITextFieldDelegate, ASAuthorizationControllerDelegate, ASAuthorizationControllerPresentationContextProviding {
    func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        return self.view.window!
    }
    
    @IBOutlet weak var emailField: UITextField!
    @IBOutlet weak var passwordField: UITextField!
    // Was a GIDSignInButton; a plain hidden view while Google Sign-In is off.
    @IBOutlet var googleSignInButton: UIView!
    @IBOutlet var appleSignInButton: UIButton!
    
    let appDelegate = UIApplication.shared.delegate as! AppDelegate
    var isSignupComplete = false
    
    override func viewDidLoad() {
        super.viewDidLoad()
        // Do any additional setup after loading the view, typically from a nib.
        
        #if canImport(GoogleSignIn)
        GIDSignIn.sharedInstance()?.delegate = self

        GIDSignIn.sharedInstance()?.presentingViewController = self
        (googleSignInButton as? GIDSignInButton)?.style = .wide
        #endif
        
        let newAppleSignInButton = ASAuthorizationAppleIDButton.init(type: .signIn, style: .whiteOutline)
        (newAppleSignInButton as UIControl).cornerRadius = 8
        newAppleSignInButton.addTarget(self, action: #selector(handleAuthorizationAppleIDButtonPress), for: .touchUpInside)
        
        appleSignInButton.addSubview(newAppleSignInButton)
        // Programatically fill space
        newAppleSignInButton.bindFrameToSuperviewBounds()

        self.isModalInPresentation = true
    }
    
    override func viewDidAppear(_ animated: Bool) {
        if(isSignupComplete){
            self.dismiss(animated: true, completion: {
                SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
            })
        }
        
        let user = appDelegate.getUser()
        if(user != nil){
            self.dismiss(animated: true, completion: {
                SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
            })
        }
    }
    
    override func prepare(for segue: UIStoryboardSegue, sender: Any?) {
        if(segue.identifier == "questsSegue"){
            
        }
    }
    
    func textFieldShouldReturn(_ textField: UITextField) -> Bool {
        self.view.endEditing(true)
        return false
    }
    
    @IBAction func googleLoginButtonTouched(_ sender: Any){
        #if canImport(GoogleSignIn)
        GIDSignIn.sharedInstance()?.signIn()
        #endif
    }
    
    @IBAction func guestLoginButtonTouched(_ sender: Any)   {
        guestSign()
    }

    @IBAction func loginButtonTouched(_ sender: Any) {
        let username = emailField.text
        let password = passwordField.text
        
        ApiController.authenticate(username: username!, password: password!, type: "basic", access_token: "", completion: { (success, user, errorMsg)  in
            if(success == true){
                self.dismiss(animated: true, completion: {
                    SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
                })
            }else{
                debugLog("something went wrong")
                self.showErrorMessage(title: "Error", body: errorMsg!, presentationStyle: .top, duration: 5, buttonTapHandler: nil, buttonTitle: nil, image: nil)
            }
        })
    }
    
    public func logout(){
        let cxt = appDelegate.persistentContainer.viewContext
        
        if let user = appDelegate.getUser(){
            cxt.delete(user)
            if let oauth = user.oauth{
                cxt.delete(oauth)
            }
            appDelegate.saveContext()
        }
        
        appDelegate.deleteAllData()
        
        #if canImport(GoogleSignIn)
        //Sign out of google.
        GIDSignIn.sharedInstance().signOut()
        #endif
    }
    @IBAction func logoutButtonTouched(_ sender: Any) {
        self.logout()
    }
    
    //Unwind Segue for returning from Signup.
    @IBAction func unwindForSignUp(_ sender: UIStoryboardSegue){
        let user = appDelegate.getUser()
        if(user != nil){
            isSignupComplete = true //Set this global variable true, this happens before viewDidAppear()
        }
    }
    
    @IBAction func unwindForLogout(_ sender: UIStoryboardSegue){
        self.logout()
    }
    
    /** Google Sign In methods **/
    #if canImport(GoogleSignIn)
    func sign(_ signIn: GIDSignIn!, didSignInFor user: GIDGoogleUser!,
              withError error: Error!) {
        if let error = error {
            debugLog("\(error.localizedDescription)")
        } else {
            // Perform any operations on signed in user here.
            let idToken = user.authentication.idToken // Safe to send to the server
            let email = user.profile.email
            // ...
            ApiController.authenticate(username: email!, password: "", type: "Google", access_token: idToken!, completion: { (success, user, errorMsg)  in
                if(success == true){
                    self.dismiss(animated: true, completion: {
                        SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
                    })
                }else{
                    debugLog("something went wrong")
                    self.showErrorMessage(title: "Error", body: errorMsg!, presentationStyle: .top, duration: 5, buttonTapHandler: nil, buttonTitle: nil, image: nil)
                }
            })
        }
    }
    
    func sign(_ signIn: GIDSignIn!, didDisconnectWith user: GIDGoogleUser!,
              withError error: Error!) {
        // Perform any operations when the user disconnects from app here.
        // ...
    }
    #endif
    
    /** Guest Sign In methods */
    func guestSign()    {
        // Vendor unique ID (resets when app is reinstalled)
        let deviceId = UIDevice.current.identifierForVendor?.uuidString
        ApiController.authenticate(username: deviceId!, password: "", type: "guest", access_token: "", completion: { (success, user, errorMsg)  in
            if(success == true){
                self.dismiss(animated: true, completion: {
                    SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
                })
            }else{
                debugLog("something went wrong")
                self.showErrorMessage(title: "Error", body: errorMsg!, presentationStyle: .top, duration: 5, buttonTapHandler: nil, buttonTitle: nil, image: nil)
            }
        })
    }
    
    /** Sign In with Apple methods **/
    @objc
    func handleAuthorizationAppleIDButtonPress() {
        debugLog("entering handleAuthorizationApple")
        let appleIDProvider = ASAuthorizationAppleIDProvider()
        let request = appleIDProvider.createRequest()
        request.requestedScopes = [.email]
        
        let authorizationController = ASAuthorizationController(authorizationRequests: [request])
        authorizationController.delegate = self
        authorizationController.presentationContextProvider = self
        authorizationController.performRequests()
    }
    
    func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        debugLog("Error Apple Authorization: " + error.localizedDescription)
    }

    func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        debugLog("Got Apple Authorization")
        switch authorization.credential {
        case let appleIDCredential as ASAuthorizationAppleIDCredential:
            
            // Create an account in your system.
            /**
             WARNING!
             Sign in with Apple only returns the email on initial sign up.
             Because of this, email is checked only through the JWT which passes email every time
             */

            ApiController.authenticate(username: appleIDCredential.email ?? "", password: "", type: "Apple", access_token: String(data: appleIDCredential.identityToken!, encoding: .utf8) ?? "", completion: { (success, user, errorMsg)  in
                if(success == true){
                    self.dismiss(animated: true, completion: {
                        SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
                    })
                }else{
                    debugLog("something went wrong")
                    self.showErrorMessage(title: "Error", body: errorMsg!, presentationStyle: .top, duration: 5, buttonTapHandler: nil, buttonTitle: nil, image: nil)
                }
            })
        case let passwordCredential as ASPasswordCredential:
            debugLog("Made it ASPasswordCredential???")
            // Sign in using an existing iCloud Keychain credential.
            let username = passwordCredential.user
            let password = passwordCredential.password
            
            ApiController.authenticate(username: username, password: password, type: "basic", access_token: "", completion: { (success, user, errorMsg)  in
                if(success == true){
                    self.dismiss(animated: true, completion: {
                        SideMenuManager.default.leftMenuNavigationController?.dismiss(animated: true, completion: nil)
                    })
                }else{
                    debugLog("something went wrong")
                    self.showErrorMessage(title: "Error", body: errorMsg!, presentationStyle: .top, duration: 5, buttonTapHandler: nil, buttonTitle: nil, image: nil)
                }
            })
        default:
            break
        }
    }
}

extension UIView {

    /// Adds constraints to this `UIView` instances `superview` object to make sure this always has the same size as the superview.
    /// Please note that this has no effect if its `superview` is `nil` – add this `UIView` instance as a subview before calling this.
    func bindFrameToSuperviewBounds() {
        guard let superview = self.superview else {
            debugLog("Error! `superview` was nil – call `addSubview(view: UIView)` before calling `bindFrameToSuperviewBounds()` to fix this.")
            return
        }

        self.translatesAutoresizingMaskIntoConstraints = false
        self.topAnchor.constraint(equalTo: superview.topAnchor, constant: 0).isActive = true
        self.bottomAnchor.constraint(equalTo: superview.bottomAnchor, constant: 0).isActive = true
        self.leadingAnchor.constraint(equalTo: superview.leadingAnchor, constant: 0).isActive = true
        self.trailingAnchor.constraint(equalTo: superview.trailingAnchor, constant: 0).isActive = true

    }
}

#if canImport(GoogleSignIn)
@available(iOS 13.0, *)
extension LoginViewController: GIDSignInDelegate {}
#endif
