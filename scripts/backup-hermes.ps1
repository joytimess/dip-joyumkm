<#
.SYNOPSIS
  Operator-invoked, read-only backup of Hermes CONFIGURATION and inventory (not a data-volume snapshot). Windows PowerShell 5.1+.
.DESCRIPTION
  Writes to -Destination (must be OUTSIDE the repository) into a new timestamped folder with an owner-only ACL:
    compose.yaml + docker/hermes (manifest, templates, workflows), the legacy compose file, redacted runtime inventory
    (container ids, image, mounts, ports; NO environment values), live workflow definitions (docker cp, read-only),
    redacted key-name views of mysql.json / state env files, SHA256SUMS.txt.
  It does NOT copy credentials, mysql.json values, state/*.env values, SQLite/state DBs, or the hermes_data volume:
  a consistent volume snapshot needs the services stopped (downtime) and is never done silently. Nothing is restored or deleted.
.PARAMETER Destination
  Existing or new directory outside the repository, e.g. C:\Users\<you>\backups\hermes.
#>
[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$Destination)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'hermes-common.ps1')

try {
    $destFull = [IO.Path]::GetFullPath($Destination)
    if ($destFull.TrimEnd('\').StartsWith($script:RepoRoot.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)) { throw 'Destination must be outside the repository (backups may contain sensitive metadata).' }
    if (-not (Test-Command 'docker')) { throw 'docker not found' }
    $dir = Join-Path $destFull ('hermes-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    if (Test-Path -LiteralPath $dir) { throw "refusing to overwrite $dir" }
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    & icacls.exe $dir /inheritance:r /grant:r "$($env:USERDOMAIN)\$($env:USERNAME):(OI)(CI)(F)" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'could not restrict ACL' }

    Copy-Item -LiteralPath $script:ComposeFile -Destination $dir
    Copy-Item -LiteralPath $script:HermesDir -Destination (Join-Path $dir 'docker-hermes') -Recurse -Exclude '.env', 'generated'
    $labels = Get-ContainerLabels 'hermes-agent'
    if ($labels -and $labels.PSObject.Properties['com.docker.compose.project.config_files']) {
        $legacy = [string]$labels.'com.docker.compose.project.config_files'
        if (Test-Path -LiteralPath $legacy) { Copy-Item -LiteralPath $legacy -Destination (Join-Path $dir 'legacy-docker-compose.yml') }
    }

    $inv = @()
    foreach ($n in 'hermes-agent', 'hermes-workflow-ingress', 'hermes-webhook-proxy') {
        $rt = Get-RuntimeInspect $n
        if ($rt) {
            $inv += [pscustomobject]@{
                name = $n; id = $rt.Id; image = $rt.Image; restart = $rt.HostConfig.RestartPolicy.Name; privileged = $rt.HostConfig.Privileged
                mounts = @($rt.Mounts | ForEach-Object { '{0}|{1}->{2}|rw={3}' -f $_.Type, $(if ($_.Type -eq 'volume') { $_.Name } else { $_.Source }), $_.Destination, $_.RW })
                networks = @($rt.NetworkSettings.Networks.PSObject.Properties.Name)
                env_key_names = @($rt.Config.Env | ForEach-Object { ($_ -split '=', 2)[0] } | Sort-Object)
            }
        }
    }
    $inv | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $dir 'runtime-inventory.json') -Encoding UTF8

    $wf = Join-Path $dir 'live-workflow-designs'
    [void](Invoke-Native 'docker' @('cp', 'hermes-agent:/opt/data/workflow-designs', $wf))
    if ($script:LastExit -ne 0) { Write-Host 'WARN: live workflow copy failed' -ForegroundColor Yellow }

    $c = Invoke-HermesCanary
    if ($c) { ($c | Select-Object analytics, flags, cron, onboarding, mysql_json, plugin_version, uat_pin_active | ConvertTo-Json -Depth 6) | Set-Content -LiteralPath (Join-Path $dir 'state-signature.json') -Encoding UTF8 }

    $sums = Get-ChildItem -LiteralPath $dir -Recurse -File | Where-Object { $_.Name -ne 'SHA256SUMS.txt' } | ForEach-Object { '{0}  {1}' -f (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant(), $_.FullName.Substring($dir.Length + 1) }
    $sums | Set-Content -LiteralPath (Join-Path $dir 'SHA256SUMS.txt') -Encoding ASCII
    Write-Host "Backup written: $dir ($(@($sums).Count) files, owner-only ACL, checksums recorded)" -ForegroundColor Green
    Write-Host 'NOT included: hermes_data volume contents and secrets. Volume snapshot requires planned downtime; restore is targeted and was NOT rehearsed.' -ForegroundColor Yellow
    exit 0
} catch {
    Write-Host ('backup-hermes failed: ' + $_.Exception.Message) -ForegroundColor Red
    exit 2
}
