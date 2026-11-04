# LabControl — Colocar o sistema a funcionar no laboratório

Este guia descreve uma instalação nova num computador Windows que fará de **servidor/controlador** do laboratório. O sistema abre diretamente no Dashboard: **não é necessário criar utilizador, executar o setup de administrador, iniciar sessão nem usar token JWT**.

> **Importante — utilize apenas na rede interna do laboratório.** Neste modo, qualquer pessoa com acesso ao endereço do servidor pode gerir os hosts e executar ações remotas. Não exponha o LabControl à Internet nem a uma rede de convidados. As credenciais Windows pedidas abaixo são das estações e servem para o WinRM; não são um login do LabControl.

## 1. O que é necessário

### No servidor/controlador

- Windows 10/11 ou Windows Server.
- XAMPP com Apache, PHP 8.x e MySQL/MariaDB.
- Extensões PHP `pdo_mysql`, `openssl`, `curl` e `sockets` ativas.
- PowerShell 5.1 ou superior.
- Endereço IP fixo ou reserva DHCP, por exemplo `192.168.21.10`.
- Node.js 18 ou superior **apenas se** quiser o serviço opcional de monitorização em segundo plano.

### Em cada estação que será controlada

- Windows 10/11 Pro, Education, Enterprise ou Windows Server com WinRM.
- Nome de computador, IPv4 e endereço MAC conhecidos.
- Conta local ou de domínio com permissão administrativa para comandos remotos.
- Para Wake-on-LAN: suporte ativado na BIOS/UEFI e na placa de rede.

## 2. Instalar os ficheiros

1. Instale o XAMPP em `C:\xampp`.
2. Para confirmar as extensões PHP, execute `C:\xampp\php\php.exe -m`. Se alguma das extensões indicadas acima faltar, ative-a em `C:\xampp\php\php.ini` e reinicie Apache.
3. Copie todo este projeto para:
2. Copie todo este projeto para:

   ```text
   C:\xampp\htdocs\labcontrol
   ```

4. Confirme que estes caminhos existem:

   ```text
   C:\xampp\htdocs\labcontrol\labcontrol-frontend\index.html
   C:\xampp\htdocs\labcontrol\labcontrol-backend\api\hosts.php
   C:\xampp\htdocs\labcontrol\labcontrol-backend\sql\database.sql
   ```

Não coloque os ficheiros dentro de uma segunda pasta `labcontrol` por engano.

## 3. Criar e configurar o ficheiro `.env`

Abra o **PowerShell** e execute:

```powershell
Set-Location C:\xampp\htdocs\labcontrol
Copy-Item .env.example .env
```

Abra `C:\xampp\htdocs\labcontrol\.env` no Bloco de Notas e configure pelo menos:

```dotenv
DB_HOST=localhost
DB_NAME=labcontrol
DB_USER=root
DB_PASS=
APP_TIMEZONE=Africa/Luanda
FIREBASE_ENABLED=false

CORS_ALLOWED_ORIGINS=http://localhost,http://192.168.21.10

REMOTE_USER=Administrador
REMOTE_PASSWORD=COLOQUE_A_PASSWORD_WINDOWS_DAS_ESTACOES
```

Substitua `192.168.21.10` pelo IPv4 real do servidor. Se o MySQL tiver password, coloque-a em `DB_PASS`.

### Chave para credenciais de hosts

Gere uma chave única com o PHP do XAMPP:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Copie os 64 caracteres apresentados para o `.env`:

```dotenv
ENCRYPTION_KEY=COLE_AQUI_A_CHAVE_DE_64_CARACTERES
```

Guarde o `.env`. Não partilhe nem apague este ficheiro depois de guardar credenciais encriptadas; sem a mesma `ENCRYPTION_KEY`, elas deixam de poder ser decifradas.

> `REMOTE_USER` e `REMOTE_PASSWORD` são opcionais quando cada host tiver credenciais próprias. Numa estação com conta local, pode ser necessário usar `.\Administrador`; em domínio, use o formato `DOMINIO\Utilizador`.

## 4. Criar a base de dados

1. Abra o **XAMPP Control Panel**.
2. Inicie **Apache** e **MySQL**.
3. No PowerShell, importe o esquema:

   ```powershell
   cmd.exe /c "C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\labcontrol\labcontrol-backend\sql\database.sql"
   ```

   O `cmd.exe /c` é usado porque o PowerShell não aceita o redirecionamento de entrada `<` do MySQL.

   Se o utilizador `root` tiver password, use `-p` e introduza-a quando solicitado:

   ```powershell
   cmd.exe /c "C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\labcontrol\labcontrol-backend\sql\database.sql"
   ```

4. **Apenas numa instalação nova**, remova os hosts e logs de demonstração antes de registar o parque real. Pode executar no separador SQL do phpMyAdmin:

   ```sql
   USE labcontrol;
   DELETE FROM logs;
   DELETE FROM hosts;
   ALTER TABLE hosts AUTO_INCREMENT = 1;
   ```

   Não execute estes `DELETE` numa base que já contenha dados reais.

