# Security

## Autenticacao de Servico

Rotas internas usam token de servico por header:

- `X-Service-Token`
- `Authorization: Bearer <token>`

Tokens por query string nao sao aceitos. A comparacao usa `hash_equals`.

## Headers API

Todas as respostas API recebem:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- `Permissions-Policy: geolocation=(), microphone=(), camera=()`
- `Cache-Control: no-store`

## Logs

O canal `communication` escreve em `storage/logs/communication.log`.

Nao registre tokens, payloads brutos, dados pessoais completos ou mensagens sensiveis sem sanitizacao. Use `App\Services\Security\PayloadSanitizer` antes de enviar dados de comunicacao para logs.

## Webhooks

`VerifyProviderWebhookSignature` e um ponto de extensao. Nesta fase ele permite local/testing ou provider desabilitado; validacoes reais devem ser implementadas por provedor.
