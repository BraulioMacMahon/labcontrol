<#
.SYNOPSIS
    Executa um comando PowerShell em um host remoto.
.DESCRIPTION
    Executa um comando arbitrário no computador remoto.
    Suporta autenticação com credenciais.
.PARAMETER ComputerName
    Nome ou IP do computador remoto.
.PARAMETER Username
    Nome de usuário para autenticação.
.PARAMETER Password
    Senha para autenticação.
.PARAMETER Command
    Comando a ser executado.
.EXAMPLE
    .\Execute-Command.ps1 -ComputerName "192.168.1.100" -Username "admin" -Password "senha123" -Command "Get-Service"
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [string]$ComputerName,
    
    [Parameter(Mandatory=$false)]
    [string]$Username = "",
    
    [Parameter(Mandatory=$false)]
    [string]$Password = "",
    
    [Parameter(Mandatory=$true)]
    [string]$Command
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

$scriptBlock = {
    param($cmd)
    try {
        $output = Invoke-Expression $cmd | Out-String
        return @{
            success = $true
            output = $output
            exit_code = $LASTEXITCODE
        }
    }
    catch {
        return @{
            success = $false
            error = $_.Exception.Message
            exit_code = 1
        }
    }
}

$remoteResult = Invoke-RemoteCommand `
    -Computer $ComputerName `
    -Credential $credential `
    -ScriptBlock $scriptBlock `
    -ArgumentList $Command

if ($remoteResult.Success) {
    $result = $remoteResult.Result
    Write-Output ($result | ConvertTo-Json -Compress)
    exit 0
}
else {
    $response = @{
        success = $false
        error = $remoteResult.Error
    }
    Write-Output ($response | ConvertTo-Json -Compress)
    exit 1
}
