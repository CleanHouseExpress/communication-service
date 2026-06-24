# Architecture

Communication Gateway e um microservico Laravel API-only para infraestrutura de comunicacao da Orchestra.

## Responsabilidades

- Normalizar provedores de mensagem.
- Receber webhooks de provedores.
- Enviar mensagens por contratos internos.
- Processar status de entrega.
- Preparar ponte com agentes e n8n.
- Isolar retries, rate limit e logs de comunicacao.

## Fora do Escopo Inicial

- Login de usuarios.
- Frontend.
- Blade publico.
- Sessao web.
- Integracao real com Z-API.
- Copia de codigo legado.

## Camadas Preparadas

- `app/Contracts`: contratos para provedores e agentes.
- `app/DTO`: objetos simples de transporte.
- `app/Services/Providers`: implementacoes de provedores.
- `app/Services/Agents`: ponte para agentes.
- `app/Services/Security`: sanitizacao e seguranca operacional.
- `app/Support`: utilitarios futuros de logging e normalizacao.

## Rate Limits

- `internal-api`: 120 requisicoes por minuto.
- `provider-webhooks`: 300 requisicoes por minuto.
- `agent-callbacks`: 120 requisicoes por minuto.
