# Communication Gateway

Microservico API para envio, recebimento, roteamento e processamento de mensagens da Orchestra.

Este projeto nasce como uma aplicacao Laravel API-only. Ele nao possui login humano, sessoes web, Blade publico, autenticacao de usuarios ou frontend.

## Objetivo

Centralizar infraestrutura de comunicacao para WhatsApp, webhooks, status de entrega, midias, retries, rate limit, normalizacao de provedores, ponte com agentes/n8n e futuro provedor proprio.

## Requisitos

- PHP 8.4
- Composer
- SQLite para desenvolvimento local ou outro banco configurado via `.env`

## Instalacao

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure `COMMUNICATION_GATEWAY_SERVICE_TOKEN` com um segredo forte antes de chamar rotas internas.

## Rodar Localmente

```bash
php artisan serve
```

Endpoints iniciais:

- `GET /api/health`
- `GET /api/version`
- `GET /api/internal/health`

## Testes

```bash
php artisan config:clear
php artisan test
```

## Proximos Passos

- Analisar o modulo de comunicacao existente da Clin.
- Planejar extracao/adaptacao sem clonar codigo legado nesta fase.
- Implementar validacao real de assinatura de webhooks por provedor.
- Implementar integracao real com Z-API.
- Implementar ponte real com n8n/agentes.
