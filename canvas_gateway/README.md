# VAuLT → Canvas gateway (Node.js)

Standalone Node.js 20+ service, using built-in HTTP/fetch only. No npm dependencies or schema migrations.

## Start locally (PowerShell)

```powershell
cd canvas_gateway
Copy-Item .env.example .env
Copy-Item mappings.example.json mappings.json
# Edit .env and mappings.json with your test instance credentials and IDs.
npm start
```

VAULT_BASE_URL points to the VAuLT **frontend API** (normally port 80), not the editor on port 89. CANVAS_BASE_URL is your Canvas instance URL. The Canvas access token must belong to an account permitted to grade the mapped assignment. Keep credentials in .env; neither .env nor local mappings.json is committed.

`mappings` maps VAuLT quest IDs to Canvas course/assignment IDs. `users` maps VAuLT user IDs to Canvas student IDs. Example IDs are placeholders, not real users. Mapping and credentials are loaded at startup; restart after editing them.

## 1. Save progress in VAuLT

Obtain the existing VAuLT OAuth access_token from `/api/authenticate` (the existing login accepts form data). The new endpoint uses that token as a Bearer credential, with active OAuth state and active user required.

```powershell
$vaultToken = 'YOUR_VAULT_ACCESS_TOKEN'
$headers = @{ Authorization = "Bearer $vaultToken" }
Invoke-RestMethod -Method Post -Uri 'http://localhost/api/v1/quests/1/progress' `
  -Headers $headers -ContentType 'application/json' `
  -Body '{"task_id":2,"is_complete":true}'
Invoke-RestMethod -Uri 'http://localhost/api/v1/quests/1/progress' -Headers $headers
```

POST changes only the authenticated user's specified task. The task must be active and belong to the active quest. Use `false` to reset a task. GET/POST returns `data` with user_id, quest_id, completed_tasks, total_tasks, is_complete, progress_percent. Repeated POSTs update existing records. Progress counts distinct completed active tasks; an empty quest is not complete. Access to active quests follows the existing mobile API policy; this endpoint does not introduce enrollment restrictions.

## 2. Send the saved progress to Canvas

Call this endpoint from a trusted server/tool holding the gateway key:

```powershell
$gatewayHeaders = @{ Authorization = 'Bearer YOUR_GATEWAY_API_KEY' }
$body = @{ quest_id = 1; vault_access_token = $vaultToken } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:3001/api/v1/grades/sync' `
  -Headers $gatewayHeaders -ContentType 'application/json' -Body $body
```

The gateway GETs progress from VAuLT using the supplied user's token, resolves the Canvas identity from server configuration, and PUTs `submission[posted_grade]` as a percentage to `/api/v1/courses/:course_id/assignments/:assignment_id/submissions/:user_id`. See the [official Canvas Submissions API](https://developerdocs.instructure.com/services/canvas/resources/submissions). A quest with 3/4 completed tasks sends `75%`; Canvas converts it to the assignment's points. Use a points/percentage-compatible assignment; partial percentages are not suitable for pass/fail assignments.

This is an explicit sync: saving progress does not automatically call Canvas. If Canvas fails, saved VAuLT progress remains intact; repeat the sync request to retry. Each sync overwrites the mapped assignment's grade with the current completion percentage, including a lower grade after reset. Do not map multiple quests to the same assignment unless that overwrite is intended. There is no background queue or sync history.

Completion is self-reported, matching VAuLT's existing task model; this is a test integration, not verified assessment scoring. Existing legacy task-writing endpoints remain unchanged. Assess their authorization and introduce server-verified scoring before using completion for consequential grades.

`GET /health` checks that the gateway is running (not upstream connectivity). Errors use JSON `{ "error": "..." }`: 400 invalid input, 401 authentication, 404 missing route/quest, 405 method, 413 oversized body, 415 content type, 422 missing mapping/invalid progress, 502 upstream failure. Upstream calls time out after 10 seconds and do not follow redirects. Tokens and raw upstream responses are not logged or returned.

The gateway binds to 127.0.0.1 by default. For deployment use HTTPS between clients, gateway, VAuLT and Canvas; keep the gateway key on trusted servers. No browser CORS or LTI launch/OAuth installation flow is included.

## Verification

```powershell
npm test
```

Tests use mocked VAuLT/Canvas responses and a real local HTTP gateway. For a live smoke test: save an active task, verify GET progress, sync to a Canvas sandbox assignment, and check the resulting grade in Canvas. Also verify another user's token cannot change your progress, wrong-quest task IDs return 404, and a repeated save does not create an additional user_task record.
