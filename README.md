<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## Chatbot Fiscal

Assistente fiscal em PT-BR: o Gemma 4 E2B (Ollama) decide qual ferramenta consultar no `mcp-fiscal-brasil` (CNPJ, NCM, CFOP, CST, CEST, ICMS e Reforma Tributária) e redige a resposta.

### Serviços

| Serviço | Porta (host) | Função |
|---|---|---|
| `app` (Laravel) | `127.0.0.1:8100` | Interface, histórico, orquestração |
| `mcp-fiscal` | `127.0.0.1:8002` | Ferramentas fiscais (MCP streamable-http) |
| `ollama` | `127.0.0.1:11434` | LLM `gemma4:e2b` |
| PostgreSQL | `127.0.0.1:5432` (host) | Conversas e mensagens |

O container `app` usa `network_mode: host` porque o PostgreSQL do host escuta só em `localhost`.

### Subir

```bash
docker compose up -d --build
docker compose exec ollama ollama pull gemma4:e2b   # primeira vez
```

### Manutenção

- **Retenção LGPD:** `fiscal:prune-conversations --days=30` roda diariamente às 03:00 pelo agendador do Laravel. O agendador precisa de um disparo externo, por exemplo no crontab do host: `* * * * * cd /var/www/chatbot-fiscal && docker compose exec -T app php artisan schedule:run`.
- **Tabela CEST completa:** veja `storage/app/dados/README.md` (download no CONFAZ e importação com `fiscal:import-cest`). Depois de importar, reconstrua a imagem do `mcp-fiscal` para que ela use a base nova.
- **Bateria de avaliação:** `docker compose exec app php artisan fiscal:battery` gera um relatório em `storage/app/relatorios/`. O gabarito fica em `tests/Fixtures/bateria_fiscal.json`.
- **Validação de XML:** NFSe e DPS do padrão nacional são validados no back-end contra os XSDs de `resources/schemas/nfse/{1.00,1.01}` (cópias dos schemas oficiais, via repositório `php-nfse-nacional`). O resultado é enviado ao modelo como fonte de verdade. A validação é estrutural: não confere assinatura digital, regras municipais nem situação cadastral.
- **Testes:** `php artisan test`.

### Limitações conhecidas

- O `app` usa `php artisan serve`, adequado a uso local ou de demonstração. Para produção, use PHP-FPM atrás de um proxy (Caddy ou Nginx).
- Sem GPU, respostas longas levam dezenas de segundos. Em `docker-compose.yml` há o bloco comentado para GPU NVIDIA.
- O modelo recebe a pergunta atual e, no máximo, a pergunta anterior do usuário (sem as respostas antigas), porque o E2B copiava respostas anteriores. Veja `FiscalChatOrchestrator`.