## 5. Abrir o sistema — sem login

No próprio servidor, abra:

```text
http://localhost/labcontrol/labcontrol-frontend/
```

De outro computador da mesma rede, abra:

```text
http://IP_DO_SERVIDOR/labcontrol/labcontrol-frontend/
```

Exemplo:

```text
http://192.168.21.10/labcontrol/labcontrol-frontend/
```

O Dashboard deve aparecer diretamente com a indicação **ACESSO LOCAL DIRETO**. Não execute `setup.php` e não crie `admin@labcontrol.local`: esse fluxo pertence às versões anteriores e já não é necessário.

### Teste rápido da API, também sem token

```powershell
Invoke-RestMethod "http://localhost/labcontrol/labcontrol-backend/api/hosts.php?action=list"
Invoke-RestMethod "http://localhost/labcontrol/labcontrol-backend/api/logs.php?action=list&limit=5"
```

Uma resposta válida contém `"success": true` e **não** devolve HTTP 401 por falta de `Authorization`.

## 6. Registar as estações reais

1. No Dashboard, clique no botão azul **+**.
2. Preencha:
   - **Identification**: o nome real do computador Windows, por exemplo `LAB17-PC35`.
   - **Network Address (IP)**: o IPv4 atual/reservado da estação.
   - **Physical Address (MAC)**: necessário para Wake-on-LAN, no formato `AA:BB:CC:DD:EE:FF`.
3. Clique em **Confirm & Register Host**.
4. Aguarde alguns segundos: o painel verifica o estado por ping a cada 10 segundos.
5. Para editar nome, IP ou MAC, abra o host e clique no lápis.
6. Para remover um host, abra o mesmo painel de edição e use **Remove Host from System**.

No Windows de uma estação pode obter os dados com:

```powershell
hostname
ipconfig
getmac /v
```

Utilize reservas DHCP ou IPs fixos para que os registos não fiquem desatualizados.

## 7. Ativar o controlo remoto WinRM

O ping mostra apenas se a máquina responde na rede. Processos, reinício, desligamento e comandos exigem WinRM e credenciais administrativas válidas.

### 7.1. Em cada estação

Abra o PowerShell **como Administrador** e execute:

```powershell
Enable-PSRemoting -Force
Set-Service WinRM -StartupType Automatic
Start-Service WinRM
Get-NetConnectionProfile
```

A rede do laboratório deve estar como `Private`/`Privada`, não como rede pública. Confirme o serviço:

```powershell
Get-Service WinRM
```

O estado esperado é `Running`.

### 7.2. No servidor/controlador

Ative o cliente/serviço WinRM no controlador. Se as máquinas estiverem num domínio, normalmente o Kerberos já resolve os alvos pelo nome. Num grupo de trabalho, adicione os nomes/IPs das estações a `TrustedHosts` **no computador que inicia a ligação (o servidor)**:

```powershell
Enable-PSRemoting -Force
Set-Item WSMan:\localhost\Client\TrustedHosts -Value "LAB17-PC35,LAB17-PC36" -Force
Restart-Service WinRM
```

Adapte a lista a todas as estações. Este comando substitui a lista existente; se já houver outros alvos, inclua-os também.

Teste a conectividade e a conta administrativa a partir do servidor:

```powershell
Test-WSMan -ComputerName LAB17-PC35
Invoke-Command -ComputerName LAB17-PC35 -Credential (Get-Credential) -ScriptBlock { hostname }
```

No segundo comando, introduza a mesma conta Windows configurada em `REMOTE_USER`/`REMOTE_PASSWORD`. O resultado deve ser o nome da estação.

> Se o Apache estiver instalado como serviço, a conta desse serviço também precisa de permissão para executar `powershell.exe` e aceder à rede. Para diagnóstico inicial, execute Apache pelo XAMPP Control Panel numa sessão de administrador.

## 8. Configurar Wake-on-LAN (opcional)

Em cada estação que deva ser ligada remotamente:

1. Ative Wake-on-LAN/Magic Packet na BIOS/UEFI.
2. Nas propriedades da placa de rede Windows, ative **Wake on Magic Packet**.
3. Desative, se necessário, a opção de poupança de energia que permite ao computador desligar completamente a placa.
4. Registe o MAC correto no LabControl.
5. Mantenha servidor e estações na mesma rede/VLAN ou configure o encaminhamento de pacotes WOL entre VLANs.

Com o host desligado, clique no ícone de raio junto ao host. O envio do pacote não garante o arranque se BIOS, placa, switch ou alimentação não estiverem configurados.

## 9. Monitorização em segundo plano (opcional)

O Dashboard já faz verificações periódicas enquanto está aberto. O `sync-service` é opcional e serve para continuar a atualizar o estado dos hosts quando nenhum navegador está aberto. Não é necessário para o login (que já não existe), CRUD ou controlo remoto.

