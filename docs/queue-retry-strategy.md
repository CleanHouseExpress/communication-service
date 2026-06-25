# Estrategia de Retry das Filas

## Objetivo

Os jobs de agente e outbound usam tentativas limitadas, backoff progressivo e um
registro operacional de falha definitiva. O fluxo sincrono continua preservando o
comportamento anterior; a politica de retry se aplica aos workers de fila.

## Configuracao

```env
COMMUNICATION_QUEUE_MAX_TRIES=5
COMMUNICATION_QUEUE_BACKOFF=10,30,60,120,300
COMMUNICATION_QUEUE_FAILED_EVENT_ENABLED=true
```

`COMMUNICATION_QUEUE_MAX_TRIES` define o total maximo de execucoes do job.
`COMMUNICATION_QUEUE_BACKOFF` define os intervalos, em segundos, usados entre as
tentativas. Os jobs leem esses valores em runtime.

## Falhas retryable

Falhas retornadas pelo n8n ou pela Z-API continuam atualizando o estado operacional
do agent run ou outbound. No caminho assincrono, o job converte esse resultado em
excecao para que o worker aplique retry e backoff.

O caminho sincrono nao passa a lancar essas excecoes e continua retornando o status
`failed` como antes.

## Falha definitiva

Depois de esgotar as tentativas, o callback `failed()`:

- grava um registro em `communication_failed_jobs_metadata`;
- registra `job_failed` na timeline, quando a flag de evento esta habilitada;
- guarda apenas classe da excecao e mensagem resumida;
- nunca persiste stack trace na tabela operacional.

Falhas funcionais tambem geram `agent_failed` ou `outbound_failed`. Esses eventos
podem ocorrer antes da falha definitiva, pois cada tentativa atualiza o estado do
dominio.

## Dead letter operacional

A tabela `communication_failed_jobs_metadata` guarda:

- tenant, conversa e mensagem relacionados;
- nome do job;
- payload minimo necessario para reprocessamento;
- classe da excecao;
- numero de tentativas;
- datas de falha e resolucao;
- metadata sem stack trace.

Ela complementa a tabela `failed_jobs` nativa do Laravel. A tabela nativa continua
sob responsabilidade do driver de fila; a tabela de comunicacao oferece contexto
operacional seguro para timeline e suporte.

Existe uma migration landlord e uma migration tenant. Com tenant runtime ligado, a
dead letter acompanha a conexao tenant.

## Operacao manual

Listar falhas nao resolvidas:

```bash
php artisan communication:queue:retry-failed
```

Filtrar:

```bash
php artisan communication:queue:retry-failed --tenant=tenant-1 --job=SendOutboundMessageJob --list
php artisan communication:queue:retry-failed --conversation=UUID --list
```

Reprocessar de forma sincrona:

```bash
php artisan communication:queue:retry-failed --tenant=tenant-1 --retry
```

`--list` e `--retry` podem ser usados juntos. Sem `--retry`, o comando somente
lista. Um retry bem-sucedido preenche `resolved_at`; o historico nao e apagado.
Se falhar novamente, a mesma dead letter permanece aberta e recebe a falha resumida
mais recente.

Quando o tenant runtime estiver habilitado, `--tenant` e obrigatorio porque cada
tenant possui sua propria tabela.

## Limitacoes

- Nao ha Horizon ou dashboard.
- Nao ha dead-letter queue externa.
- Nao ha retry em massa distribuido; o comando manual processa sincronamente.
- Retentativas do agente podem criar novos agent runs.
- Eventos funcionais de falha podem se repetir entre tentativas.
- A limpeza da tabela `failed_jobs` nativa do Laravel continua sendo uma operacao
  separada.
