<#
.SYNOPSIS
    Encerra um processo em um host remoto de forma robusta.
.DESCRIPTION
    Encerra um processo no computador remoto por ID ou nome usando sessões WinRM persistentes.
.PARAMETER ComputerName
    Nome ou IP do computador remoto.
.PARAMETER Username
    Nome de usuário para autenticação.
.PARAMETER Password
    Senha para autenticação.
.PARAMETER ProcessId
    ID do processo a ser encerrado.
.PARAMETER ProcessName
    Nome do processo a ser encerrado.
.PARAMETER Force
    Força o encerramento do processo.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [string]$ComputerName,
    
    [Parameter(Mandatory=$false)]
    [string]$Username = "",
    
    [Parameter(Mandatory=$false)]
    [string]$Password = "",
    
    [Parameter(Mandatory=$false)]
    [int]$ProcessId = 0,
    
    [Parameter(Mandatory=$false)]
    [string]$ProcessName = "",
    
    [Parameter(Mandatory=$false)]
    [switch]$Force
)

# Função para criar credencial segura
function Get-SecureCredential {
    param([string]$User, [string]$Pass)
    if ([string]::IsNullOrEmpty($User) -or [string]::IsNullOrEmpty($Pass)) { return $null }
    $securePassword = ConvertTo-SecureString $Pass -AsPlainText -Force
    return New-Object System.Management.Automation.PSCredential($User, $securePassword)
}

# Função para executar comando remoto robusta (mesma do Get-Processes)
function Invoke-RemoteCommand {
    param(
        [string]$Computer,
        [System.Management.Automation.PSCredential]$Credential,
        [scriptblock]$ScriptBlock,
        [array]$ArgumentList
    )
    
    try {
        $sessionParams = @{
            ComputerName = $Computer
            ErrorAction = 'Stop'
        }
        
        if ($Credential -ne $null) {
            $sessionParams['Credential'] = $Credential
        }
        
        $session = New-PSSession @sessionParams
        $result = Invoke-Command -Session $session -ScriptBlock $ScriptBlock -ArgumentList $ArgumentList
        Remove-PSSession -Session $session
        
        return @{ Success = $true; Result = $result; Error = $null }
    }
    catch {
        $errorMsg = $_.Exception.Message
        if ($errorMsg -like "*Access is denied*" -or $errorMsg -like "*acesso negado*") {
            return @{ Success = $false; Result = $null; Error = "ACESSO_NEGADO: Credenciais inválidas ou permissão negada." }
        }
        elseif ($errorMsg -like "*WinRM*" -or $errorMsg -like "*cannot connect*") {
            return @{ Success = $false; Result = $null; Error = "WINRM_ERRO: Verifique WinRM e TrustedHosts." }
        }
        else {
            return @{ Success = $false; Result = $null; Error = "ERRO: $errorMsg" }
        }
    }
}

# ========== SCRIPT PRINCIPAL ==========

$credential = $null
if (-not [string]::IsNullOrEmpty($Username) -and -not [string]::IsNullOrEmpty($Password)) {
    $credential = Get-SecureCredential -User $Username -Pass $Password
}

$scriptBlock = {
    param($targetPid, $pname, $forceKill)
    
    try {
        if ($targetPid -gt 0) {
            $process = Get-Process -Id $targetPid -ErrorAction SilentlyContinue
            if (-not $process) {
                return @{ Success = $false; Message = "Processo ID $targetPid não encontrado ou já encerrado." }
            }
            
            $processName = $process.ProcessName
            if ($forceKill) {
                Stop-Process -Id $targetPid -Force -ErrorAction Stop
            } else {
                Stop-Process -Id $targetPid -ErrorAction Stop
            }
            
            return @{ Success = $true; Message = "Processo $processName (ID $targetPid) encerrado com sucesso."; ProcessId = $targetPid; ProcessName = $processName }
        }
        elseif ($pname) {
            $processes = Get-Process -Name $pname -ErrorAction SilentlyContinue
            if (-not $processes) {
                return @{ Success = $false; Message = "Processo '$pname' não encontrado." }
            }
            
            $count = ($processes | Measure-Object).Count
            if ($forceKill) {
                Stop-Process -Name $pname -Force -ErrorAction Stop
            } else {
                Stop-Process -Name $pname -ErrorAction Stop
            }
            
            return @{ Success = $true; Message = "$count processo(s) '$pname' encerrado(s) com sucesso."; ProcessName = $pname; Count = $count }
        }
        else {
            return @{ Success = $false; Message = "PID ou nome do processo não fornecido." }
        }
    }
    catch {
        return @{ Success = $false; Message = "Falha ao encerrar: $($_.Exception.Message)" }
    }
}

$remoteResult = Invoke-RemoteCommand `
    -Computer $ComputerName `
    -Credential $credential `
    -ScriptBlock $scriptBlock `
    -ArgumentList $ProcessId, $ProcessName, $Force.IsPresent

if ($remoteResult.Success) {
    $result = $remoteResult.Result
    if ($result.Success) {
        Write-Output (@{ success = $true; message = $result.Message; process_id = $result.ProcessId; process_name = $result.ProcessName } | ConvertTo-Json -Compress)
        exit 0
    } else {
        Write-Output (@{ success = $false; error = $result.Message } | ConvertTo-Json -Compress)
        exit 1
    }
} else {
    Write-Output (@{ success = $false; error = $remoteResult.Error } | ConvertTo-Json -Compress)
    exit 1
}
