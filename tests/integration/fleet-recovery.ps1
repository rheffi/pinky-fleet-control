$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8080'
function Docker-Check {
    param([string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed: $Arguments" }
}
function Snapshot { Invoke-RestMethod "$base/api/v1/snapshot" -TimeoutSec 10 }
function Read-Run($id) { (Invoke-RestMethod "$base/api/v1/runs/$id" -TimeoutSec 10).run }
function Wait-Condition([scriptblock]$Condition, [string]$Name) {
    for ($i=0; $i -lt 12; $i++) {
        if (& $Condition) { return }
        Start-Sleep -Seconds 1
    }
    throw "Timed out: $Name"
}
$initial = Snapshot
if ($initial.mode -ne 'sample') { throw 'Recovery verification is restricted to sample mode.' }
if ($initial.active_run) { throw 'Finish the active sample run before recovery verification.' }
$probeJson = & docker compose exec -T app php artisan app:check-environment --write
if ($LASTEXITCODE -ne 0) { throw 'Could not create the persistence probe' }
$probe = $probeJson | ConvertFrom-Json
$page = Invoke-WebRequest $base -SessionVariable session
$token = [regex]::Match($page.Content, 'name="csrf-token" content="([^"]+)"').Groups[1].Value
$headers = @{'X-CSRF-TOKEN'=$token; Accept='application/json'}
$body = @{request_id=[guid]::NewGuid().ToString();map_id='sample-map';map_version='1';assignments=@(@{robot_id='eed0';goal_id='eed0-right'},@{robot_id='648d';goal_id='648d-right'},@{robot_id='62b2';goal_id='62b2-right'})}
$created = Invoke-RestMethod "$base/api/v1/runs" -Method Post -Body ($body | ConvertTo-Json -Depth 5) -ContentType 'application/json' -Headers $headers -WebSession $session
$id = $created.run.id
Wait-Condition { (Read-Run $id).status -eq 'running' } 'run starts'
try {
    Docker-Check @('compose','--profile','sample','stop','-t','1','simulator')
    $frozen = (Snapshot).robots.pose | ConvertTo-Json -Depth 5 -Compress
    Start-Sleep -Seconds 4
    if ((Snapshot).executor.connection_state -ne 'stale') { throw 'Expected stale executor after 4 seconds' }
    Start-Sleep -Seconds 7
    if ((Snapshot).executor.connection_state -ne 'offline') { throw 'Expected offline executor after 11 seconds' }
    if ($frozen -ne ((Snapshot).robots.pose | ConvertTo-Json -Depth 5 -Compress)) { throw 'Read requests moved robots' }
    Docker-Check @('compose','--profile','sample','up','-d','simulator')
    Wait-Condition { (Snapshot).executor.connection_state -eq 'online' } 'executor recovers'
    if ((Read-Run $id).status -ne 'interrupted') { throw 'Expected interrupted after restart' }
    if ($frozen -ne ((Snapshot).robots.pose | ConvertTo-Json -Depth 5 -Compress)) { throw 'Restart moved robots automatically' }
    $body.request_id = [guid]::NewGuid().ToString()
    $denied = Invoke-WebRequest "$base/api/v1/runs" -Method Post -Body ($body | ConvertTo-Json -Depth 5) -ContentType 'application/json' -Headers $headers -WebSession $session -SkipHttpErrorCheck
    if ($denied.StatusCode -ne 409) { throw 'Interrupted run must block a new run' }
    $stop = Invoke-RestMethod "$base/api/v1/runs/$id/stop" -Method Post -Body (@{request_id=[guid]::NewGuid().ToString()} | ConvertTo-Json) -ContentType 'application/json' -Headers $headers -WebSession $session
    if ($stop.run.status -ne 'stopping') { throw 'Stop must first be accepted' }
    Wait-Condition { (Read-Run $id).status -eq 'cancelled' } 'sample stop acknowledged'
    $saved = Read-Run $id | ConvertTo-Json -Depth 12 -Compress
    $events = (Invoke-RestMethod "$base/api/v1/runs/$id/events").events | ConvertTo-Json -Depth 8 -Compress
    Docker-Check @('compose','exec','-T','app','php','artisan','db:seed','--class=FleetSeeder','--force')
    if ($saved -ne (Read-Run $id | ConvertTo-Json -Depth 12 -Compress)) { throw 'Seed changed run history' }
    Docker-Check @('compose','--profile','sample','stop','-t','2')
    Docker-Check @('compose','--profile','sample','up','-d','--wait','--wait-timeout','90')
    if ($saved -ne (Read-Run $id | ConvertTo-Json -Depth 12 -Compress)) { throw 'Run history changed after restart' }
    if ($events -ne ((Invoke-RestMethod "$base/api/v1/runs/$id/events").events | ConvertTo-Json -Depth 8 -Compress)) { throw 'Events changed after restart' }
    Docker-Check @('compose','exec','-T','app','php','artisan','app:check-environment',("--expect=" + $probe.token))
    [pscustomobject]@{run_id=$id;stale=$true;offline=$true;restart_interrupted=$true;position_preserved=$true;cancelled=$true;seed_preserved=$true;all_services_restart_preserved=$true;passed=$true} | ConvertTo-Json -Compress | Tee-Object -FilePath work/fleet-recovery-result.json
} finally {
    Docker-Check @('compose','--profile','sample','up','-d','simulator')
}
