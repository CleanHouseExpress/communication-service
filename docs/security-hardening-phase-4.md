# Security Hardening - Phase 4

## Riscos Encontrados

- Webhook provider ainda estava permissivo em local/test sem chave explicita de bypass.
- Requests internos tinham poucos limites maximos de string.
- Payloads JSON podiam crescer sem limite defensivo claro.
- Clients HTTP aceitavam URLs de config sem validacao de esquema/host em producao.
- Mensagens inbound eram enviadas ao agente sem metadata explicita sobre prompt injection.
- Health precisava garantir que flags operacionais fossem expostas sem vazar segredos.
- Idempotencia abusiva de agent run manual ainda nao tem protecao propria.

## Riscos Mitigados

- Tokens internos seguem obrigatorios, com `hash_equals` e respostas 401/403 sem segredo.
- Webhook Z-API agora tem secret/header configuraveis e bypass local/test explicito.
- Requests ganharam limites de tamanho para ids, texto, contato e payloads.
- `provider` e `message_type` seguem restritos por enum.
- IDs internos sensiveis usam `uuid` e `exists` nos endpoints internos aplicaveis.
- Models revisados com `$fillable` explicito e casts JSON/datetime.
- Nao ha `whereRaw`, `selectRaw`, `orderBy` dinamico de usuario nem SQL por concatenacao no codigo auditado.
- Rate limits ficaram separados para health, APIs internas e webhooks provider.
- Logs estruturados registram IDs/status/erro curto, sem payload bruto completo, tokens ou headers Authorization.
- `AgentPromptGuard` limita texto enviado ao agente e marca metadata de prompt injection sem censurar a mensagem.
- `ConfiguredUrlGuard` valida URLs configuradas e exige HTTPS/bloqueia host local/privado em producao.
- Clients HTTP usam timeout configurado e `withoutRedirecting()`.
- Health nao retorna tokens, secrets ou URLs sensiveis.

## Riscos Aceitos Temporariamente

- Nao ha WAF, OAuth/JWT, mTLS ou gestao real de secrets.
- Nao ha rotacao de tokens.
- Nao ha criptografia por campo em repouso.
- Nao ha auditoria completa de seguranca.
- Nao ha fila/retry; chamadas externas ainda sao sincronas.
- O endpoint manual de agent run ainda nao e idempotente.
- Bloqueio SSRF nao resolve DNS de dominios para IP privado; ele bloqueia apenas localhost/IPs privados literais em producao.
- HMAC do webhook e generico; deve ser ajustado se a Z-API exigir esquema proprietario.

## Checklist OWASP API Security Basico

- API1 Broken Object Level Authorization: parcialmente mitigado por token interno; RBAC/tenant isolation ainda pendente.
- API2 Broken Authentication: token obrigatorio em rotas internas; sem OAuth/JWT ainda.
- API3 Broken Object Property Level Authorization: requests usam campos conhecidos; mass assignment com fillable explicito.
- API4 Unrestricted Resource Consumption: limites de tamanho, timeouts e rate limits basicos adicionados.
- API5 Broken Function Level Authorization: rotas internas protegidas por service token.
- API6 Unrestricted Access to Sensitive Business Flows: idempotencia inbound/outbound existe; agent run manual ainda precisa endurecer.
- API7 SSRF: URLs nao vem do usuario; validacao production para HTTPS e hosts privados literais.
- API8 Security Misconfiguration: `.env.example` e config documentam flags fake/real e assinatura provider.
- API9 Improper Inventory Management: contratos internos/provider documentados.
- API10 Unsafe Consumption of APIs: clients externos tratam falhas sem stack trace e sem quebrar inbound.

## Recomendacoes Proximas

1. Definir esquema oficial de assinatura Z-API e validar contra exemplos reais.
2. Mover outbound e agente para fila com retry/backoff seguro.
3. Implementar idempotencia para agent run manual.
4. Adicionar isolamento de tenant e autorizacao por escopo quando sair do service token unico.
5. Resolver DNS e bloquear ranges privados tambem para dominios em producao.
6. Centralizar sanitizacao de erros externos e classificar erros por codigo.
7. Adicionar secret manager/rotacao fora do `.env`.
8. Criar trilha de auditoria para operacoes internas sensiveis.
9. Revisar limites de payload com base nos limites reais da Z-API e do n8n.
10. Adicionar monitoramento de rate limit e anomalias de idempotency_key.
