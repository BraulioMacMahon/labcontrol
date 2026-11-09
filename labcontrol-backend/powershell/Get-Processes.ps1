<#
.SYNOPSIS
    Lista processos de um host remoto através do WinRM.
.DESCRIPTION
    Segue a consulta já validada no laboratório: Get-Process e exclusão dos
    caminhos do Windows. Emite sempre um envelope JSON com um array processes.
    Todas as linhas executáveis são ASCII para Windows PowerShell 5.1.
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

try {
    $utf8 = New-Object System.Text.UTF8Encoding -ArgumentList $false
    $OutputEncoding = $utf8
    [Console]::OutputEncoding = $utf8
} catch { }

function Write-JsonResult {
    param([hashtable]$Payload)
    [Console]::Out.WriteLine((ConvertTo-Json -InputObject $Payload -Compress -Depth 5))
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

if ([string]::IsNullOrWhiteSpace($ComputerName)) {
    Write-JsonResult @{ success = $false; error = 'ERRO: ComputerName nao fornecido.' }
    exit 1
}

$session = $null
$exitCode = 0
$response = $null
try {
    $securePassword = ConvertTo-SecureString -String $Password -AsPlainText -Force
    $credential = New-Object System.Management.Automation.PSCredential($Username, $securePassword)
    $sessionOption = New-PSSessionOption -OpenTimeout 10000 -CancelTimeout 5000 -OperationTimeout 15000
    $session = New-PSSession -ComputerName $ComputerName -Credential $credential -Authentication Negotiate -SessionOption $sessionOption -ErrorAction Stop

    # This is the laboratory-tested query, returned as objects (not Format-Table)
    # so that PHP can serialize both an empty list and a single process correctly.
    $processes = @(Invoke-Command -Session $session -ErrorAction Stop -ScriptBlock {
        Get-Process | Where-Object {
            $_.Path -notlike 'C:\Windows\System32\*' -and
            $_.Path -notlike 'C:\Windows\SysWOW64\*' -and
            $null -ne $_.Path
        } | Select-Object Name, Id, Path
    })

    $response = @{ success = $true; processes = @($processes) }
}
catch {
    $message = [string]$_.Exception.Message
    $kind = Get-RemoteErrorKind -Message $message
    $response = @{ success = $false; error = "${kind}: $message" }
    $exitCode = 1
}
finally {
    if ($null -ne $session) {
        try { Remove-PSSession -Session $session -ErrorAction SilentlyContinue } catch { }
    }
}

Write-JsonResult $response
exit $exitCode
