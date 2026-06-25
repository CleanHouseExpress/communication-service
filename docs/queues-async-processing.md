# Processamento Assincrono com Filas

## Fluxo atual

Por padrao, o communication-service continua processando de forma sincrona:

- mensagens inbound text podem acionar o agente logo apos a persistencia;
- mensagens outbound sao criadas e enviadas para a Z-API no mesmo fluxo;
- falhas externas sao registradas sem apagar a mensagem operacional criada.

Esse comportamento preserva compatibilidade enquanto a operacao das filas e preparada.

## Fluxo assincrono

O processamento assincrono e habilitado separadamente para agente e outbound:

```env
COMMUNICATION_QUEUE_AGENT_ENABLED=false
COMMUNICATION_QUEUE_OUTBOUND_ENABLED=false
COMMUNICATION_QUEUE_AGENT_NAME=communication-agent
COMMUNICATION_QUEUE_OUTBOUND_NAME=communication-outbound
```

Quando a fila do agente esta habilitada, o inbound persiste a mensagem e despacha
`DispatchAgentForMessageJob`. O webhook nao aguarda a chamada ao n8n.

Quando a fila outbound esta habilitada, o servico cria a mensagem e o registro
outbound com status `pending`, depois despacha `SendOutboundMessageJob`. O envio
para a Z-API acontece somente durante o processamento do job.

## Filas planejadas

- `communication-agent`: execucao do agente n8n/IA para mensagens inbound.
- `communication-outbound`: envio de mensagens pendentes para o provider.

Os nomes podem ser alterados por ambiente. Workers devem consumir explicitamente
as filas habilitadas na instalacao.

Exemplo:

```bash
php artisan queue:work --queue=communication-agent,communication-outbound
```

## Compatibilidade e idempotencia

As flags ficam desligadas por padrao. Assim, ambientes sem worker continuam usando
o comportamento sincrono existente.

O job outbound recebe o identificador de um registro ja persistido e chama apenas
o envio pendente. Ele nao recria a mensagem outbound, evitando recursao e mantendo
a idempotencia do fluxo de criacao.

Os jobs recebem tambem o `tenant_id`, restauram o contexto tenant quando o runtime
multi-tenant esta habilitado e limpam esse contexto ao terminar.

## Timeline

Os eventos do agente continuam sendo registrados pela action de dispatch, tanto no
caminho sincrono quanto no job.

O evento `message_sent` e registrado somente quando o envio outbound efetivamente
termina com sucesso. A simples criacao de um outbound `pending` nao registra envio.

## Operacao segura

- Tokens, senhas e payloads completos nao devem aparecer nos logs dos jobs.
- Jobs ausentes ou registros removidos sao tratados com log resumido e encerramento.
- Falhas do provider ou agente atualizam os estados operacionais existentes.
- O driver de fila e os workers devem ser configurados antes de habilitar as flags.

## Limitacoes atuais

- Nao ha retry ou backoff customizado.
- Nao ha dead-letter queue.
- Nao ha Horizon ou metricas avancadas.
- Nao ha deduplicacao adicional no momento do consumo do job.
- Nao ha monitoramento automatico de jobs presos ou filas sem worker.
- A configuracao continua permitindo processamento sincrono, inclusive em producao.

## Proximos passos

1. Configurar um backend de fila persistente por ambiente.
2. Publicar workers separados ou priorizados para agente e outbound.
3. Definir tentativas, backoff e tratamento de jobs definitivamente falhos.
4. Adicionar metricas de latencia, profundidade da fila e taxa de falhas.
5. Avaliar Horizon quando Redis fizer parte da infraestrutura oficial.
