$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
$base = 'http://127.0.0.1:8080'
$clients = @()
$payloads = @()
try {
    foreach ($index in 0..1) {
        $handler = [System.Net.Http.HttpClientHandler]::new()
        $handler.CookieContainer = [System.Net.CookieContainer]::new()
        $client = [System.Net.Http.HttpClient]::new($handler)
        $clients += $client
        $client.Timeout = [TimeSpan]::FromSeconds(15)
        $html = $client.GetStringAsync($base).GetAwaiter().GetResult()
        $token = [regex]::Match($html, 'name="csrf-token" content="([^"]+)"').Groups[1].Value
        if (-not $token) { throw 'CSRF token missing' }
        $client.DefaultRequestHeaders.Add('X-CSRF-TOKEN', $token)
        $client.DefaultRequestHeaders.Add('Accept', 'application/json')
        $payloads += (@{
            request_id=[guid]::NewGuid().ToString(); map_id='sample-map'; map_version='1'
            assignments=@(@{robot_id='eed0';goal_id='eed0-right'},@{robot_id='648d';goal_id='648d-right'},@{robot_id='62b2';goal_id='62b2-right'})
        } | ConvertTo-Json -Depth 5 -Compress)
    }
    $state = $clients[0].GetStringAsync("$base/api/v1/snapshot").GetAwaiter().GetResult() | ConvertFrom-Json
    if ($state.active_run) { throw 'Finish the active sample run before the concurrency test.' }
    $tasks = @(foreach ($index in 0..1) {
        $clients[$index].PostAsync("$base/api/v1/runs", [System.Net.Http.StringContent]::new($payloads[$index], [Text.Encoding]::UTF8, 'application/json'))
    })
    [System.Threading.Tasks.Task]::WaitAll([System.Threading.Tasks.Task[]]$tasks)
    $codes = @($tasks | ForEach-Object { [int]$_.Result.StatusCode })
    if (($codes | Sort-Object) -join ',' -ne '201,409') { throw "Unexpected statuses: $codes" }
    $winner = [Array]::IndexOf($codes, 201)
    $result = $tasks[$winner].Result.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
    $replay = $clients[$winner].PostAsync("$base/api/v1/runs", [System.Net.Http.StringContent]::new($payloads[$winner], [Text.Encoding]::UTF8, 'application/json')).GetAwaiter().GetResult()
    $replayBody = $replay.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
    if ([int]$replay.StatusCode -ne 200 -or -not $replayBody.replayed -or $replayBody.run.id -ne $result.run.id) { throw 'Idempotency failed' }
    $stopBody = @{request_id=[guid]::NewGuid().ToString()} | ConvertTo-Json -Compress
    $stop = $clients[$winner].PostAsync("$base/api/v1/runs/$($result.run.id)/stop", [System.Net.Http.StringContent]::new($stopBody, [Text.Encoding]::UTF8, 'application/json')).GetAwaiter().GetResult()
    $stopResult = $stop.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
    if ([int]$stop.StatusCode -ne 202 -or $stopResult.run.status -ne 'stopping') { throw 'Stop acceptance failed' }
    [pscustomobject]@{check='mysql-http-race';statuses=$codes;replay=200;run_id=$result.run.id;stop_status=$stopResult.run.status;passed=$true} | ConvertTo-Json -Compress
} finally {
    foreach ($client in $clients) { $client.Dispose() }
}
