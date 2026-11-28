# Shared helpers for setup-hermes.ps1 / check-hermes.ps1 / backup-hermes.ps1.
# Windows PowerShell 5.1+. Dot-source only. Nothing here prints credentials, env values or payloads.

Set-StrictMode -Version 2.0

$script:RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$script:HermesDir = Join-Path $script:RepoRoot 'docker\hermes'
$script:ComposeFile = Join-Path $script:RepoRoot 'compose.yaml'
$script:ManifestPath = Join-Path $script:HermesDir 'manifest.json'
$script:Results = New-Object System.Collections.ArrayList

function Get-HermesManifest {
    if (-not (Test-Path -LiteralPath $script:ManifestPath)) { throw "manifest missing: $script:ManifestPath" }
    return (Get-Content -LiteralPath $script:ManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json)
}

function Add-Check {
    param([string]$Group, [string]$Name, [ValidateSet('PASS', 'FAIL', 'WARN', 'INFO', 'SKIP')][string]$Status, [string]$Detail = '')
    [void]$script:Results.Add([pscustomobject]@{ Group = $Group; Name = $Name; Status = $Status; Detail = $Detail })
    $color = switch ($Status) { 'PASS' { 'Green' } 'FAIL' { 'Red' } 'WARN' { 'Yellow' } default { 'Gray' } }
    Write-Host ('[{0}] {1}: {2}{3}' -f $Status, $Group, $Name, $(if ($Detail) { " - $Detail" } else { '' })) -ForegroundColor $color
}

function Test-GroupPassed {
    param([string]$Group)
    $rows = @($script:Results | Where-Object { $_.Group -eq $Group })
    return ($rows.Count -gt 0 -and @($rows | Where-Object { $_.Status -eq 'FAIL' }).Count -eq 0)
}

# Runs a native command; returns stdout lines. Never echoes arguments. Sets $script:LastExit.
function Invoke-Native {
    param([string]$Exe, [string[]]$Arguments, [string]$StdIn = $null)
    $script:LastExit = -1
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($null -ne $StdIn) { $out = $StdIn | & $Exe @Arguments 2>$null } else { $out = & $Exe @Arguments 2>$null }
        $script:LastExit = $LASTEXITCODE
    } finally { $ErrorActionPreference = $old }
    if ($null -eq $out) { return , @() }
    return , @($out)
}

# Compose labels of a container as an object (PS 5.1 drops embedded double quotes in native args, so use {{json}}).
function Get-ContainerLabels {
    param([string]$Container)
    $o = Invoke-Native 'docker' @('inspect', $Container, '--format', '{{json .Config.Labels}}')
    if ($script:LastExit -ne 0 -or $o.Count -eq 0) { return $null }
    try { return (($o -join '') | ConvertFrom-Json) } catch { return $null }
}

function Test-Command { param([string]$Name) return [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function ConvertTo-ComposePath {
    param([string]$Path)
    return ((Resolve-Path -LiteralPath $Path).Path -replace '\\', '/')
}

function Normalize-HostPath {
    param([string]$Path)
    if (-not $Path) { return '' }
    $p = $Path -replace '\\', '/'
    if ($p -match '^/run/desktop/mnt/host/([a-zA-Z])/(.*)$') { $p = '{0}:/{1}' -f $Matches[1], $Matches[2] }
    return $p.TrimEnd('/').ToLowerInvariant()
}

# Reads KEY=VALUE pairs (BOM/quotes tolerant) into a hashtable. Values stay in memory only.
function Read-EnvFile {
    param([string]$Path)
    $map = @{}
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $map }
    foreach ($line in (Get-Content -LiteralPath $Path -Encoding UTF8)) {
        $l = $line.Trim([char]0xFEFF).Trim()
        if (-not $l -or $l.StartsWith('#')) { continue }
        $i = $l.IndexOf('=')
        if ($i -lt 1) { continue }
        $v = $l.Substring($i + 1).Trim()
        if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) { $v = $v.Substring(1, $v.Length - 2) }
        $map[$l.Substring(0, $i).Trim()] = $v
    }
    return $map
}

# Locates the env file Compose should use: explicit > docker/hermes/.env > the legacy file next to the live compose.
function Resolve-ComposeEnvFile {
    param([string]$Explicit)
    if ($Explicit) { return $Explicit }
    $local = Join-Path $script:HermesDir '.env'
    if (Test-Path -LiteralPath $local) { return $local }
    $labels = Get-ContainerLabels 'hermes-agent'
    if ($labels -and $labels.PSObject.Properties['com.docker.compose.project.working_dir']) {
        $legacy = Join-Path ([string]$labels.'com.docker.compose.project.working_dir') '.env'
        if (Test-Path -LiteralPath $legacy) { return $legacy }
    }
    return $null
}

