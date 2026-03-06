<#
.SYNOPSIS
    Obtém informações do sistema de um host remoto com métricas precisas.
.DESCRIPTION
    Retorna informações detalhadas sobre o sistema operacional, hardware e recursos.
    Melhorada a precisão do uso de CPU e Memória.
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
        $os = Get-CimInstance Win32_OperatingSystem
        $cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
        
        # Uso de CPU (Média total normalizada 0-100%)
        # Usamos Win32_PerfFormattedData_PerfOS_Processor para maior precisão em leituras instantâneas
        $cpuStats = Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor | Where-Object Name -eq "_Total"
        $cpuUsage = $cpuStats.PercentProcessorTime
        
        # Memória (calculada corretamente)
        $memTotal = $os.TotalVisibleMemorySize
        $memFree = $os.FreePhysicalMemory
        $memUsed = $memTotal - $memFree
        $memPercent = [math]::Round(($memUsed / $memTotal) * 100, 2)
        
        # Discos
        $disks = Get-CimInstance Win32_LogicalDisk | Where-Object { $_.DriveType -eq 3 } | 
            Select-Object DeviceID, 
                @{Name="SizeGB";Expression={[math]::Round($_.Size / 1GB, 2)}}, 
                @{Name="FreeGB";Expression={[math]::Round($_.FreeSpace / 1GB, 2)}},
                @{Name="UsedPercent";Expression={[math]::Round((($_.Size - $_.FreeSpace) / $_.Size) * 100, 2)}}
        
        # Rede
        $network = Get-CimInstance Win32_NetworkAdapterConfiguration | Where-Object { $_.IPEnabled -eq $true } | 
            Select-Object -First 1 MACAddress, IPAddress, DefaultIPGateway
        
        $uptime = (Get-Date) - $os.LastBootUpTime
        
        return @{
            success = true
            computer_name = $os.CSName
            os_name = $os.Caption
            os_version = $os.Version
            cpu_name = $cpu.Name
            cpu_cores = $cpu.NumberOfCores
            cpu_threads = $cpu.NumberOfLogicalProcessors
            cpu_usage_percent = [math]::Round($cpuUsage, 2)
            memory_total_gb = [math]::Round($memTotal / 1MB, 2)
            memory_used_gb = [math]::Round($memUsed / 1MB, 2)
            memory_usage_percent = $memPercent
            disks = $disks
            mac_address = $network.MACAddress
            ip_address = if ($network.IPAddress) { $network.IPAddress[0] } else { $null }
            uptime_hours = [math]::Round($uptime.TotalHours, 2)
            last_boot = $os.LastBootUpTime.ToString("yyyy-MM-dd HH:mm:ss")
        }
    }
    catch {
        return @{ success = false; error = $_.Exception.Message }
    }
}

$remoteResult = Invoke-RemoteCommand `
    -Computer $ComputerName `
    -Credential $credential `
    -ScriptBlock $scriptBlock

if ($remoteResult.Success) {
    $result = $remoteResult.Result
    if ($result.success) {
        Write-Output ($result | ConvertTo-Json -Compress)
        exit 0
    } else {
        Write-Output (@{ success = $false; error = $result.error } | ConvertTo-Json -Compress)
        exit 1
    }
} else {
    Write-Output (@{ success = $false; error = $remoteResult.Error } | ConvertTo-Json -Compress)
    exit 1
}
