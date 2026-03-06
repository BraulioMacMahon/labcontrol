# Documentação de Manutenção e Evolução - LabControl

## 1. Objetivo
Este documento serve como guia para administradores e desenvolvedores que desejam manter o sistema atualizado, corrigir falhas ou expandir as funcionalidades atuais do LabControl.

## 2. Checklist de Manutenção Periódica

### 2.1. Atualizações de Dependências (NPM)
O serviço de sincronização (`sync-service`) utiliza Node.js. Recomendamos verificar vulnerabilidades a cada 3 meses:
1.  Acesse a pasta `sync-service`.
2.  Execute `npm audit` para identificar problemas de segurança.
3.  Execute `npm update` para aplicar patches menores.

### 2.2. Segurança e Patches do Windows
O servidor que hospeda o XAMPP e o Node.js deve manter as atualizações do Windows em dia, especialmente os patches relacionados ao **WinRM** e **PowerShell**, que são o coração da plataforma.

### 2.3. Auditoria de Logs
Verifique periodicamente o tamanho do banco de dados (tabela `logs`).
*   Configuração padrão: 90 dias de retenção (`log_retention_days`).
*   Scripts PHP ou tarefas cron podem ser configurados para limpar logs antigos.

---

## 3. Gestão de Inventário (Adicionar Novos Hosts)

Existem três formas de adicionar máquinas ao sistema:

1.  **Interface Gráfica (Frontend):** Clique no botão flutuante (+) no Dashboard e preencha IP, Hostname e MAC Address.
2.  **Importação SQL:** Insira múltiplos registros diretamente na tabela `hosts`.
    ```sql
    INSERT INTO hosts (ip, hostname, mac_address) VALUES ('192.168.1.10', 'PC-NOVO', '00:11:22:33:44:55');
    ```
3.  **Importação via CSV:** (Se implementado via script) Utilize o arquivo `migrate-tables.php` como referência para carga em massa.

---

## 4. Guia de Extensibilidade (Novas Features)

### 4.1. Como adicionar um novo Comando PowerShell
Para adicionar uma funcionalidade como "Instalar um Software" ou "Limpar Disco":
1.  **Crie o Script:** Salve um novo arquivo `.ps1` em `labcontrol-backend/powershell/`.
2.  **Atualize o Backend:** No arquivo `control.php`, adicione um novo `case` dentro do switch `$action`.
    *   Siga o padrão de gerar script temporário e usar `exec()`.
3.  **Atualize o Frontend:**
    *   No `app.js`, adicione um botão no componente de detalhes do host.
    *   Crie a função de clique que chama `api.request('control.php?action=seu_novo_comando', ...)`.

### 4.2. Como adicionar novas métricas no Sync Service
Para enviar novos dados para a nuvem (ex: temperatura do PC):
1.  Adicione a coluna na tabela `hosts` no MySQL.
2.  No arquivo `sync-service/index.js`, atualize a query de monitoramento para incluir o novo campo.
3.  O serviço fará o push automático para a referência correspondente no Firebase.

---

## 5. Backup e Recuperação de Desastres

### 5.1. Backup do Banco de Dados
Recomendamos o backup diário via CLI ou script automatizado:
```bash
mysqldump -u root labcontrol > C:\backups\labcontrol_backup_%date%.sql
```

### 5.2. Backup de Arquivos
Sempre mantenha uma cópia de:
*   Pasta `labcontrol-backend/config/` (Contém as chaves de criptografia e credenciais no `.env`).
*   Pasta `sync-service/.env`.
*   Scripts customizados em `powershell/`.

### 5.3. Procedimento de Restore
1.  Reinstale o ambiente (XAMPP/Node.js).
2.  Restaure o banco de dados: `mysql -u root labcontrol < backup.sql`.
3.  Substitua os arquivos da pasta `htdocs/labcontrol` pela cópia de backup.
4.  Re-inicialize o `sync-service`.

---

## 6. Boas Práticas de Desenvolvimento
*   **Versionamento:** Utilize Git para rastrear mudanças nos scripts PowerShell e lógica de API.
*   **Ambiente de Teste:** Nunca teste novos scripts PowerShell diretamente em máquinas de produção sem validar localmente.
*   **Logs de Erro:** Em caso de falha silenciosa, verifique `labcontrol-backend/logs/error_YYYY-MM-DD.log`.

---
*Documentação Gerada em: 06/03/2026*
