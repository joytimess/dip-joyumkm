<#
.SYNOPSIS
  Reproducible, idempotent setup of the Docker-managed Hermes dependency for JoyUMKM (local development only).
  Windows PowerShell 5.1+. Laravel/Vue/PHP/MySQL stay native (Laragon); only Hermes runs in Docker.
.PARAMETER Mode
  Auto   (default) classify; existing deployment -> Adopt; nothing present -> prints the Fresh plan and stops; anything else -> stop.
  Adopt  existing deployment: validate compose == runtime and assets, report drift. NEVER runs up/down/pull/recreate, writes nothing.
  Fresh  new workstation: creates network+volume, provisions analytics SQL, stages pinned assets, starts services once. Needs -Mode Fresh explicitly.
.PARAMETER Plan
  Dry run. Performs only read-only checks and prints what would happen. Creates nothing, writes nothing, imports nothing.
.PARAMETER EnvFile
  Compose interpolation file. Default: docker/hermes/.env, else the legacy .env next to the live compose (read in place, never copied).
.PARAMETER DbAdminUser
  Local MySQL administrator for Fresh SQL provisioning (default root). The password is prompted as a masked secret and never stored.
.PARAMETER Yes
  Skip the interactive FRESH confirmation (Fresh only).
.NOTES
  Exit codes: 0 ok, 1 checks failed, 2 blocked/conflict/error. No destructive/reset option exists by design.
  Fresh mode is IMPLEMENTED BUT NOT RUN in the authoring session (see docs/development/reproducible-hermes-infrastructure).
#>
[CmdletBinding()]
param(
    [ValidateSet('Auto', 'Adopt', 'Fresh')][string]$Mode = 'Auto',
    [switch]$Plan,
    [string]$EnvFile,
    [string]$DbAdminUser = 'root',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'hermes-common.ps1')

function Complete-Setup { $script:Done = $true; throw [System.OperationCanceledException]::new('done') }
function Stop-Setup { param([string]$Why, [int]$Code = 2) Write-Host "STOP: $Why" -ForegroundColor Red; $script:ExitCode = $Code; throw [System.OperationCanceledException]::new($Why) }

function Get-MySqlExe {
    $cmd = Get-Command mysql.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $hit = Get-ChildItem 'C:\laragon\bin\mysql\*\bin\mysql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1
    if ($hit) { return $hit.FullName }
    return $null
}

function Get-Classification {
    $names = 'hermes-agent', 'hermes-workflow-ingress', 'hermes-webhook-proxy'
    $found = @($names | Where-Object { $null -ne (Get-RuntimeInspect $_) })
    [void](Invoke-Native 'docker' @('volume', 'inspect', 'hermes_data')); $vol = ($script:LastExit -eq 0)
    $labelsOk = $false
    if ($found.Count -eq 3) {
        $labelsOk = $true
        foreach ($n in $names) { $l = Get-ContainerLabels $n; if (-not $l -or $l.'com.docker.compose.project' -ne 'jobsheet2') { $labelsOk = $false } }
    }
    if ($found.Count -eq 3 -and $vol -and $labelsOk) { return 'existing' }
    if ($found.Count -eq 0 -and -not $vol) {
        $busy = @(8642, 9119, 8644, 8650, 8651 | Where-Object { Test-TcpPort '127.0.0.1' $_ })
        if ($busy.Count -gt 0) { return "conflict: ports in use by another process ($($busy -join ','))" }
        return 'fresh'
    }
    return "conflict: partial or foreign topology (containers found=$($found.Count), volume=$vol, project labels ok=$labelsOk)"
}

function New-RandomSecret { param([int]$Bytes = 32) $b = New-Object byte[] $Bytes; $r = [Security.Cryptography.RandomNumberGenerator]::Create(); $r.GetBytes($b); $r.Dispose(); return ([Convert]::ToBase64String($b) -replace '[+/=]', 'x') }

function Protect-File { param([string]$Path) & icacls.exe $Path /inheritance:r /grant:r "$($env:USERDOMAIN)\$($env:USERNAME):(F)" | Out-Null; if ($LASTEXITCODE -ne 0) { throw 'icacls failed' } }

function Invoke-MySql {
    param([string]$Sql, [string]$Defaults, [string]$Exe)
    $o = Invoke-Native $Exe @("--defaults-extra-file=$Defaults", '--batch', '--skip-column-names') -StdIn $Sql
    if ($script:LastExit -ne 0) { throw 'mysql command failed (details withheld: may contain credentials)' }
    return $o
}

