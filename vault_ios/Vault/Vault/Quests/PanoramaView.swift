//
//  PanoramaView.swift
//  Vault
//
//  A 360° photo or video viewer. Replaces GVRPanoramaView and GVRVideoView from
//  the Google Cardboard SDK, which Apple rejects because it links UIWebView.
//
//  The media is painted on the inside of a sphere with the camera at its centre.
//  The viewer looks around by dragging or by moving the device.
//

import SceneKit
import AVFoundation
import CoreMotion

class PanoramaView: SCNView {

    /// Called when the view is tapped.
    var onTap: (() -> Void)?

    private let sphere = SCNSphere(radius: 10)
    private let yawNode = SCNNode()     // turned by dragging left and right
    private let pitchNode = SCNNode()   // turned by dragging up and down
    private let cameraNode = SCNNode()  // turned by moving the device
    private let motion = CMMotionManager()
    private var player: AVPlayer?
    private var isFacingCentre = false

    required init?(coder: NSCoder) {
        super.init(coder: coder)

        scene = SCNScene()
        cameraNode.camera = SCNCamera()
        pitchNode.addChildNode(cameraNode)
        yawNode.addChildNode(pitchNode)
        scene?.rootNode.addChildNode(yawNode)

        // The sphere is seen from the inside, so the image is flipped back to
        // read left to right, and turned so its centre is straight ahead.
        let material = sphere.firstMaterial
        material?.isDoubleSided = true
        material?.lightingModel = .constant
        material?.diffuse.wrapS = .repeat
        material?.diffuse.contentsTransform = SCNMatrix4Translate(SCNMatrix4MakeScale(-1, 1, 1), 1, 0, 0)
        let sphereNode = SCNNode(geometry: sphere)
        sphereNode.eulerAngles.y = .pi
        scene?.rootNode.addChildNode(sphereNode)

        addGestureRecognizer(UIPanGestureRecognizer(target: self, action: #selector(didPan)))
        addGestureRecognizer(UITapGestureRecognizer(target: self, action: #selector(didTap)))
    }

    // MARK: - Content

    func load(_ image: UIImage?) {
        sphere.firstMaterial?.diffuse.contents = image
    }

    /// Loads a video. It stays paused until `play()` is called.
    func load(from url: URL) {
        player = AVPlayer(url: url)
        sphere.firstMaterial?.diffuse.contents = player
        rendersContinuously = true
    }

    func play() {
        player?.play()
    }

    func pause() {
        player?.pause()
    }

    // MARK: - Looking around

    @objc private func didTap() {
        onTap?()
    }

    @objc private func didPan(_ pan: UIPanGestureRecognizer) {
        let translation = pan.translation(in: self)
        pan.setTranslation(.zero, in: self)

        // Dragging across the full height of the view turns the camera by its field of view.
        let radiansPerPoint = Float((cameraNode.camera?.fieldOfView ?? 60) * .pi / 180 / max(bounds.height, 1))
        yawNode.eulerAngles.y += Float(translation.x) * radiansPerPoint
        if !motion.isDeviceMotionActive {   // with motion on, tilting the device looks up and down
            let pitch = pitchNode.eulerAngles.x + Float(translation.y) * radiansPerPoint
            pitchNode.eulerAngles.x = max(-.pi / 2, min(.pi / 2, pitch))
        }
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()
        guard window != nil, motion.isDeviceMotionAvailable else {
            motion.stopDeviceMotionUpdates()
            return
        }
        motion.deviceMotionUpdateInterval = 1.0 / 60
        motion.startDeviceMotionUpdates(using: .xArbitraryZVertical, to: .main) { [weak self] deviceMotion, _ in
            guard let self = self, let attitude = deviceMotion?.attitude.quaternion else { return }
            self.look(with: attitude)
        }
    }

    private func look(with attitude: CMQuaternion) {
        // Core Motion measures with Z pointing up; SceneKit has Y pointing up.
        let zUpToYUp = simd_quatf(angle: -.pi / 2, axis: simd_float3(1, 0, 0))
        let device = simd_quatf(ix: Float(attitude.x), iy: Float(attitude.y), iz: Float(attitude.z), r: Float(attitude.w))
        let screen = simd_quatf(angle: screenAngle, axis: simd_float3(0, 0, 1))
        cameraNode.simdOrientation = zUpToYUp * device * screen

        // Start facing the centre of the media, whichever way the device points.
        if !isFacingCentre {
            let forward = cameraNode.simdOrientation.act(simd_float3(0, 0, -1))
            yawNode.eulerAngles.y = -atan2(-forward.x, -forward.z)
            pitchNode.eulerAngles.x = 0
            isFacingCentre = true
        }
    }

    /// How far the interface is rotated from the device's portrait position.
    private var screenAngle: Float {
        switch window?.windowScene?.interfaceOrientation {
        case .landscapeRight?: return -.pi / 2
        case .landscapeLeft?: return .pi / 2
        case .portraitUpsideDown?: return .pi
        default: return 0
        }
    }
}
