//
//  VaultTests.swift
//  VaultTests
//
//  Created by Carl Burnstein on 3/13/19.
//  Copyright © 2019 CASLS.
//

import XCTest
@testable import VAuLT

class VaultTests: XCTestCase {

    // MARK: - Card 26: QR Link Recognition and Parsing

    func testRecogniseValidVaultQRLink() {
        let qrString = "vault://quest/1?token=abcdef1234567890abcdef1234567890abcdef1234567890&gateway=http%3A%2F%2Flocalhost%3A3000"
        let parsed = LaunchLinkParser.parse(text: qrString)
        
        XCTAssertNotNil(parsed, "Valid vault:// deep link should be recognised")
        XCTAssertEqual(parsed?.questId, 1)
        XCTAssertEqual(parsed?.launchCode, "abcdef1234567890abcdef1234567890abcdef1234567890")
        XCTAssertEqual(parsed?.gatewayUrl, "http://localhost:3000")
    }

    func testRecogniseHttpsGatewayLink() {
        let urlString = "https://gateway.example.edu/quest/42?token=tok_secret_999&gateway=https%3A%2F%2Fgateway.example.edu"
        let parsed = LaunchLinkParser.parse(text: urlString)
        
        XCTAssertNotNil(parsed)
        XCTAssertEqual(parsed?.questId, 42)
        XCTAssertEqual(parsed?.launchCode, "tok_secret_999")
        XCTAssertEqual(parsed?.gatewayUrl, "https://gateway.example.edu")
    }

    func testRecogniseQueryQuestId() {
        let urlString = "vault://launch?questId=7&token=tok_query_7&gateway=http%3A%2F%2F192.168.1.50%3A8080"
        let parsed = LaunchLinkParser.parse(text: urlString)
        
        XCTAssertNotNil(parsed)
        XCTAssertEqual(parsed?.questId, 7)
        XCTAssertEqual(parsed?.launchCode, "tok_query_7")
        XCTAssertEqual(parsed?.gatewayUrl, "http://192.168.1.50:8080")
    }

    func testRecognisePlainStartCodeReturnsNil() {
        // Plain start codes should NOT be recognised as gateway launch links
        let plainCodes = ["START123", "4820", "QUEST-CHEM", "plain-code-value"]
        for code in plainCodes {
            let parsed = LaunchLinkParser.parse(text: code)
            XCTAssertNil(parsed, "Plain start code '\(code)' should return nil so downloadQuestByCode handles it")
        }
    }

    func testRejectMalformedLinks() {
        let malformed = [
            "vault://quest",                      // Missing quest ID, token, gateway
            "vault://quest/abc?token=1&gateway=g", // Non-numeric quest ID
            "vault://quest/1",                    // Missing token & gateway
            "vault://quest/1?token=abc",          // Missing gateway
            "vault://quest/1?gateway=http://g",   // Missing token
            "not-a-url",
            ""
        ]
        for text in malformed {
            let parsed = LaunchLinkParser.parse(text: text)
            XCTAssertNil(parsed, "Malformed string '\(text)' should return nil")
        }
    }

    // MARK: - Card 30: Launch Reference Storage & Persistence

    func testLaunchRefStorage() {
        let questId = 999
        let testRef = "test_launch_ref_12345678"
        
        ApiController.setLaunchRef(testRef, for: questId)
        let retrieved = ApiController.getLaunchRef(for: questId)
        XCTAssertEqual(retrieved, testRef, "Stored launch reference should match retrieved value")
        
        ApiController.setLaunchRef(nil, for: questId)
        let afterClear = ApiController.getLaunchRef(for: questId)
        XCTAssertNil(afterClear, "Cleared launch reference should return nil")
    }

    func testStartAttemptRouterPath() throws {
        let router = Router.startAttempt(quest_id: 15, launch_ref: "launch_ref_abc123")
        let request = try router.asURLRequest()
        
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertTrue(request.url?.path.hasSuffix("/start-attempt") ?? false)
    }
}
