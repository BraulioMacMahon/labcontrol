<#
.SYNOPSIS
    Gets the process list of a remote host with CPU and memory metrics.
.DESCRIPTION
    ALWAYS writes exactly one JSON object to stdout:

      {"success":true,"processes":[...]}
      {"success":false,"error":"ACESSO_NEGADO: ..."}
      {"success":false,"error":"WINRM_ERRO: ..."}
      {"success":false,"error":"ERRO: ..."}

    Exit code is 0 only for the first form. The PHP side relies on this
    contract (see includes/PowerShell.php). Writing through [Console]::Out
    bypasses the PowerShell formatter, which can wrap long JSON lines and
    produce invalid output for the API.

    All strings in this file are ASCII on purpose: Windows PowerShell 5.1
    reads .ps1 files without a UTF-8 BOM as ANSI.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [string]$ComputerName,

    [Parameter(Mandatory=$false)]
    [string]$Username = "",

    [Parameter(Mandatory=$false)]
    [string]$Password = ""
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# Force UTF-8 on stdout. Without this, accented process descriptions leave
# powershell.exe in the OEM codepage and PHP's json_decode() fails.
try {
    $utf8 = New-Object System.Text.UTF8Encoding -ArgumentList $false
    $OutputEncoding = $utf8
    [Console]::OutputEncoding = $utf8
} catch {
    # Older runtimes: keep the default encoding, PHP still parses via
    # JSON_INVALID_UTF8_SUBSTITUTE.
}

function Get-SecureCredential {
    param([string]$User, [string]$Pass)
    if ([string]::IsNullOrEmpty($User) -or [string]::IsNullOrEmpty($Pass)) { return $null }
    $securePassword = ConvertTo-SecureString $Pass -AsPlainText -Force
    return New-Object System.Management.Automation.PSCredential($User, $securePassword)
}

function Get-RemoteErrorKind {
    param([string]$Message)
    if ($Message -match 'access is denied|acesso negado|logon failure|unknown user name|bad password|the user name or password is incorrect|permission denied|falha de logon') {
        return 'ACESSO_NEGADO'
    }
    if ($Message -match 'winrm|trustedhosts|cannot connect|could not connect|connection refused|the client cannot connect|network path was not found') {
        return 'WINRM_ERRO'
    }
    return 'ERRO'
}

function Write-JsonResult {
    param([hashtable]$Payload, [int]$ExitCode)
    $json = ConvertTo-Json -InputObject $Payload -Compress -Depth 6
    [Console]::Out.WriteLine($json)
    exit $ExitCode
}

function Invoke-RemoteCommand {
    param(
        [string]$Computer,
        [System.Management.Automation.PSCredential]$Credential,
        [scriptblock]$ScriptBlock
    )

    $session = $null
    try {
        $sessionParams = @{
            ComputerName   = $Computer
            ErrorAction    = 'Stop'
            Authentication = 'Negotiate'
            SessionOption  = (New-PSSessionOption -OpenTimeout 10000 -CancelTimeout 5000 -OperationTimeout 15000)
        }
        if ($null -ne $Credential) { $sessionParams['Credential'] = $Credential }

        $session = New-PSSession @sessionParams
        $result = Invoke-Command -Session $session -ScriptBlock $ScriptBlock -ErrorAction Stop
        return @{ Success = $true; Result = $result; Error = $null }
    }
    catch {
        $msg = $_.Exception.Message
        $kind = Get-RemoteErrorKind -Message $msg
        return @{ Success = $false; Result = $null; Error = "${kind}: $msg" }
    }
    finally {
        if ($null -ne $session) {
            try { Remove-PSSession -Session $session -ErrorAction SilentlyContinue } catch { }
        }
    }
}

