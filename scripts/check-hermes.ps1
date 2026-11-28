<#
.SYNOPSIS
  Strictly read-only health check of the JoyUMKM <-> Hermes integration. Windows PowerShell 5.1+.
.DESCRIPTION
  Never POSTs a webhook, never restarts/recreates anything, never toggles a flag or scheduler, never prints secrets.
  Reports three independent states: INFRA_READY, INTEGRATION_READY, OPERATIONAL_ACTIVATION (BLOCKED/UNKNOWN).
  Exit code 0 only when INFRA_READY and INTEGRATION_READY are both true. Activation blockers never change the exit code.
.PARAMETER EnvFile
  Compose interpolation file (default: docker/hermes/.env, else the legacy .env next to the live compose). Values stay in memory.
#>
[CmdletBinding()]
param([string]$EnvFile)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'hermes-common.ps1')

$names = $null
try {
    $manifest = Get-HermesManifest
    $dockerOk = (Test-Command 'docker')
    $live = $false
    if ($dockerOk) { [void](Invoke-Native 'docker' @('info', '--format', '{{.ServerVersion}}')); $live = ($script:LastExit -eq 0) }
    Write-Host ('Check target: ' + $(if ($live) { 'LIVE runtime (running containers are inspected)' } else { 'STATIC validation only (Docker daemon not reachable)' })) -ForegroundColor Cyan

    # ---------------- INFRA ----------------
    $app = Test-AppUp
    Add-Check 'INFRA' 'Laravel /up' $(if ($app.Ok) { 'PASS' } else { 'FAIL' }) ("$($app.Url) HTTP $($app.Code)")

    $hashBad = @(Get-RepoHashes 'docker\hermes\workflows' $manifest.workflows.files)
    Add-Check 'INFRA' 'repo workflow assets match manifest' $(if ($hashBad.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($hashBad -join '; ')

    if (-not $live) {
        Add-Check 'INFRA' 'Docker daemon' 'FAIL' 'not reachable; runtime checks skipped (STATIC only)'
    } else {
        $envPath = Resolve-ComposeEnvFile $EnvFile
        $names = Set-ComposeProcessEnv -EnvFile $envPath -ValidateOnly
        [void](Invoke-Native 'docker' ((Get-ComposeArgs) + @('config', '--quiet')))
        Add-Check 'INFRA' 'compose.yaml valid (project jobsheet2)' $(if ($script:LastExit -eq 0) { 'PASS' } else { 'FAIL' })
        $diffs = @(Compare-ComposeToRuntime)
        Add-Check 'INFRA' 'compose equals live runtime (redacted structural)' $(if ($diffs.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($diffs -join '; ')
        foreach ($svc in $manifest.services.PSObject.Properties) {
            foreach ($p in $svc.Value.ports) {
                $port = [int](($p -split ':')[1])
                Add-Check 'INFRA' "port $p ($($svc.Value.container))" $(if (Test-TcpPort '127.0.0.1' $port) { 'PASS' } else { 'FAIL' })
            }
        }
        [void](Invoke-Native 'docker' @('volume', 'inspect', $manifest.volume))
        Add-Check 'INFRA' "volume $($manifest.volume) exists" $(if ($script:LastExit -eq 0) { 'PASS' } else { 'FAIL' })
        $labels = Get-ContainerLabels 'hermes-agent'
        $cf = ''; if ($labels -and $labels.PSObject.Properties['com.docker.compose.project.config_files']) { $cf = [string]$labels.'com.docker.compose.project.config_files' }
        Add-Check 'INFRA' 'compose labels' 'INFO' "live containers still reference: $cf (not migrated; no recreate performed)"
    }

    # ---------------- INTEGRATION ----------------
    $webhookUrl = Get-LaravelEnv 'HERMES_WEBHOOK_URL'
    $secret = Get-LaravelEnv 'HERMES_WEBHOOK_SECRET'
    $hmac = ''; if ($secret) { $hmac = Get-Sha256Hex $secret }
    $secret = $null
    $route = ''; if ($webhookUrl) { $route = ([uri]$webhookUrl).AbsolutePath.TrimStart('/') -replace '^webhooks/', '' }
    $proxy = Get-Content -LiteralPath (Join-Path $script:RepoRoot $manifest.proxy_asset) -Raw
    Add-Check 'INTEGRATION' 'Laravel webhook path allowed by proxy allowlist' $(if ($route -and $proxy -match [regex]::Escape("/webhooks/$route")) { 'PASS' } else { 'FAIL' }) "route=$route"

    $c = $null
    if ($live) { $c = Invoke-HermesCanary -ExpectHmacSha256 $hmac }
    if ($live -and -not $c) { Add-Check 'INTEGRATION' 'runtime canary executed' 'FAIL' 'no/invalid canary output' }
    elseif ($live) {
        Add-Check 'INTEGRATION' 'runtime canary executed (read-only, no model)' $(if (@($c.errors).Count -eq 0) { 'PASS' } else { 'FAIL' }) (@($c.errors) -join ',')
        $a = $c.analytics
        if ($a) {
            Add-Check 'INTEGRATION' 'database + principal' $(if ($a.database -eq $manifest.sql.database -and $a.principal -eq $manifest.sql.reader) { 'PASS' } else { 'FAIL' }) "$($a.database) / $($a.principal)"
            Add-Check 'INTEGRATION' 'TLS active, CA present' $(if ($a.tls_active -and $a.ca_present) { 'PASS' } else { 'FAIL' })
            Add-Check 'INTEGRATION' 'reader grants = SELECT on 3 views only' $(if ($a.grants_views_only) { 'PASS' } else { 'FAIL' })
            Add-Check 'INTEGRATION' 'views expose no identity columns' $(if ($a.no_identity_columns) { 'PASS' } else { 'FAIL' })
            $cnt = $a.counts
            Add-Check 'INTEGRATION' 'cohort view counts non-zero' $(if ($cnt.analytics_umkms -gt 0 -and $cnt.analytics_evaluations -gt 0 -and $cnt.analytics_six_factor_evaluations -gt 0) { 'PASS' } else { 'FAIL' }) "umkms=$($cnt.analytics_umkms) eval3d=$($cnt.analytics_evaluations) six=$($cnt.analytics_six_factor_evaluations)"
            if (-not $a.ssl_verify_identity) { Add-Check 'INTEGRATION' 'TLS hostname verification' 'WARN' 'ssl_verify_identity=false (known backlog; CA is verified)' }
        } else { Add-Check 'INTEGRATION' 'analytics adapter' 'FAIL' 'no analytics section' }
        if ($c.mysql_json) { Add-Check 'INTEGRATION' 'mysql.json permissions' $(if ($c.mysql_json.mode -eq '600') { 'PASS' } else { 'FAIL' }) "mode=$($c.mysql_json.mode)" }
        $b = @(Compare-RuntimeHashes $c.workflows $manifest.workflows.files 'workflows')
        Add-Check 'INTEGRATION' 'workflow definitions (7) match manifest' $(if ($b.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($b -join '; ')
        $b = @(Compare-RuntimeHashes $c.jmi $manifest.jmi.files 'jmi')
        Add-Check 'INTEGRATION' 'JMI code (incl. P2 scope guard) matches manifest' $(if ($b.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($b -join '; ')
        $b = @(Compare-RuntimeHashes $c.overlay $manifest.plugin.overlay_files 'overlay')
        Add-Check 'INTEGRATION' "visual-workflow overlay matches manifest (plugin $($c.plugin_version))" $(if ($b.Count -eq 0 -and $c.plugin_version -eq $manifest.plugin.version) { 'PASS' } else { 'FAIL' }) ($b -join '; ')
        $rt = $null; if ($route) { $rt = $c.webhook_routes.PSObject.Properties[$route] }
        Add-Check 'INTEGRATION' 'webhook route exists and HMAC key agrees with Laravel (compared locally)' $(if ($rt -and $rt.Value.enabled -and $rt.Value.hmac_matches_laravel) { 'PASS' } else { 'FAIL' }) "route=$route"
        foreach ($port in 8650, 8651) {
            try { $h = Invoke-WebRequest -Uri "http://127.0.0.1:$port/health" -UseBasicParsing -TimeoutSec 4; $ok = ($h.StatusCode -eq 200) } catch { $ok = $false }
            Add-Check 'INTEGRATION' "ingress health :$port (GET /health)" $(if ($ok) { 'PASS' } else { 'WARN' })
        }
    }

    # ---------------- ACTIVATION (reported, never gating) ----------------
    $blockers = New-Object System.Collections.ArrayList
    if ($c) {
        $f = $c.flags
        if ($f.JMI_EMAIL_DELIVERY_ENABLED -eq '1' -and $f.JMI_EMAIL_DELIVERY_PAUSED -eq '0' -and $f.JMI_EMAIL_STATUS_SYNC_ENABLED -eq '0') { [void]$blockers.Add('dev email delivery enabled/unpaused while status-sync disabled') }
        $running = @($c.cron | Where-Object { $_.enabled -eq $true })
        if ($running.Count -eq 0 -and @($c.cron).Count -gt 0) { [void]$blockers.Add("$(@($c.cron).Count) Hermes cron jobs paused") }
        if (-not $c.uat_pin_active) { [void]$blockers.Add('no active UAT scope pin (scope unconfirmed)') }
        if ($c.onboarding.enabled -eq $true) { [void]$blockers.Add("onboarding enabled mode=$($c.onboarding.mode)") }
    }
    $sig = Get-PendingSignature
    if ($sig) {
        $pendingOutbox = @($sig.outbox | Where-Object { $_.status -eq 'pending' })
        Add-Check 'ACTIVATION' 'pending outbox / queued jobs (ids and status only)' 'INFO' ("outbox pending ids=[" + (($pendingOutbox | ForEach-Object { $_.id }) -join ',') + "] jobs ids=[" + ((@($sig.jobs) | ForEach-Object { $_.id }) -join ',') + ']')
        if ($pendingOutbox.Count -gt 0 -or @($sig.jobs).Count -gt 0) { [void]$blockers.Add('pending outbox / queued jobs need operator reconciliation') }
    } else { Add-Check 'ACTIVATION' 'pending signature' 'WARN' 'unavailable (php/artisan/db)' }
    try {
        $tasks = @(Get-ScheduledTask -ErrorAction Stop | Where-Object { $_.TaskName -match 'JoyUMKM' })
        $dis = @($tasks | Where-Object { $_.State -eq 'Disabled' })
        Add-Check 'ACTIVATION' 'Windows tasks (JoyUMKM)' 'INFO' "$($tasks.Count) found, $($dis.Count) Disabled"
        if ($dis.Count -gt 0) { [void]$blockers.Add("$($dis.Count) Windows tasks Disabled") }
    } catch { Add-Check 'ACTIVATION' 'Windows tasks' 'WARN' 'cannot enumerate' }
    foreach ($bl in $blockers) { Add-Check 'ACTIVATION' 'blocker' 'INFO' $bl }

    $infra = Test-GroupPassed 'INFRA'
    $integ = $live -and (Test-GroupPassed 'INTEGRATION')
    Write-Host ''
    Write-Host ('INFRA_READY:                  ' + $infra)
    Write-Host ('INTEGRATION_READY:            ' + $integ + $(if ($live) { ' (bounded read-only, live runtime)' } else { ' (NOT evaluated: static only)' }))
    Write-Host ('OPERATIONAL_ACTIVATION:       ' + $(if ($blockers.Count -gt 0) { 'BLOCKED (' + $blockers.Count + ' blockers listed above)' } elseif ($live) { 'NOT BLOCKED BY THESE CHECKS (still requires separate authorization)' } else { 'UNKNOWN' }))
    Write-Host 'Production:                   NOT DEPLOYED (out of scope)'
    if ($infra -and $integ) { exit 0 } else { exit 1 }
} catch {
    Write-Host ("check-hermes failed: " + $_.Exception.GetType().Name + ' - ' + $_.Exception.Message) -ForegroundColor Red
    exit 2
} finally {
    if ($names) { Clear-ComposeProcessEnv $names }
}
