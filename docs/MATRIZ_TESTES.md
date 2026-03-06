# 📊 Matriz de Testes - LabControl

## 1. Testes de Segurança

### 1.1 Autenticação

| Cenário | Entrada | Esperado | Implementado |
|---------|---------|----------|--------------|
| Login válido | email correto + senha correta | Token JWT válido | ✅ Sim |
| Login inválido | email correto + senha errada | Erro 401 | ✅ Sim |
| Email não existe | email inexistente | Erro 401 | ✅ Sim |
| Email vazio | email = "" | Erro 400 | ✅ Sim |
| Senha vazia | password = "" | Erro 400 | ✅ Sim |
| Email inválido | email = "notanemail" | Erro 400 | ✅ Sim |
| Rate limiting | 6 tentativas em 5 min | Bloqueado (429) | ❌ Não |
| Token expirado | Bearer com exp < now | Erro 401 com refresh | ⚠️ Parcial |
| Token inválido | Bearer com assinatura falsa | Erro 401 | ✅ Sim |
| Refresh token | POST /auth?action=refresh | Novo token | ✅ Sim |

### 1.2 Autorização

| Cenário | Role | Ação | Esperado | Status |
|---------|------|------|----------|--------|
| User criar host | user | POST /hosts?action=create | Erro 403 | ❌ Não |
| Admin criar host | admin | POST /hosts?action=create | Sucesso | ✅ Sim |
| User listar hosts | user | GET /hosts?action=list | Sucesso | ✅ Sim |
| User controlar host | user | POST /control?action=shutdown | Erro 403 | ❌ Não |
| Admin controlar host | admin | POST /control?action=shutdown | Sucesso | ✅ Sim |
| Acessar outro host | user | GET /hosts?action=get&id=999 | Só se permissão | ⚠️ Parcial |

### 1.3 Injeção

| Tipo | Entrada | Vulnerável? |
|------|---------|------------|
| SQL | `' OR '1'='1` em hostname | ❌ Seguro (prepared statements) |
| XSS | `<script>alert('xss')</script>` em hostname | ⚠️ Armazenado (sem sanitização no frontend) |
| Command | `; rm -rf /` em hostname | ✅ PowerShell com array args (seguro) |
| LDAP | `*`(objectClass=*)` se LDAP | N/A (não implementado) |

### 1.4 Headers e Conformidade

| Header | Presente? | Valor Esperado |
|--------|----------|-----|
| X-Frame-Options | ❌ Não | SAMEORIGIN |
| X-Content-Type-Options | ❌ Não | nosniff |
| X-XSS-Protection | ❌ Não | 1; mode=block |
| Content-Security-Policy | ❌ Não | default-src 'self' |
| Strict-Transport-Security | ❌ Não | max-age=... (HTTPS) |
| Access-Control-Allow-Origin | ✅ Sim | * (INSEGURO!) |

---

## 2. Testes de Funcionalidade

### 2.1 Hosts - CRUD

```javascript
// Test Suite: Host Management

// CREATE - ✅ Working
POST /api/hosts.php?action=create
{
  "hostname": "PC-LAB-01",
  "ip": "192.168.1.100",
  "os_type": "Windows",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "description": "Máquina de teste"
}

// READ - ✅ Working
GET /api/hosts.php?action=list
GET /api/hosts.php?action=get&id=1

// UPDATE - ✅ Working
PUT /api/hosts.php?action=update&id=1
{
  "description": "Updated"
}