# Applies compose interpolation variables to THIS process only; returns the names set so callers can clear them.
function Set-ComposeProcessEnv {
    param([string]$EnvFile, [switch]$ValidateOnly)
    $names = New-Object System.Collections.ArrayList
    $vars = @{}
    if ($EnvFile) { $vars = Read-EnvFile $EnvFile }
    $vars['HERMES_HOST_REPO_ROOT'] = ConvertTo-ComposePath $script:RepoRoot
    $vars['HERMES_HOST_WWW_ROOT'] = ConvertTo-ComposePath (Split-Path $script:RepoRoot -Parent)
    if ($ValidateOnly) {
        foreach ($k in 'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD', 'HERMES_DASHBOARD_BASIC_AUTH_SECRET') { if (-not $vars.ContainsKey($k) -or -not $vars[$k]) { $vars[$k] = 'validate-only' } }
    }
    foreach ($k in $vars.Keys) { Set-Item -Path ('Env:' + $k) -Value $vars[$k]; [void]$names.Add($k) }
    return , $names
}

function Clear-ComposeProcessEnv {
    param($Names)
    foreach ($k in @($Names)) { Remove-Item -Path ('Env:' + $k) -ErrorAction SilentlyContinue }
}

function Get-ComposeArgs { return @('compose', '-p', 'jobsheet2', '-f', $script:ComposeFile) }

function Get-RuntimeInspect {
    param([string]$Container)
    $o = Invoke-Native 'docker' @('inspect', $Container)
    if ($script:LastExit -ne 0 -or $o.Count -eq 0) { return $null }
    return (($o -join "`n") | ConvertFrom-Json)[0]
}

