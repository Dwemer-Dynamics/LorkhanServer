[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(
        'status.snapshot','god_mode.set','collision.set','ai.set','mwscript.set','shader_hot_reload.set',
        'shaders.reload','render_mode.toggle','player.inventory.add','player.inventory.remove',
        'player.spell.add','player.spell.remove','player.vitals.restore','player.stat.set',
        'player.attribute.set','player.skill.set','player.level.set','player.bounty.set',
        'player.teleport','player.scale.set','world.time.advance','world.timescale.set','world.weather.set',
        'target.actor.kill','target.actor.restore','target.teleport.to_player','target.scale.set'
    )]
    [string] $Command,

    [string] $ParametersJson = '{}',
    [string] $SessionId,
    [string] $BaseUrl = 'http://127.0.0.1:7514/LORKHANserver',
    [ValidateRange(1, 60)]
    [int] $TimeoutSeconds = 35
)

$ErrorActionPreference = 'Stop'
$serviceRoot = $BaseUrl.TrimEnd('/')
$parsedParameters = $ParametersJson | ConvertFrom-Json
if ($null -eq $parsedParameters -or $parsedParameters -isnot [pscustomobject]) {
    throw 'ParametersJson must be a JSON object.'
}
$parameters = [ordered]@{}
foreach ($property in $parsedParameters.PSObject.Properties) { $parameters[$property.Name] = $property.Value }

# Opening the local management page creates the same short-lived browser session used by the UI.
$null = Invoke-WebRequest -Uri "$serviceRoot/ui/home.php" -SessionVariable lorkhanWebSession -UseBasicParsing
$cookies = $lorkhanWebSession.Cookies.GetCookies([Uri]$serviceRoot)
$csrf = ($cookies | Where-Object Name -eq 'lorkhan_csrf' | Select-Object -First 1).Value
if ([string]::IsNullOrWhiteSpace($csrf)) { throw 'LORKHAN management CSRF cookie was not issued.' }

$api = "$serviceRoot/manage/api/v1"
$sessions = Invoke-RestMethod -Uri "$api/debug-command-sessions" -WebSession $lorkhanWebSession -Headers @{ Accept = 'application/json' }
if ([string]::IsNullOrWhiteSpace($SessionId)) {
    $active = @($sessions.items | Where-Object supported | Select-Object -First 1)
    if ($active.Count -eq 0) { throw 'No connected LORKHAN game supports debug.commands.v1.' }
    $SessionId = [string]$active[0].session_id
}

$body = @{ session_id = $SessionId; name = $Command; parameters = $parameters } | ConvertTo-Json -Depth 8 -Compress
$queued = Invoke-RestMethod -Method Post -Uri "$api/debug-commands" -WebSession $lorkhanWebSession -ContentType 'application/json' `
    -Headers @{ 'X-CSRF-Token' = $csrf; Accept = 'application/json' } -Body $body
$commandId = [string]$queued.command.command_id
$deadline = [DateTimeOffset]::UtcNow.AddSeconds($TimeoutSeconds)

do {
    Start-Sleep -Milliseconds 250
    $history = Invoke-RestMethod -Uri "$api/debug-commands?session_id=$([Uri]::EscapeDataString($SessionId))" `
        -WebSession $lorkhanWebSession -Headers @{ Accept = 'application/json' }
    $result = @($history.items | Where-Object command_id -eq $commandId | Select-Object -First 1)
    if ($result.Count -eq 1 -and $result[0].state -in @('succeeded','failed','rejected','expired')) {
        $result[0] | ConvertTo-Json -Depth 8
        if ($result[0].state -ne 'succeeded') { exit 2 }
        exit 0
    }
} while ([DateTimeOffset]::UtcNow -lt $deadline)

throw "Timed out waiting for LORKHAN debug command $commandId."
