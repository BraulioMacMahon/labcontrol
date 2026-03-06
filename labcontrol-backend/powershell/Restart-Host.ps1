<#
.SYNOPSIS
    Reinicia um host remoto via PowerShell.
.DESCRIPTION
    Envia comando de reinício para um computador remoto usando Invoke-Command.
    Suporta autenticação com credenciais.
.PARAMETER ComputerName
    Nome ou IP do computador remoto.
.PARAMETER Username
    Nome de usuário para autenticação.
.PARAMETER Password
    Senha para autenticação.
.PARAMETER Message
    Mensagem a ser exibida antes do reinício.
.PARAMETER Timeout
    Tempo de espera em segundos antes do reinício.
.PARAMETER Force
    Força o reinício mesmo com aplicativos abertos.
.EXAMPLE
    .\Restart-Host.ps1 -ComputerName "192.168.1.100" -Username "admin" -Password "senha123" -Message "Reinício programado" -Timeout 60
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
    [string]$Message = "Reinício remoto solicitado pelo LabControl",
    
    [Parameter(Mandatory=$false)]
    [int]$Timeout = 30,
    
    [Parameter(Mandatory=$false)]
    [switch]$Force
)

# Função para criar credencial segura
function Get-SecureCredential {
    param([string]$User, [string]$Pass)
    
    if ([string]::IsNullOrEmpty($User) -or [string]::IsNullOrEmpty($Pass)) {
        return $null
    }
    
    $securePassword = ConvertTo-SecureString $Pass -AsPlainText -Force
    return New-Object System.Management.Automation.PSCredential($User, $securePassword)
}

# Função para executar comando remoto
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
            $sessionParams['Authentication'] = 'Basic'
        }
        
        $session = New-PSSession @sessionParams
        $result = Invoke-Command -Session $session -ScriptBlock $ScriptBlock -ArgumentList $ArgumentList
        Remove-PSSession -Session $session
        
        return @{ Success = $true; Result = $result; Error = $null }
    }
    catch [System.Management.Automation.Remoting.PSRemotingTransportException] {
        $errorMsg = $_.Exception.Message
        
        if ($errorMsg -like "*Access is denied*" -or $errorMsg -like "*acesso negado*") {
            return @{ Success = $false; Result = $null; Error = "ACESSO_NEGADO: Credenciais inválidas ou usuário sem permissões administrativas." }
        }
        elseif ($errorMsg -like "*WinRM*" -or $errorMsg -like "*cannot connect*") {
            return @{ Success = $false; Result = $null; Error = "WINRM_ERRO: Não foi possível conectar. Verifique se o WinRM está habilitado." }
        }
        else {
            return @{ Success = $false; Result = $null; Error = "CONEXAO_ERRO: $errorMsg" }
        }
    }
    catch {
        return @{ Success = $false; Result = $null; Error = "ERRO: $($_.Exception.Message)" }
    }
}

# ========== SCRIPT PRINCIPAL ==========

$credential = $null
if (-not [string]::IsNullOrEmpty($Username) -and -not [string]::IsNullOrEmpty($Password)) {
    $credential = Get-SecureCredential -User $Username -Pass $Password
}

$restartArgs = "/r /t $Timeout /c `"$Message`""
if ($Force) {
    $restartArgs += " /f"
}

$scriptBlock = {
    param($args)
    $output = cmd /c shutdown $args 2`>`&1
    $exitCode = $LASTEXITCODE
    return @{ Output = $output; ExitCode = $exitCode }
}

$remoteResult = Invoke-RemoteCommand `
    -Computer $ComputerName `
    -Credential $credential `
    -ScriptBlock $scriptBlock `
    -ArgumentList $restartArgs

if ($remoteResult.Success) {
    $result = $remoteResult.Result
    
    if ($result.ExitCode -eq 0) {
        $response = @{
            success = $true
            message = "Comando de reinício enviado com sucesso"
            computer = $ComputerName
            timeout = $Timeout
            output = $result.Output
        }
        Write-Output ($response | ConvertTo-Json -Compress)
        exit 0
    }
    else {
        $response = @{
            success = $false
            error = "Erro ao executar restart: $($result.Output)"
            exit_code = $result.ExitCode
        }
        Write-Output ($response | ConvertTo-Json -Compress)
        exit 1
    }
}
else {
    $response = @{
        success = $false
        error = $remoteResult.Error
    }
    Write-Output ($response | ConvertTo-Json -Compress)
    exit 1
}
