//
//  ThreeSixtyImageViewController.swift
//  Vault
//
//  Created by Carl Burnstein on 10/13/19.
//  Copyright © 2019 CASLS.
//

import UIKit

class ThreeSixtyImageViewController: UIViewController {

    var task : Task!
    
    @IBOutlet weak var panoramaView: PanoramaView!
    @IBOutlet weak var lockView: UIView!
    
    override func viewDidLoad() {
        super.viewDidLoad()

        // Do any additional setup after loading the view.
        if let mediaObjects = task.media{
            for media in mediaObjects{
                let media = media as! Media
                if let mediaPath = media.getLocalPath(){
                    self.panoramaView.load(UIImage(contentsOfFile: mediaPath))
                }
            }
        }
    }
    
    override func viewWillAppear(_ animated: Bool) {
        super.viewWillAppear(animated)
        
        //Check if the task is locked by required tasks.
        if(task.isLocked() == true){
            lockView.isHidden = false
            self.view.bringSubviewToFront(lockView)
        }else{
            lockView.isHidden = true
            self.view.sendSubviewToBack(lockView)
        }
    }

    /*
    // MARK: - Navigation

    // In a storyboard-based application, you will often want to do a little preparation before navigation
    override func prepare(for segue: UIStoryboardSegue, sender: Any?) {
        // Get the new view controller using segue.destination.
        // Pass the selected object to the new view controller.
    }
    */

}