// DELETE - ✅ Working
DELETE /api/hosts.php?action=delete&id=1
```

### 2.2 Controle Remoto

| Ação | Script | Status |
|------|--------|--------|
| Shutdown | Shutdown-Host.ps1 | ✅ Implementado |
| Restart | Restart-Host.ps1 | ✅ Implementado |
| Get Processes | Get-Processes.ps1 | ✅ Implementado |
| Kill Process | Kill-Process.ps1 | ✅ Implementado |
| Wake-on-LAN | Falta implementar | ❌ Não |
| Get System Info | Get-SystemInfo.ps1 | ✅ Implementado |
| Execute Command | Execute-Command.ps1 | ⚠️ Perigoso |

### 2.3 Logs e Auditoria

| Evento | Registrado | Detalhes |
|--------|-----------|----------|
| Login sucesso | ✅ Sim | user_id, email, ip, timestamp |
| Login falho | ✅ Sim | tentativa, email, ip |
| Logout | ✅ Sim | user_id |
| Create host | ✅ Sim | user_id, host_id, details |
| Delete host | ✅ Sim | user_id, host_id |
| Shutdown | ✅ Sim | user_id, host_id, timestamp |
| Mudança de permissão | ❌ Não | Não registrado |

---

## 3. Testes de Performance

### 3.1 Endpoints

```bash
# Ferramenta: Apache Bench
ab -n 100 -c 10 http://localhost/labcontrol/labcontrol-backend/api/hosts.php?action=list

# Benchmark esperado:
- Resposta média: < 200ms
- 90º percentil: < 500ms
- 99º percentil: < 1000ms
```

### 3.2 Carregamento de Dados

| Operação | Qtd Registros | Tempo Esperado | Status |
|----------|--------------|----------|--------|
| List hosts | 100 | < 100ms | ⚠️ Sem paginação |
| List hosts | 1000 | < 500ms | ❌ Carrega tudo |
| Get logs | 1000 | < 200ms | ⚠️ Sem filtro |
| Sync Firebase | 100 | < 2s | ✅ Aceitável |

### 3.3 Cache

| Recurso | Cache? | TTL | Impacto |
|---------|--------|-----|--------|
| Host list | ❌ Não | - | Sem cache (faz query sempre) |
| User data | ❌ Não | - | Sem cache |
| System stats | ❌ Não | - | Sem cache |
| Logs | ❌ Não | - | Sem cache |

**Recomendação**: Implementar Redis com TTL de 5-10 minutos.

---

## 4. Testes de Compatibilidade

### 4.1 Browsers (Frontend)

| Browser | Versão | Status |
|---------|--------|--------|
| Chrome | Latest | ✅ Suportado |
| Firefox | Latest | ✅ Suportado |
| Safari | Latest | ✅ Suportado |
| Edge | Latest | ✅ Suportado |
| IE 11 | - | ❌ Não suportado |

### 4.2 Devices

| Device | Resolução | Status |
|--------|-----------|--------|
| Desktop | 1920x1080+ | ✅ Excelente |
| Tablet | 768x1024 | ✅ Bom |
| Mobile | 375x667 | ✅ Bom (Tailwind responsive) |
| Mobile pequeno | < 375px | ⚠️ Marginal |

### 4.3 PHP/MySQL

| Componente | Versão | Status |
|-----------|--------|--------|
| PHP | 7.4+ | ✅ OK |
| MySQL | 5.7+ | ✅ OK |
| MariaDB | 10.3+ | ✅ OK |
| Apache | 2.4+ | ✅ OK |

---

## 5. Cobertura de Código (Recomendado)

### Atual: ~0%
### Alvo: 80%+

```bash
# Usar PHPUnit
composer require --dev phpunit/phpunit

# Executar testes
./vendor/bin/phpunit --coverage-html coverage/

# Gerar relatório
phpunit --coverage-text
```

### Arquivos sem testes:

- [ ] Database.php (crítica)
- [ ] FirebaseIntegration.php (crítica)
- [ ] auth.php (crítica)
- [ ] hosts.php (crítica)
- [ ] control.php (crítica)
- [ ] Validator.php (importante)
- [ ] RateLimiter.php (importante)

---

## 6. Casos de Teste para Implementar

### 6.1 PHPUnit - Database

```php
<?php

class DatabaseTest extends \PHPUnit\Framework\TestCase {
    private $db;
    
    public function setUp(): void {
        $this->db = Database::getInstance();
    }
    
    public function testSelectReturnsArray() {
        $result = $this->db->select("SELECT * FROM hosts LIMIT 1");
        $this->assertIsArray($result);
    }
    
