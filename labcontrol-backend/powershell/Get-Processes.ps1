<#
.SYNOPSIS
    Obtém a lista de processos de um host remoto com métricas reais de CPU e Memória.
.DESCRIPTION
    Retorna informações precisas sobre os processos, ignorando erros de acesso a propriedades protegidas.
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

function Get-SecureCredential {
    param([string]$User, [string]$Pass)
    if ([string]::IsNullOrEmpty($User) -or [string]::IsNullOrEmpty($Pass)) { return $null }
    $securePassword = ConvertTo-SecureString $Pass -AsPlainText -Force
    return New-Object System.Management.Automation.PSCredential($User, $securePassword)
}

function Invoke-RemoteCommand {
    param(
        [string]$Computer,
        [System.Management.Automation.PSCredential]$Credential,
        [scriptblock]$ScriptBlock,
        [array]$ArgumentList
    )
    
    try {
        $sessionParams = @{ ComputerName = $Computer; ErrorAction = 'Stop' }
        if ($Credential -ne $null) { $sessionParams['Credential'] = $Credential }
        
        $session = New-PSSession @sessionParams
        $result = Invoke-Command -Session $session -ScriptBlock $ScriptBlock -ArgumentList $ArgumentList
        Remove-PSSession -Session $session
        
        return @{ Success = $true; Result = $result; Error = $null }
    }
    catch {
        return @{ Success = $false; Error = $_.Exception.Message }
    }
}

# ========== SCRIPT PRINCIPAL ==========

$credential = $null
if (-not [string]::IsNullOrEmpty($Username) -and -not [string]::IsNullOrEmpty($Password)) {
    $credential = Get-SecureCredential -User $Username -Pass $Password
}

$scriptBlock = {
    try {
        # Obter memória total do sistema para cálculo de percentual
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
        $totalMemKB = if ($os) { $os.TotalVisibleMemorySize } else { 1 }
        
        # Obter dados de performance em tempo real
        $perfData = Get-CimInstance Win32_PerfFormattedData_PerfProc_Process -ErrorAction SilentlyContinue | 
                    Where-Object { $_.Name -notmatch "_Total|Idle" } |
                    Group-Object IDProcess -AsHashTable -AsString
        
        # Pegar os processos reais
        $processes = Get-Process | ForEach-Object {
            $id = $_.Id
            $name = $_.ProcessName
            $perf = $perfData["$id"]
            
            $cpuPercent = if ($perf) { $perf.PercentProcessorTime } else { 0 }
            
            # StartTime pode falhar para processos do sistema
            $startTimeStr = ""
            try { $startTimeStr = $_.StartTime.ToString("yyyy-MM-dd HH:mm:ss") } catch { $startTimeStr = "N/A" }
            
            [PSCustomObject]@{
                Id = $id
                ProcessName = $name
                CPU = [math]::Round($cpuPercent, 1)
                MemoryMB = [math]::Round($_.WorkingSet64 / 1MB, 2)
                MemoryPercent = [math]::Round(($_.WorkingSet64 / 1024 / $totalMemKB) * 100, 2)
                StartTime = $startTimeStr
                Responding = $_.Responding
            }
        } | Sort-Object CPU -Descending | Select-Object -First 50
        
        return @{ Success = $true; Processes = $processes }
    }
    catch {
        return @{ Success = $false; Error = $_.Exception.Message }
    }
}

$remoteResult = Invoke-RemoteCommand `
    -Computer $ComputerName `
    -Credential $credential `
    -ScriptBlock $scriptBlock

if ($remoteResult.Success) {
    $result = $remoteResult.Result
    if ($result.Success) {
        Write-Output ($result.Processes | ConvertTo-Json -Compress)
        exit 0
    } else {
        Write-Output (@{ success = $false; error = $result.Error } | ConvertTo-Json -Compress)
        exit 1
    }
} else {
    Write-Output (@{ success = $false; error = $remoteResult.Error } | ConvertTo-Json -Compress)
    exit 1
}