# Redacted structural comparison of the resolved compose config against live containers. Returns a list of difference strings (names only).
function Compare-ComposeToRuntime {
    $diffs = New-Object System.Collections.ArrayList
    $raw = Invoke-Native 'docker' ((Get-ComposeArgs) + @('config', '--format', 'json'))
    if ($script:LastExit -ne 0) { [void]$diffs.Add('compose config could not be resolved'); return $diffs.ToArray() }
    $cfg = ($raw -join "`n") | ConvertFrom-Json
    $manifest = Get-HermesManifest
    foreach ($svcProp in $cfg.services.PSObject.Properties) {
        $svc = $svcProp.Value
        $key = $svcProp.Name
        $cname = $svc.container_name
        $rt = Get-RuntimeInspect $cname
        if (-not $rt) { [void]$diffs.Add("${key}: container $cname not found"); continue }
        if (-not $rt.State.Running) { [void]$diffs.Add("${key}: container not running") }
        $digest = ([string]$svc.image) -replace '^.*@', ''
        if ($rt.Image -ne $digest) { [void]$diffs.Add("${key}: image differs from pinned digest") }
        if ([string]$svc.restart -ne $rt.HostConfig.RestartPolicy.Name) { [void]$diffs.Add("${key}: restart policy differs") }
        $cp = $false; if ($svc.PSObject.Properties['privileged']) { $cp = [bool]$svc.privileged }
        if ($cp -ne [bool]$rt.HostConfig.Privileged) { [void]$diffs.Add("${key}: privileged differs") }
        if ($svc.PSObject.Properties['user'] -and ([string]$svc.user -ne [string]$rt.Config.User)) { [void]$diffs.Add("${key}: user differs") }
        $cro = $false; if ($svc.PSObject.Properties['read_only']) { $cro = [bool]$svc.read_only }
        if ($cro -ne [bool]$rt.HostConfig.ReadonlyRootfs) { [void]$diffs.Add("${key}: read_only differs") }
        $ccd = @(); if ($svc.PSObject.Properties['cap_drop']) { $ccd = @($svc.cap_drop) }
        $rcd = @(); if ($rt.HostConfig.CapDrop) { $rcd = @($rt.HostConfig.CapDrop) }
        if ((($ccd | Sort-Object) -join ',') -ne (($rcd | Sort-Object) -join ',')) { [void]$diffs.Add("${key}: cap_drop differs") }
        $cso = @(); if ($svc.PSObject.Properties['security_opt']) { $cso = @($svc.security_opt) }
        $rso = @(); if ($rt.HostConfig.SecurityOpt) { $rso = @($rt.HostConfig.SecurityOpt) }
        if ((($cso | Sort-Object) -join ',') -ne (($rso | Sort-Object) -join ',')) { [void]$diffs.Add("${key}: security_opt differs") }
        # ports
        $cports = @(); if ($svc.PSObject.Properties['ports']) { $cports = @($svc.ports | ForEach-Object { '{0}:{1}->{2}' -f $_.host_ip, $_.published, $_.target } | Sort-Object) }
        $rports = @(); foreach ($p in $rt.HostConfig.PortBindings.PSObject.Properties) { foreach ($b in $p.Value) { $rports += ('{0}:{1}->{2}' -f $b.HostIp, $b.HostPort, ($p.Name -replace '/.*$', '')) } }
        if (($cports -join ',') -ne (($rports | Sort-Object) -join ',')) { [void]$diffs.Add("${key}: port mapping differs") }
        # mounts: type|target|rw|source(normalised for binds)|subpath
        $cm = @()
        if ($svc.PSObject.Properties['volumes']) {
            foreach ($v in $svc.volumes) {
                $ro = $false; if ($v.PSObject.Properties['read_only']) { $ro = [bool]$v.read_only }
                $sub = ''; if ($v.PSObject.Properties['volume'] -and $v.volume -and $v.volume.PSObject.Properties['subpath']) { $sub = [string]$v.volume.subpath }
                $src = if ($v.type -eq 'bind') { Normalize-HostPath $v.source } else { [string]$v.source }
                $cm += ('{0}|{1}|ro={2}|{3}|{4}' -f $v.type, $v.target, $ro, $src, $sub)
            }
        }
        $subByTarget = @{}
        if ($rt.HostConfig.PSObject.Properties['Mounts'] -and $rt.HostConfig.Mounts) {
            foreach ($hm in $rt.HostConfig.Mounts) { if ($hm.PSObject.Properties['VolumeOptions'] -and $hm.VolumeOptions -and $hm.VolumeOptions.PSObject.Properties['Subpath']) { $subByTarget[[string]$hm.Target] = [string]$hm.VolumeOptions.Subpath } }
        }
        $rm = @()
        foreach ($m in $rt.Mounts) {
            $src = if ($m.Type -eq 'bind') { Normalize-HostPath $m.Source } else { [string]$m.Name }
            $sub = ''; if ($subByTarget.ContainsKey([string]$m.Destination)) { $sub = $subByTarget[[string]$m.Destination] }
            $rm += ('{0}|{1}|ro={2}|{3}|{4}' -f $m.Type, $m.Destination, (-not [bool]$m.RW), $src, $sub)
        }
        $cmN = @($cm | ForEach-Object { $_.ToLowerInvariant() } | Sort-Object)
        $rmN = @($rm | ForEach-Object { $_.ToLowerInvariant() } | Sort-Object)
        if (($cmN -join ';') -ne ($rmN -join ';')) { [void]$diffs.Add("${key}: mounts differ (" + (@(Compare-Object $cmN $rmN | ForEach-Object { ($_.InputObject -split '\|')[1] } | Select-Object -Unique) -join ', ') + ')') }
        # environment: compare declared keys only; report key names, never values
        if ($svc.PSObject.Properties['environment']) {
            $rtEnv = @{}; foreach ($e in $rt.Config.Env) { $i = $e.IndexOf('='); if ($i -gt 0) { $rtEnv[$e.Substring(0, $i)] = $e.Substring($i + 1) } }
            foreach ($ep in $svc.environment.PSObject.Properties) {
                if (-not $rtEnv.ContainsKey($ep.Name)) { [void]$diffs.Add("${key}: env key $($ep.Name) missing at runtime") }
                elseif ([string]$ep.Value -ne $rtEnv[$ep.Name]) { [void]$diffs.Add("${key}: env key $($ep.Name) differs (value not shown)") }
            }
        }
        # networks
        $cn = @($svc.networks.PSObject.Properties.Name | ForEach-Object { if ($_ -eq 'default') { 'jobsheet2_default' } else { $_ } } | Sort-Object)
        $rn = @($rt.NetworkSettings.Networks.PSObject.Properties.Name | Sort-Object)
        if (($cn -join ',') -ne ($rn -join ',')) { [void]$diffs.Add("${key}: networks differ") }
        if ($rt.Mounts | Where-Object { $_.Source -like '*docker.sock*' }) { [void]$diffs.Add("${key}: docker.sock mounted (baseline has none)") }
    }
    return $diffs.ToArray()
}