    public function testInsertReturnsId() {
        $id = $this->db->insert('hosts', [
            'hostname' => 'TEST-PC',
            'ip' => '192.168.1.1',
            'status' => 'offline'
        ]);
        $this->assertGreaterThan(0, $id);
    }
    
    public function testPreparedStatementPreventsInjection() {
        // SQL injections devem ser neutralizadas
        $result = $this->db->select(
            "SELECT * FROM hosts WHERE hostname = ?",
            ["'; DROP TABLE hosts; --"]
        );
        $this->assertTrue(count($result) >= 0); // Não deve falhar
    }
}
```

### 6.2 Jest - Frontend

```javascript
// app.test.js

describe('LabControl Frontend', () => {
    
    test('AuthUI renders correctly', () => {
        const ui = Components.AuthUI();
        expect(ui).toContain('LabControl');
        expect(ui).toContain('Email');
        expect(ui).toContain('Password');
    });
    
    test('Login handles correct credentials', async () => {
        // Mock da API
        const response = await api.login('admin@test.com', 'admin123');
        expect(response.success).toBe(true);
        expect(response.data.token).toBeDefined();
    });
    
    test('Login rejects wrong credentials', async () => {
        const response = await api.login('admin@test.com', 'wrong');
        expect(response.success).toBe(false);
    });
});
```

---

## 7. Plano de Testes para Produção

### Semana 1: Segurança
- [ ] Penetration testing
- [ ] OWASP Top 10 check
- [ ] Análise de dependências

### Semana 2: Funcionalidade
- [ ] Teste manual de todos os endpoints
- [ ] Teste de integrações (Firebase, WinRM)
- [ ] Teste offline/online

### Semana 3: Performance
- [ ] Load testing (100, 1000, 10000 usuários)
- [ ] Memory leaks
- [ ] Database optimization

### Semana 4: Conformidade
- [ ] GDPR compliance
- [ ] Backup/Recovery
- [ ] Documentação

---

## 8. Ferramentas Recomendadas

### Backend Testing
```bash
# PHPUnit - Testes Unitários
composer require --dev phpunit/phpunit

# PHPSTAN - Análise Estática
composer require --dev phpstan/phpstan

# PHP Code Sniffer - Padrão de Código
composer require --dev squizlabs/php_codesniffer

# Security Checker
composer require --dev roave/security-advisories
```

### Frontend Testing
```bash
# Jest - Testes JavaScript
npm install --save-dev jest @testing-library/dom

# Cypress - E2E
npm install --save-dev cypress

# ESLint - Linting
npm install --save-dev eslint
```

### API Testing
```bash
# Postman Collection:
# Importar: labcontrol-api.postman_collection.json

# Curl scripts:
# ./scripts/test-api.sh
```

### Security Testing
```bash
# OWASP ZAP
# https://www.zaproxy.org/

# Burp Suite Community
# https://portswigger.net/burp

# npm: snyk
npm install -g snyk
snyk test
```

---

## 9. Métricas de Qualidade

### Alvo para Produção

| Métrica | Alvo | Atual |
|---------|------|-------|
| Code Coverage | 80%+ | 0% |
| Código Crítico | 100% | ~50% |
| Vulnerabilidades | 0 | 8 |
| Código Duplicado | < 5% | ~20% |
| Complexidade Max | 10 | ~15 |
| Tempo Resposta P90 | < 500ms | ~200ms |
| Uptime | 99%+ | ~95% |

---

## 10. GitHub Actions / CI/CD

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: labcontrol_test
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '7.4'
        extensions: pdo_mysql, openssl
    
    - name: Install dependencies
      run: composer install
    
    - name: Run PHPUnit
      run: ./vendor/bin/phpunit --coverage-clover coverage.xml
    
    - name: Upload coverage
      uses: codecov/codecov-action@v2
```

---

**Status Geral**: ⚠️ Cobertura muito baixa, precisa testes antes de produção

**Próximo Passo**: Implementar Phase 1 de testes (segurança + funcionalidade básica)