```powershell
Set-Location C:\xampp\htdocs\labcontrol\sync-service
Copy-Item .env.example .env
notepad .env
npm install
npm start
```

No `sync-service/.env`, confirme os mesmos dados do MySQL. Para produção, deixe o worker configurado como serviço com o NSSM incluído no projeto. Para encerrar uma execução manual, use `Ctrl+C`.

Firebase é opcional e pode permanecer com `FIREBASE_ENABLED=false` numa instalação totalmente local.

## 10. Acesso a partir de outros computadores

1. Descubra o IP do servidor com `ipconfig`.
2. Confirme que o Apache escuta na rede e que a Firewall do Windows permite **Apache HTTP Server** na rede privada. Se necessário, autorize a porta TCP 80 apenas na rede do laboratório.
3. No `.env`, inclua o endereço usado no navegador em `CORS_ALLOWED_ORIGINS`, por exemplo:

   ```dotenv
   CORS_ALLOWED_ORIGINS=http://localhost,http://192.168.21.10
   ```

4. Reinicie Apache depois de alterar configurações e teste o URL externo indicado no passo 5.
5. Mantenha o projeto no caminho `/labcontrol`; o frontend utiliza esse caminho para localizar a API.

## 11. Arranque diário recomendado

1. Ligue o servidor/controlador.
2. Abra o XAMPP e inicie **MySQL** e **Apache**.
3. Se utilizar o worker opcional, inicie o `sync-service` ou confirme o seu serviço NSSM.
4. Abra o URL do frontend num navegador atualizado.
5. Verifique se os hosts aparecem e se os estados são atualizados.
6. Antes de **Shutdown All**, confirme que nenhuma aula/trabalho será interrompida.

Para uso permanente, configure Apache e MySQL como serviços do Windows no XAMPP.

## 12. Checklist de aceitação no laboratório

- [ ] O Dashboard abre sem formulário de login e sem pedir token.
- [ ] `hosts.php?action=list` e `logs.php?action=list` respondem sem `Authorization`.
- [ ] É possível registar, editar (incluindo o IP) e remover um host.
- [ ] O estado online/offline muda após ligar/desligar uma estação de teste.
- [ ] Os eventos aparecem em **Audit Logs** como `operador@labcontrol.local`.
- [ ] A listagem de processos funciona numa estação de teste com WinRM.
- [ ] Reiniciar e desligar foram testados primeiro numa única estação.
- [ ] Wake-on-LAN foi testado numa estação compatível.
- [ ] O acesso funciona pelo IP do servidor a partir de outro PC autorizado.
- [ ] O sistema não está acessível a partir da Internet/rede de convidados.

## 13. Resolução de problemas

### O navegador mostra erro de ligação ou a lista fica vazia

- Confirme Apache e MySQL no XAMPP.
- Abra diretamente `http://localhost/labcontrol/labcontrol-backend/api/hosts.php?action=list`.
- Confirme que o frontend está em `/labcontrol/labcontrol-frontend/`.
- Consulte `C:\xampp\apache\logs\error.log` e a consola do navegador (F12).

### Erro de conexão à base de dados

- Confirme `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` no `.env`.
- Verifique se a base `labcontrol` existe no phpMyAdmin.
- Se acabou de alterar o `.env`, reinicie Apache e atualize a página com `Ctrl+F5`.

### Todos os hosts ficam offline, mas estão ligados

- Teste `ping IP_DA_ESTACAO` a partir do servidor.
- Permita ICMP/ping na Firewall das estações.
- Confirme IP, VLAN, cabo/Wi-Fi e reserva DHCP.

### Aparece “Credenciais remotas não configuradas”

- Configure `REMOTE_USER` e `REMOTE_PASSWORD` no `.env`, ou credenciais específicas para o host através da API.
- Valide a conta com `Invoke-Command ... -Credential (Get-Credential)`.

### WinRM recusa a ligação

- Confirme `Enable-PSRemoting`, o serviço WinRM e a Firewall na estação.
- Em grupo de trabalho, confirme `TrustedHosts` no servidor.
- Use o hostname exato ou inclua o IP nos alvos confiáveis.
- Teste a conta e o formato `.\Utilizador` ou `DOMINIO\Utilizador`.

### O painel ainda mostra uma versão antiga

Atualize com `Ctrl+F5` ou limpe a cache do navegador. Os ficheiros JavaScript desta versão usam o identificador de cache `v=2.0`.

## 14. Cópias de segurança

Inclua nas cópias, em local restrito:

- A base de dados `labcontrol`.
- O ficheiro raiz `.env`, especialmente a `ENCRYPTION_KEY`.
- Quaisquer credenciais/configurações específicas da instalação.

Não coloque cópias do `.env`, dumps com credenciais ou logs sensíveis numa pasta pública do Apache.
