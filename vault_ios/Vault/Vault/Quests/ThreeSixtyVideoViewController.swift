//
//  ThreeSixtyVideoViewController.swift
//  Vault
//
//  Created by Carl Burnstein on 10/13/19.
//  Copyright © 2019 CASLS.
//

import UIKit

class ThreeSixtyVideoViewController: UIViewController {

    @IBOutlet weak var videoVRView: PanoramaView!
    @IBOutlet weak var lockView: UIView!
    var isPlaying : Bool = false
    var task : Task!
    let appDelegate = UIApplication.shared.delegate as! AppDelegate
    public var pagingViewController : CustomPagingViewController!
    
    override func viewDidLoad() {
        super.viewDidLoad()

        // Do any additional setup after loading the view.
        videoVRView.onTap = { [weak self] in self?.togglePlayback() }
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
    
    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        
        if let mediaObjects = task.media{
           for media in mediaObjects{
               let media = media as! Media
               if let mediaPath = media.getLocalPath(){
                   DispatchQueue.main.async {
                       self.videoVRView.load(from: URL(fileURLWithPath: mediaPath))
                   }
               }
           }
       }
    }
    
    override func viewDidDisappear(_ animated: Bool) {
        super.viewDidDisappear(animated)
        if(isPlaying){
            videoVRView.pause()
            isPlaying = false
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

extension ThreeSixtyVideoViewController {
    func togglePlayback() {
        if(isPlaying == true){
            videoVRView.pause()
            isPlaying = false
        }else{
            videoVRView.play()
            isPlaying = true
        }
    }
}