$scriptBlock = {
    $ErrorActionPreference = 'Stop'
    try {
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
        $totalMemKB = 1
        if ($null -ne $os -and $os.TotalVisibleMemorySize) {
            $totalMemKB = [double]$os.TotalVisibleMemorySize
        }
        if ($totalMemKB -le 0) { $totalMemKB = 1 }

        # Initialised as real hashtables: a null $services used to throw
        # "You cannot call a method on a null-valued expression" on hosts where
        # Win32_Service returned nothing, and the whole listing failed.
        $services = @{}
        try {
            $svc = Get-CimInstance Win32_Service -ErrorAction SilentlyContinue
            if ($null -ne $svc) {
                $grouped = $svc | Where-Object { $_.ProcessId -gt 0 } | Group-Object ProcessId -AsHashTable -AsString
                if ($null -ne $grouped) { $services = $grouped }
            }
        } catch { }

        $perfData = @{}
        try {
            $perf = Get-CimInstance Win32_PerfFormattedData_PerfProc_Process -ErrorAction SilentlyContinue
            if ($null -ne $perf) {
                $groupedPerf = $perf | Where-Object { $_.Name -notmatch '_Total|Idle' } | Group-Object IDProcess -AsHashTable -AsString
                if ($null -ne $groupedPerf) { $perfData = $groupedPerf }
            }
        } catch { }

        $processes = @(Get-Process -ErrorAction SilentlyContinue | ForEach-Object {
            $proc = $_
            $id = $proc.Id
            $idKey = [string]$id
            $name = [string]$proc.ProcessName

            $cpuPercent = 0
            if ($perfData.ContainsKey($idKey)) {
                $perfEntries = @($perfData[$idKey])
                if ($perfEntries.Count -gt 0 -and $null -ne $perfEntries[0].PercentProcessorTime) {
                    $cpuPercent = [double]$perfEntries[0].PercentProcessorTime
                }
            }

            $description = ''
            try { $description = [string]$proc.Description } catch { $description = '' }

            $svcInfo = ''
            if ($services.ContainsKey($idKey)) {
                try { $svcInfo = (@($services[$idKey]) | Select-Object -ExpandProperty Name) -join ', ' } catch { $svcInfo = '' }
            }

            $displayName = $name
            if ($name -eq 'svchost' -and $svcInfo) {
                $displayName = "svchost ($svcInfo)"
            } elseif ($description -and $description -ne $name) {
                $displayName = "$name ($description)"
            }

            $startTimeStr = 'N/A'
            try { $startTimeStr = $proc.StartTime.ToString('yyyy-MM-dd HH:mm:ss') } catch { }

            $workingSet = 0
            if ($null -ne $proc.WorkingSet64) { $workingSet = [double]$proc.WorkingSet64 }

            [PSCustomObject]@{
                Id            = $id
                ProcessName   = $displayName
                CPU           = [math]::Round($cpuPercent, 1)
                MemoryMB      = [math]::Round($workingSet / 1048576, 2)
                MemoryPercent = [math]::Round(($workingSet / 1024 / $totalMemKB) * 100, 2)
                StartTime     = $startTimeStr
                Responding    = [bool]$proc.Responding
            }
        } | Sort-Object CPU, MemoryMB -Descending | Select-Object -First 50)

        return @{ Success = $true; Processes = $processes; Error = $null }
    }
    catch {
        return @{ Success = $false; Processes = @(); Error = $_.Exception.Message }
    }
}

# ========== MAIN ==========

if ([string]::IsNullOrEmpty($ComputerName)) {
    Write-JsonResult @{ success = $false; error = 'ERRO: ComputerName nao fornecido.' } 1
}

$credential = $null
if (-not [string]::IsNullOrEmpty($Username) -and -not [string]::IsNullOrEmpty($Password)) {
    try {
        $credential = Get-SecureCredential -User $Username -Pass $Password
    } catch {
        Write-JsonResult @{ success = $false; error = "ACESSO_NEGADO: Nao foi possivel criar a credencial: $($_.Exception.Message)" } 1
    }
}

$remoteResult = Invoke-RemoteCommand -Computer $ComputerName -Credential $credential -ScriptBlock $scriptBlock

if (-not $remoteResult.Success) {
    Write-JsonResult @{ success = $false; error = [string]$remoteResult.Error } 1
}

$result = $remoteResult.Result
if ($null -eq $result -or -not $result.Success) {
    $err = 'O comando remoto nao devolveu resultados.'
    if ($null -ne $result -and $result.Error) { $err = [string]$result.Error }
    Write-JsonResult @{ success = $false; error = "ERRO: $err" } 1
}

# ConvertTo-Json -InputObject (not pipeline) keeps the array shape even with
# 0 or 1 process. A bare object used to break .slice() in the frontend.
Write-JsonResult @{ success = $true; processes = @($result.Processes) } 0