function Invoke-AdoptChecks {
    $manifest = Get-HermesManifest
    Add-Check 'ADOPT' 'mode' 'INFO' 'existing deployment: no up/down/pull/recreate/restart is issued; nothing is written'
    $envPath = Resolve-ComposeEnvFile $EnvFile
    Add-Check 'ADOPT' 'compose env source' $(if ($envPath) { 'INFO' } else { 'WARN' }) $(if ($envPath) { "$envPath (read in memory only)" } else { 'none found; placeholders used (value comparison will report differences)' })
    $names = Set-ComposeProcessEnv -EnvFile $envPath -ValidateOnly
    try {
        [void](Invoke-Native 'docker' ((Get-ComposeArgs) + @('config', '--quiet')))
        if ($script:LastExit -ne 0) { Stop-Setup 'compose.yaml failed validation' }
        Add-Check 'ADOPT' 'compose.yaml valid' 'PASS'
        $diffs = @(Compare-ComposeToRuntime)
        if ($diffs.Count -gt 0) {
            foreach ($d in $diffs) { Add-Check 'ADOPT' 'drift' 'FAIL' $d }
            Stop-Setup 'compose.yaml is not equivalent to the live runtime: migration BLOCKED pending operator-approved maintenance (canonical file stays staged)' 1
        }
        Add-Check 'ADOPT' 'compose.yaml equivalent to live runtime' 'PASS' 'repo compose.yaml is now the canonical definition; labels still point at the legacy file'
    } finally { Clear-ComposeProcessEnv $names }
    $bad = @(Get-RepoHashes 'docker\hermes\workflows' $manifest.workflows.files)
    $bad += @(Get-RepoHashes 'docker\hermes\scripts\jmi' $manifest.jmi.files)
    $bad += @(Get-RepoHashes 'docker\hermes\scripts\visual-workflow-overlay' $manifest.plugin.overlay_files)
    Add-Check 'ADOPT' 'versioned assets match manifest' $(if ($bad.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($bad -join '; ')
    if ($bad.Count -gt 0) { Stop-Setup 'repository assets differ from manifest' 1 }
}

$script:ExitCode = 0
$script:Done = $false
try {
    Write-Host "setup-hermes: mode=$Mode plan=$([bool]$Plan) repo=$script:RepoRoot" -ForegroundColor Cyan
    # ---- 1. host prerequisites (read-only) ----
    if ($PSVersionTable.PSVersion.Major -lt 5) { Stop-Setup 'Windows PowerShell 5.1+ required' }
    if (-not (Test-Command 'docker')) { Stop-Setup 'docker CLI not found (install/start Docker Desktop)' }
    [void](Invoke-Native 'docker' @('info', '--format', '{{.ServerVersion}}'))
    if ($script:LastExit -ne 0) { Stop-Setup 'Docker daemon not reachable (start Docker Desktop)' }
    [void](Invoke-Native 'docker' @('compose', 'version', '--short'))
    if ($script:LastExit -ne 0) { Stop-Setup 'docker compose plugin not available' }
    Add-Check 'HOST' 'Docker + Compose' 'PASS'
    $app = Test-AppUp
    Add-Check 'HOST' 'Laravel /up (native app)' $(if ($app.Ok) { 'PASS' } else { 'FAIL' }) "$($app.Url) HTTP $($app.Code)"
    if (-not $app.Ok) { Stop-Setup 'native Laravel app is not up: run the standard Laravel prerequisites first (README)' }
    $appEnv = Get-LaravelEnv 'APP_ENV'; $db = Get-LaravelEnv 'DB_DATABASE'
    if ($appEnv -ne 'local') { Stop-Setup "APP_ENV is '$appEnv': this tooling is local development only" }
    $manifest = Get-HermesManifest
    if ($db -ne $manifest.sql.database) { Stop-Setup "Laravel DB_DATABASE is '$db', expected '$($manifest.sql.database)': refusing to provision against another schema" }
    Add-Check 'HOST' 'local target' 'PASS' "APP_ENV=local, database=$db"

    # ---- 2. classification ----
    $class = Get-Classification
    Add-Check 'CLASSIFY' 'resource identity' 'INFO' $class
    if ($class -like 'conflict*') { Stop-Setup "$class. Nothing was changed." }
    $effective = $Mode
    if ($Mode -eq 'Auto') { $effective = if ($class -eq 'existing') { 'Adopt' } else { 'FreshPlanOnly' } }
    if ($effective -eq 'Adopt' -and $class -ne 'existing') { Stop-Setup 'Adopt requires the existing 3-container deployment; none was found. Use -Mode Fresh on a new workstation.' }
    if ($effective -eq 'Fresh' -and $class -ne 'fresh') { Stop-Setup 'Fresh requires NO existing hermes containers, NO hermes_data volume and free ports. It never takes over existing resources.' }

    if ($effective -eq 'Adopt') {
        Invoke-AdoptChecks
        Write-Host ''
        Write-Host 'Adoption verified (no runtime change). Running the read-only integration check...' -ForegroundColor Cyan
        & (Join-Path $PSScriptRoot 'check-hermes.ps1') -EnvFile $EnvFile
        $script:ExitCode = $LASTEXITCODE
        Complete-Setup
    }

    # ---- 3. Fresh (also the plan for Auto on an empty workstation) ----
    $mysql = Get-MySqlExe
    $steps = @(
        "1. verify prerequisites: mysql.exe ($(if ($mysql) { 'found' } else { 'MISSING' })), python+pip (host), git, Docker",
        "2. create external network 'joyumkm-hermes-webhook' and volume 'hermes_data' (only if absent)",
        "3. SQL: inspect, then create ONLY absent objects from $($manifest.sql.path) (reader password generated; DBA password prompted, masked)",
        '4. stage pinned assets: plugin @ pinned upstream commit + overlay, JMI code, 7 workflows, python deps (pinned), safe config templates (side effects OFF)',
        '5. one transient `docker run --rm` copies the stage into hermes_data and fixes ownership/modes (no permanent init container)',
        '6. docker compose up -d once (pinned image digest), bounded wait for ports',
        '7. run check-hermes.ps1; list remaining MANUAL prerequisites (model login, plugin enable, webhook subscriptions, paused crons, secrets)'
    )
    $steps | ForEach-Object { Write-Host "  PLAN $_" }
    foreach ($tool in 'git', 'python') { if (-not (Test-Command $tool)) { Add-Check 'FRESH' "host tool $tool" 'FAIL' 'required for staging' } }
    $stageBad = @(Get-RepoHashes 'docker\hermes\workflows' $manifest.workflows.files)
    $stageBad += @(Get-RepoHashes 'docker\hermes\scripts\jmi' $manifest.jmi.files)
    $stageBad += @(Get-RepoHashes 'docker\hermes\scripts\visual-workflow-overlay' $manifest.plugin.overlay_files)
    Add-Check 'FRESH' 'versioned assets match manifest' $(if ($stageBad.Count -eq 0) { 'PASS' } else { 'FAIL' }) ($stageBad -join '; ')
    if ($Plan -or $effective -eq 'FreshPlanOnly') {
        Write-Host ''
        Write-Host $(if ($Plan) { 'PLAN ONLY: nothing created, written or imported.' } else { 'Nothing present: re-run with -Mode Fresh to create it. Nothing was changed.' }) -ForegroundColor Yellow
        $script:ExitCode = $(if (@($script:Results | Where-Object { $_.Status -eq 'FAIL' }).Count -gt 0) { 1 } else { 0 })
        Complete-Setup
    }
    if (-not $mysql) { Stop-Setup 'mysql.exe not found (Laragon: C:\laragon\bin\mysql\...\bin)' }
    if ($stageBad.Count -gt 0) { Stop-Setup 'asset hash mismatch; refusing to stage' 1 }
    if (-not $Yes) { $a = Read-Host 'Type FRESH to create the Hermes network, volume, SQL principals and containers'; if ($a -ne 'FRESH') { Stop-Setup 'not confirmed' } }

    $stage = Join-Path $script:HermesDir 'generated\stage'
    $defaults = Join-Path $env:TEMP ('jmy-mysql-' + [guid]::NewGuid().ToString('N') + '.cnf')
    $dbSecure = $null; $readerPw = $null
    try {
        # secrets: generated or prompted; never printed, never passed as argv
        $local = Join-Path $script:HermesDir '.env'
        if (-not (Test-Path -LiteralPath $local)) {
            New-Item -ItemType File -Path $local -Force | Out-Null; Protect-File $local
            $lines = @('HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=' + (New-RandomSecret), 'HERMES_DASHBOARD_BASIC_AUTH_SECRET=' + (New-RandomSecret), 'JOYUMKM_ONBOARDING_LIVE_ENABLED=0', 'JOYUMKM_ONBOARDING_LIVE_RECIPIENT=')
            [IO.File]::WriteAllText($local, ($lines -join "`r`n") + "`r`n")
            Add-Check 'FRESH' 'docker/hermes/.env created (ACL-restricted, git-ignored)' 'PASS'
        }
        $dbSecure = Read-Host "MySQL password for '$DbAdminUser' (masked)" -AsSecureString
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($dbSecure)
        try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
        New-Item -ItemType File -Path $defaults -Force | Out-Null; Protect-File $defaults
        $esc = $plain.Replace('\', '\\').Replace('"', '\"'); $plain = $null
        [IO.File]::WriteAllText($defaults, "[client]`r`nuser=$DbAdminUser`r`npassword=`"$esc`"`r`nhost=127.0.0.1`r`nport=$(Get-LaravelEnv 'DB_PORT')`r`n"); $esc = $null

        # SQL: inspect first; create only what is absent; conflicts fail closed
        $dbq = "``$db``"
        $viewRows = Invoke-MySql "SELECT table_name FROM information_schema.views WHERE table_schema='$db' AND table_name IN ('analytics_umkms','analytics_evaluations','analytics_six_factor_evaluations');" $defaults $mysql
        $baseRows = Invoke-MySql "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND table_name IN ('umkms','sectors','evaluations','six_factor_evaluations','analytics_dataset_members','analytics_dataset_cohorts');" $defaults $mysql
        if (@($baseRows).Count -ne 6) { Stop-Setup 'base tables/cohort tables missing: run the standard Laravel migrations and dataset seeding first (README). No SQL was applied.' }
        $cohort = Invoke-MySql "SELECT COUNT(*) FROM $dbq.analytics_dataset_members;" $defaults $mysql
        if ([int]($cohort | Select-Object -First 1) -eq 0) { Add-Check 'FRESH' 'analytics cohort' 'WARN' 'analytics_dataset_members is empty (dataset not seeded): views will be empty until the seeder runs under its own controls' }
        $reader = Invoke-MySql "SELECT COUNT(*) FROM mysql.user WHERE user='joyumkm_analytics_ro' AND host='localhost';" $defaults $mysql
        $definer = Invoke-MySql "SELECT COUNT(*) FROM mysql.user WHERE user='joyumkm_analytics_def' AND host='localhost';" $defaults $mysql
        if (@($viewRows).Count -eq 3) {
            $body = (Invoke-MySql "SELECT GROUP_CONCAT(view_definition SEPARATOR ' ') FROM information_schema.views WHERE table_schema='$db' AND table_name LIKE 'analytics\_%';" $defaults $mysql) -join ' '
            if ($body -notmatch 'joyumkm-analytics' -or $body -notmatch "'v2'") { Stop-Setup 'existing analytics views do not match the v2 contract: conflict, refusing CREATE OR REPLACE over them' }
            Add-Check 'FRESH' 'analytics views already present and matching' 'PASS' 'SQL not re-applied'
        } elseif (@($viewRows).Count -gt 0) { Stop-Setup 'only some analytics views exist: ambiguous, refusing to modify' }
        else {
            if ([int]($reader | Select-Object -First 1) -ne 0) { Stop-Setup 'reader account exists but its password is unknown: this script never rotates credentials. Create mysql.json manually (README) or remove the account yourself.' }
            if ([int]($definer | Select-Object -First 1) -ne 0) { Stop-Setup 'definer account exists without the views: ambiguous, refusing to modify' }
            $readerPw = New-RandomSecret 24
            $sqlText = "CREATE USER 'joyumkm_analytics_ro'@'localhost' IDENTIFIED BY '$readerPw' REQUIRE SSL;`n" + (Get-Content -LiteralPath (Join-Path $script:RepoRoot $manifest.sql.path) -Raw -Encoding UTF8)
            [void](Invoke-MySql $sqlText $defaults $mysql); $sqlText = $null
            Add-Check 'FRESH' 'analytics principals and 3 views created from canonical SQL' 'PASS'
        }
        if (-not $readerPw) { Stop-Setup 'views existed but no reader password is available to write mysql.json; provide it manually (README)' }

        # CA from the local MySQL server
        $caPath = ((Invoke-MySql "SELECT @@ssl_ca;" $defaults $mysql) | Select-Object -First 1)
        if (-not $caPath -or $caPath -eq 'NULL') { $dd = ((Invoke-MySql 'SELECT @@datadir;' $defaults $mysql) | Select-Object -First 1); $caPath = Join-Path $dd 'ca.pem' }
        if (-not (Test-Path -LiteralPath $caPath)) { Stop-Setup 'MySQL CA certificate not found; TLS prerequisite unmet (security is not relaxed)' }

        # docker resources (idempotent)
        [void](Invoke-Native 'docker' @('network', 'inspect', 'joyumkm-hermes-webhook')); if ($script:LastExit -ne 0) { [void](Invoke-Native 'docker' @('network', 'create', 'joyumkm-hermes-webhook')); if ($script:LastExit -ne 0) { Stop-Setup 'network create failed' } }
        [void](Invoke-Native 'docker' @('volume', 'create', 'hermes_data')); if ($script:LastExit -ne 0) { Stop-Setup 'volume create failed' }
        Add-Check 'FRESH' 'network + volume present' 'PASS'

        # stage assets
        if (Test-Path -LiteralPath $stage) { Stop-Setup "stage dir already exists ($stage): remove it yourself after inspection; nothing deleted automatically" }
        New-Item -ItemType Directory -Path $stage -Force | Out-Null
        $vw = Join-Path $stage 'plugins\visual-workflow'
        [void](Invoke-Native 'git' @('clone', '--quiet', $manifest.plugin.upstream, $vw)); if ($script:LastExit -ne 0) { Stop-Setup 'plugin clone failed' }
        [void](Invoke-Native 'git' @('-C', $vw, 'checkout', '--quiet', $manifest.plugin.upstream_commit)); if ($script:LastExit -ne 0) { Stop-Setup 'pinned plugin commit checkout failed' }
        Copy-Item -Path (Join-Path $script:HermesDir 'scripts\visual-workflow-overlay\*') -Destination $vw -Recurse -Force
        $jmiDst = Join-Path $stage 'joyumkm-intelligence'
        New-Item -ItemType Directory -Path (Join-Path $jmiDst 'state') -Force | Out-Null
        Copy-Item -Path (Join-Path $script:HermesDir 'scripts\jmi') -Destination (Join-Path $jmiDst 'jmi') -Recurse
        Copy-Item -Path (Join-Path $script:HermesDir 'config\callback_registry.template.json') -Destination (Join-Path $jmiDst 'jmi\callback_registry.json')
        Copy-Item -Path (Join-Path $script:HermesDir 'scripts\jmi-knowledge') -Destination (Join-Path $jmiDst 'knowledge') -Recurse
        Copy-Item -Path (Join-Path $script:HermesDir 'workflows') -Destination (Join-Path $stage 'workflow-designs') -Recurse
        foreach ($t in Get-ChildItem (Join-Path $script:HermesDir 'config\state\*.template')) {
            $content = Get-Content -LiteralPath $t.FullName -Raw
            foreach ($pair in @{ 'HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET' = 'HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET'; 'INTELLIGENCE_CALLBACK_SECRET' = 'HERMES_INTELLIGENCE_CALLBACK_SECRET'; 'INTELLIGENCE_ARTIFACT_SECRET' = 'HERMES_INTELLIGENCE_ARTIFACT_SECRET'; 'HERMES_INTELLIGENCE_RECIPIENT_SECRET' = 'HERMES_INTELLIGENCE_RECIPIENT_SECRET'; 'INTELLIGENCE_SNAPSHOT_SECRET' = 'HERMES_INTELLIGENCE_SNAPSHOT_SECRET' }.GetEnumerator()) {
                $v = Get-LaravelEnv $pair.Value
                if ($v) { $content = $content -replace ('(?m)^' + [regex]::Escape($pair.Key) + '=__FROM_LARAVEL_ENV__'), ($pair.Key + '=' + $v.Replace('$', '$$')) }
            }
            [IO.File]::WriteAllText((Join-Path $jmiDst ('state\' + ($t.Name -replace '\.template$', ''))), $content)
        }
        $an = Join-Path $stage 'joyumkm-analytics'; New-Item -ItemType Directory -Path $an -Force | Out-Null
        Copy-Item -LiteralPath $caPath -Destination (Join-Path $an 'mysql-ca.pem')
        $cfg = (Get-Content -LiteralPath (Join-Path $script:HermesDir 'config\mysql.json.template') -Raw | ConvertFrom-Json)
        $cfg.password = $readerPw; $cfg.port = [int](Get-LaravelEnv 'DB_PORT')
        [IO.File]::WriteAllText((Join-Path $an 'mysql.json'), ($cfg | ConvertTo-Json)); $readerPw = $null
        $ob = Join-Path $stage 'joyumkm-onboarding'; New-Item -ItemType Directory -Path $ob -Force | Out-Null
        $os = Get-Content -LiteralPath (Join-Path $script:HermesDir 'config\onboarding-settings.json.template') -Raw | ConvertFrom-Json
        $os.secret = [string](Get-LaravelEnv 'HERMES_WEBHOOK_SECRET'); [IO.File]::WriteAllText((Join-Path $ob 'settings.json'), ($os | ConvertTo-Json))
        foreach ($d in 'workflow-runs', 'joyumkm-prediction') { New-Item -ItemType Directory -Path (Join-Path $stage $d) -Force | Out-Null }
        $deps = Join-Path $an 'dependencies'
        $pk = $manifest.python_dependencies.packages.PSObject.Properties | ForEach-Object { "$($_.Name)==$($_.Value)" }
        [void](Invoke-Native 'python' (@('-m', 'pip', 'install', '--quiet', '--no-deps', '--target', $deps) + @($pk))); if ($script:LastExit -ne 0) { Stop-Setup 'pinned python dependencies could not be installed' }

        $img = $manifest.image.ref
        $sh = 'set -e; cp -a /stage/. /opt/data/; chown -R 10000:10000 /opt/data/plugins /opt/data/joyumkm-intelligence /opt/data/joyumkm-analytics /opt/data/joyumkm-onboarding /opt/data/joyumkm-prediction /opt/data/workflow-designs /opt/data/workflow-runs; chmod 600 /opt/data/joyumkm-analytics/mysql.json /opt/data/joyumkm-onboarding/settings.json /opt/data/joyumkm-intelligence/state/*.env'
        [void](Invoke-Native 'docker' @('run', '--rm', '--user', 'root', '--entrypoint', 'sh', '-v', 'hermes_data:/opt/data', '-v', "$($stage):/stage:ro", $img, '-c', $sh)); if ($script:LastExit -ne 0) { Stop-Setup 'staging into hermes_data failed; volume kept for inspection (never deleted automatically)' }
        Add-Check 'FRESH' 'assets staged into hermes_data (side effects disabled)' 'PASS'

        # start once
        $names = Set-ComposeProcessEnv -EnvFile $local
        try { [void](Invoke-Native 'docker' ((Get-ComposeArgs) + @('--env-file', $local, 'up', '-d'))); if ($script:LastExit -ne 0) { Stop-Setup 'compose up failed; created resources are kept for targeted recovery' } } finally { Clear-ComposeProcessEnv $names }
        $up = $false
        foreach ($i in 1..24) { if ((Test-TcpPort '127.0.0.1' 8644) -and (Test-TcpPort '127.0.0.1' 8650) -and (Test-TcpPort '127.0.0.1' 8642)) { $up = $true; break }; Start-Sleep -Seconds 5 }
        Add-Check 'FRESH' 'services listening (bounded 120 s)' $(if ($up) { 'PASS' } else { 'WARN' })
    } finally {
        if (Test-Path -LiteralPath $defaults) { Remove-Item -LiteralPath $defaults -Force }
        $dbSecure = $null; $readerPw = $null; $plain = $null
    }
    Write-Host ''
    Write-Host 'REMAINING MANUAL PREREQUISITES (not automated, see manifest.json):' -ForegroundColor Yellow
    $manifest.manual_prerequisites_fresh | ForEach-Object { Write-Host "  - $_" }
    & (Join-Path $PSScriptRoot 'check-hermes.ps1') -EnvFile (Join-Path $script:HermesDir '.env')
    $script:ExitCode = $LASTEXITCODE
} catch [System.OperationCanceledException] {
    if (-not $script:Done -and $script:ExitCode -eq 0) { $script:ExitCode = 2 }
} catch {
    Write-Host ('setup-hermes failed: ' + $_.Exception.GetType().Name + ' - ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host 'Created resources (if any) were kept; re-run is safe. Nothing is deleted automatically.' -ForegroundColor Yellow
    $script:ExitCode = 2
}
exit $script:ExitCode