function Get-LaravelEnv {
    param([string]$Key)
    $map = Read-EnvFile (Join-Path $script:RepoRoot '.env')
    if ($map.ContainsKey($Key)) { return $map[$Key] }
    return $null
}

function Get-Sha256Hex {
    param([string]$Text)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { return (($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Text)) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() }
}

function Test-TcpPort {
    param([string]$HostName, [int]$Port, [int]$TimeoutMs = 1500)
    $c = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $c.BeginConnect($HostName, $Port, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne($TimeoutMs)) { return $false }
        $c.EndConnect($iar); return $true
    } catch { return $false } finally { $c.Close() }
}

# Runs the read-only canary inside the live hermes-agent container. Returns the parsed object or $null.
function Invoke-HermesCanary {
    param([string]$ExpectHmacSha256 = '')
    $manifest = Get-HermesManifest
    $overlay = (@($manifest.plugin.overlay_files.PSObject.Properties.Name) -join ',')
    $canary = Join-Path $script:HermesDir 'scripts\canary.py'
    $args = @('exec', '-i', '-u', 'hermes', '-e', "CHECK_OVERLAY=$overlay", '-e', "EXPECT_HMAC_SHA256=$ExpectHmacSha256", 'hermes-agent', 'python3', '-')
    $out = Invoke-Native 'docker' $args -StdIn (Get-Content -LiteralPath $canary -Raw -Encoding UTF8)
    if ($script:LastExit -ne 0 -or $out.Count -eq 0) { return $null }
    try { return (($out -join "`n") | ConvertFrom-Json) } catch { return $null }
}

function Get-RepoHashes {
    param([string]$RelDir, $Expected)
    $bad = New-Object System.Collections.ArrayList
    foreach ($p in $Expected.PSObject.Properties) {
        $f = Join-Path (Join-Path $script:RepoRoot $RelDir) ($p.Name -replace '/', '\')
        if (-not (Test-Path -LiteralPath $f)) { [void]$bad.Add("$($p.Name) (missing in repo)"); continue }
        if ((Get-FileHash -LiteralPath $f -Algorithm SHA256).Hash.ToLowerInvariant() -ne $p.Value) { [void]$bad.Add("$($p.Name) (repo hash differs from manifest)") }
    }
    return $bad.ToArray()
}

function Compare-RuntimeHashes {
    param($Runtime, $Expected, [string]$Label)
    $bad = New-Object System.Collections.ArrayList
    $have = @{}; if ($Runtime) { foreach ($p in $Runtime.PSObject.Properties) { $have[$p.Name] = $p.Value } }
    foreach ($p in $Expected.PSObject.Properties) {
        if (-not $have.ContainsKey($p.Name) -or -not $have[$p.Name]) { [void]$bad.Add("$($p.Name) missing in runtime") }
        elseif ($have[$p.Name] -ne $p.Value) { [void]$bad.Add("$($p.Name) drifted") }
    }
    return $bad.ToArray()
}

# Read-only Laravel queue/outbox signature (ids/status only; never payloads).
function Get-PendingSignature {
    if (-not (Test-Command 'php')) { return $null }
    $code = "echo json_encode(['outbox'=>DB::table('webhook_outbox')->orderByDesc('id')->limit(5)->get(['id','status']),'jobs'=>DB::table('jobs')->get(['id','queue','attempts'])]);"
    Push-Location $script:RepoRoot
    try { $o = Invoke-Native 'php' @('artisan', 'tinker', '--no-interaction', "--execute=$code") } finally { Pop-Location }
    if ($script:LastExit -ne 0) { return $null }
    $line = @($o | Where-Object { $_ -match '^\{' } | Select-Object -Last 1)
    if (-not $line) { return $null }
    try { return ($line[0] | ConvertFrom-Json) } catch { return $null }
}

function Test-AppUp {
    $url = Get-LaravelEnv 'APP_URL'
    if (-not $url) { $url = 'http://joyumkm.test' }
    try {
        $r = Invoke-WebRequest -Uri ($url.TrimEnd('/') + '/up') -UseBasicParsing -TimeoutSec 8
        return [pscustomobject]@{ Ok = ($r.StatusCode -eq 200); Url = $url; Code = $r.StatusCode }
    } catch { return [pscustomobject]@{ Ok = $false; Url = $url; Code = 0 } }
}
