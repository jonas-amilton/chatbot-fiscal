# Chatbot Fiscal

Assistente fiscal em português: o modelo **Gemma 4 E2B** (via Ollama) escolhe a ferramenta do servidor [mcp-fiscal-brasil](https://github.com/DeHor-Labs/mcp-fiscal-brasil) — CNPJ, NCM, CFOP, CST, CEST, ICMS e Reforma Tributária — e redige a resposta.

> Conteúdo informativo. Não substitui contador, legislação vigente ou consulta oficial.

## Como funciona

```text
navegador
   │  http://127.0.0.1:8100
   ▼
app (Laravel)
   ├──► ollama (rede "llm", sem porta no host) → gemma4:e2b
   ├──► mcp-fiscal (rede padrão)               → ferramentas fiscais
   └──► db (PostgreSQL, volume pgdata)         → conversas e mensagens
```

Tudo roda em containers. O `app` publica `127.0.0.1:8100`; o `mcp-fiscal` publica `127.0.0.1:8002` apenas para depuração. O Ollama e o PostgreSQL não têm porta no host.

| Serviço | Acesso |
|---|---|
| `app` | http://127.0.0.1:8100 |
| `ollama` | interno, sem porta no host |
| `mcp-fiscal` | interno; http://127.0.0.1:8002 para depuração |
| `db` | interno, sem porta no host |

### LLM isolada no Docker

O `ollama` fica na rede interna `llm`, sem porta publicada. O `app` é o único serviço dessa rede e o alcança por `http://ollama:11434`. Não há nada escutando em `localhost:11434`.

Para baixar ou listar modelos, use o próprio container:

```bash
docker compose exec ollama ollama pull gemma4:e2b
docker compose exec ollama ollama list
```

## Requisitos

- Docker Desktop (macOS/Windows) ou Docker Engine + Compose v2 (Linux)
- Git
- ~5 GB livres para o modelo
- Portas `8100` e `8002` livres

Roda em Linux, macOS e Windows. O fluxo abaixo foi testado em Linux; no Docker Desktop, os comandos são os mesmos.

## Primeira execução

### 1. Clone os dois repositórios lado a lado

O Compose compila o servidor fiscal a partir de `../mcp-fiscal-brasil`:

```bash
mkdir -p ~/fiscal && cd ~/fiscal
git clone https://github.com/jonas-amilton/chatbot-fiscal.git
git clone https://github.com/DeHor-Labs/mcp-fiscal-brasil.git
cd chatbot-fiscal
```

### 2. Configure o `.env`

```bash
cp .env.example .env
```

Defina uma senha para o banco (o Compose usa esse valor ao criar o container):

```dotenv
DB_PASSWORD=troque-esta-senha
```

Gere a chave da aplicação e cole o resultado em `APP_KEY`:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

### 3. Suba a stack

```bash
docker volume create ollama
docker compose up -d --build
```

O volume `ollama` guarda o modelo e é externo. O volume `pgdata` é criado automaticamente e guarda o banco.

### 4. Baixe o modelo (só na primeira vez)

```bash
docker compose exec ollama ollama pull gemma4:e2b
```

### 5. Crie as tabelas

```bash
docker compose exec app php artisan migrate --force
```

Abra **http://127.0.0.1:8100**.

## Uso

Digite a pergunta no chat. Exemplos:

- `Qual o CFOP de venda de mercadoria dentro do estado?`
- `Consulte o CNPJ 00.000.000/0001-91.`
- `Simule a Reforma Tributária para o NCM 2202.10.00.`

O histórico fica no PostgreSQL, vinculado a um cookie anônimo (`visitor_token`). Não há login.

## Configuração

Variáveis da aplicação no `.env`:

| Variável | Padrão | Função |
|---|---|---|
| `LLM_MODEL` | `gemma4:e2b` | modelo do Ollama |
| `LLM_API_KEY` | `ollama` | token enviado ao Ollama |
| `LLM_TIMEOUT` | `120` | timeout da chamada à LLM, em segundos |
| `MCP_FISCAL_TIMEOUT` | `10` | timeout das ferramentas fiscais |
| `MCP_TABELAS_DB` | `../mcp-fiscal-brasil/...` | base SQLite usada por `fiscal:import-cest` no host |
| `DB_DATABASE` | `chatbot_fiscal` | nome do banco |
| `DB_USERNAME` | `chatbot_fiscal` | usuário do banco |
| `DB_PASSWORD` | — | senha do banco; obrigatória |

O Compose define `DB_HOST=db`, `LLM_BASE_URL=http://ollama:11434`, `MCP_FISCAL_TOOLS_URL=http://mcp-fiscal:8000/mcp` e `APP_URL=http://127.0.0.1:8100`; esses valores do `.env` são ignorados dentro dos containers.

O banco é inicializado na primeira subida com `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`. Se mudar esses valores depois, o Postgres mantém o usuário/senha antigos; ajuste com `ALTER ROLE` ou recrie o volume `pgdata` (perde os dados).

Para trocar o modelo:

```bash
# edite LLM_MODEL no .env
docker compose exec ollama ollama pull <modelo>
docker compose up -d --force-recreate app
```

## Comandos úteis

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f db
docker compose exec app sh
docker compose exec db psql -U chatbot_fiscal -d chatbot_fiscal
docker compose exec ollama ollama list
docker compose down        # mantém os volumes
docker compose down -v     # apaga o pgdata (o volume externo ollama é preservado)
```

Backup do banco (restaure em um banco vazio):

```bash
docker compose exec -T db pg_dump -U chatbot_fiscal -d chatbot_fiscal > backup.sql
docker compose exec -T db psql -U chatbot_fiscal -d chatbot_fiscal < backup.sql
```

Teste a LLM de dentro do app:

```bash
docker compose exec app php -r 'echo file_get_contents("http://ollama:11434/api/tags");'
```

## Manutenção

- **Retenção LGPD:** `fiscal:prune-conversations --days=30` roda diariamente às 03:00 pelo agendador do Laravel. O agendador precisa de um disparo externo, por exemplo no crontab do host:

  ```cron
  * * * * * cd /var/www/chatbot-fiscal && docker compose exec -T app php artisan schedule:run
  ```

- **Tabela CEST completa:** veja `storage/app/dados/README.md` (download no CONFAZ e conversão do CSV). Rode `fiscal:import-cest` no host (com PHP), a partir da raiz do projeto, ajustando `MCP_TABELAS_DB` para o caminho do repositório irmão; depois reconstrua o container do MCP:

  ```bash
  php artisan fiscal:import-cest
  docker compose build mcp-fiscal
  docker compose up -d mcp-fiscal
  ```

- **Bateria de avaliação:** `docker compose exec app php artisan fiscal:battery` gera um relatório em `storage/app/relatorios/`. O gabarito fica em `tests/Fixtures/bateria_fiscal.json`.

- **Validação de XML:** NFSe e DPS do padrão nacional são validados no back-end contra os XSDs de `resources/schemas/nfse/{1.00,1.01}` (cópias dos schemas oficiais, via repositório `php-nfse-nacional`). O resultado é enviado ao modelo como fonte de verdade. A validação é estrutural: não confere assinatura digital, regras municipais nem situação cadastral.

- **Testes:** `php artisan test` no host, com PHP 8.4 e `composer install`. Os testes usam SQLite em memória e não precisam do container `db`.

## Solução de problemas

### "O assistente de IA está indisponível no momento."

O `app` não conseguiu falar com o Ollama ou com o MCP. Verifique, nesta ordem:

```bash
docker compose ps
docker compose exec ollama ollama list          # o modelo aparece?
docker compose logs --tail=100 app
docker compose logs --tail=100 ollama
docker compose exec app php -r 'echo file_get_contents("http://ollama:11434/api/tags");'
```

Causa comum: o modelo ainda não foi baixado (passo 4). A primeira resposta também demora mais porque o modelo é carregado na memória.

### Banco não sobe ou `SQLSTATE[08006]`

```bash
docker compose ps db
docker compose logs --tail=100 db
docker compose exec db pg_isready -U chatbot_fiscal -d chatbot_fiscal
docker compose exec app php artisan migrate:status
```

Se mudou `DB_USERNAME`/`DB_PASSWORD` depois da primeira subida, o volume `pgdata` mantém o usuário antigo. Ajuste com `ALTER ROLE` ou recrie o volume (perde os dados).

### Porta ocupada

```bash
ss -ltnp | grep -E '8100|8002'
```

No macOS/Windows, use `lsof -i :8100` ou o Gerenciador de Tarefas.

### O build do `mcp-fiscal` falha

Confira se o repositório irmão existe em `../mcp-fiscal-brasil` (passo 1).

### "Unable to locate file in Vite manifest"

Os assets não foram gerados. Reconstrua a imagem do app:

```bash
docker compose build app
docker compose up -d app
```

### Respostas lentas

Sem GPU, uma resposta longa leva dezenas de segundos. Há um bloco comentado no `docker-compose.yml` para habilitar GPU NVIDIA (requer NVIDIA Container Toolkit).

## Limitações conhecidas

- O `app` usa `php artisan serve`, adequado a uso local ou demonstração. Para produção, use PHP-FPM atrás de um proxy (Caddy ou Nginx).
- O modelo recebe a pergunta atual e, no máximo, a pergunta anterior do usuário (sem as respostas antigas), porque o E2B copiava respostas anteriores. Veja `FiscalChatOrchestrator`.
- O modelo não navega na internet: as consultas fiscais vêm do `mcp-fiscal`.
