# Documentação da API - LabControl

## 1. Visão Geral
A API do LabControl é uma interface RESTful baseada em PHP que fornece endpoints para gerenciamento de hosts, execução de comandos remotos, visualização de logs e autenticação. Todas as respostas são retornadas no formato **JSON**.

### Base URL
`http://seu-servidor/labcontrol/labcontrol-backend/api/`

---

## 2. Autenticação
A maioria dos endpoints exige autenticação via **JWT (JSON Web Token)** enviado no header `Authorization`.

**Exemplo de Header:**
`Authorization: Bearer <seu_token_aqui>`

Para obter um token, utilize o endpoint de `login`.

---

## 3. Endpoints Principais

### 3.1. Autenticação (`auth.php`)
| Ação | Método | Descrição |
| :--- | :--- | :--- |
| `login` | POST | Autentica o usuário e retorna o token JWT. |
| `verify` | GET | Verifica se o token atual é válido. |
| `users` | GET | Lista os usuários cadastrados (apenas Admin). |

### 3.2. Controle Remoto (`control.php`)
| Ação | Método | Parâmetros | Descrição |
| :--- | :--- | :--- | :--- |
| `status` | GET | `hostname` ou `ip` | Retorna o status detalhado do host (online/offline). |
| `processes` | GET | `hostname` ou `ip` | Lista processos ativos via PowerShell. |
| `shutdown` | POST | `hostname` ou `ip` | Desliga a máquina remotamente. |
| `restart` | POST | `hostname` ou `ip` | Reinicia a máquina remotamente. |
| `wol` | POST | `mac_address` | Envia o "Magic Packet" para Wake-on-LAN. |
| `killprocess`| POST | `pid`, `hostname` | Encerra um processo específico. |

### 3.3. Gerenciamento de Hosts (`hosts.php`)
| Ação | Método | Descrição |
| :--- | :--- | :--- |
| `list` | GET | Lista todos os hosts cadastrados no inventário. |
| `create` | POST | Adiciona um novo host (IP, MAC, Hostname). |
| `update` | PUT | Atualiza dados de um host existente. |
| `delete` | DELETE | Remove um host do sistema. |
| `stats` | GET | Retorna estatísticas de disponibilidade (quantos online/offline). |

### 3.4. Auditoria e Logs (`logs.php`)
| Ação | Método | Descrição |
| :--- | :--- | :--- |
| `list` | GET | Retorna o histórico de ações realizadas no sistema. |
| `search` | GET | Filtra logs por período, host ou usuário. |
| `export` | GET | Exporta os logs em formato compatível com planilhas. |

---

## 4. Estrutura de Resposta (JSON)

Todas as respostas seguem o padrão abaixo:

```json
{
  "success": true,
  "message": "Operação realizada com sucesso",
  "timestamp": "2026-03-06 11:30:00",
  "data": { ... }
}
```

**Erros:**
Em caso de erro (HTTP 4xx ou 5xx), o campo `success` será `false`.

---

## 5. Exemplos Práticos (cURL)

### Realizar Login
```bash
curl -X POST http://localhost/labcontrol/labcontrol-backend/api/auth.php?action=login \
     -H "Content-Type: application/json" \
     -d '{"email": "admin@labcontrol.local", "password": "admin123"}'
```

### Listar Processos de um PC
```bash
curl -X GET "http://localhost/labcontrol/labcontrol-backend/api/control.php?action=processes&hostname=PC-LAB01" \
     -H "Authorization: Bearer <seu_token>"
```

### Enviar Shutdown Remoto
```bash
curl -X POST http://localhost/labcontrol/labcontrol-backend/api/control.php?action=shutdown \
     -H "Authorization: Bearer <seu_token>" \
     -H "Content-Type: application/json" \
     -d '{"hostname": "PC-LAB01"}'
```

---

## 6. Permissões
*   **Admin:** Acesso total a todos os endpoints, incluindo gerenciamento de usuários.
*   **Operator:** Pode monitorar e enviar comandos de controle, mas não pode criar/remover usuários ou deletar logs.

---
*Documentação Gerada em: 06/03/2026*
