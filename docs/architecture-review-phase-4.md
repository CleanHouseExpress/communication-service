# Architecture Review - Phase 4

## Visao Atual

O `communication-gateway` nasceu como gateway tecnico para WhatsApp/Z-API, mas apos as tres primeiras fases ele ja concentra mais responsabilidades:

- recebe webhooks inbound de provider;
- normaliza payloads defensivamente;
- persiste raw events, contatos, conversas e mensagens;
- envia outbound minimo via Z-API;
- registra tentativas de outbound;
- dispara opcionalmente um agente n8n/IA;
- cria resposta outbound automatica quando o agente solicita.

O servico ainda esta adequado para evolucao local e validacao arquitetural, mas seu nome atual ja nao descreve todo o comportamento implementado.

## Responsabilidades Atuais

Responsabilidades tecnicas ja implementadas:

- adaptador Z-API inbound;
- idempotencia tecnica de webhook por evento/mensagem;
- normalizacao de mensagens inbound;
- estado local minimo de canal, contato, conversa e mensagem;
- endpoint interno inbound normalizado;
- endpoint interno outbound;
- client Z-API fake/real;
- registro de outbound tecnico;
- client n8n fake/real;
- registro de execucoes de agente;
- health check interno autenticado;
- logs estruturados basicos sem payload bruto completo.

## Limites Recomendados

### Gateway Tecnico

Deve conter:

- provider adapters;
- webhooks;
- verificacao de assinatura/token de provider;
- raw events;
- normalizacao;
- idempotencia tecnica;
- envio provider;
- timeouts, retries e rate limits tecnicos quando forem implementados;
- callbacks tecnicos de delivery/read.

### Communication-Service

Deve conter:

- modelo canonico de conversas;
- mensagens de negocio;
- participantes, departamentos e filas humanas;
- regras de atendimento;
- handoff humano;
- memoria curta de conversa para agente;
- politicas de automacao;
- auditoria e metricas de atendimento.

### Orchestra-API

Deve conter:

- tenant e autorizacao de negocio;
- CRM e entidades clinicas/comerciais;
- jornada do paciente/cliente;
- orquestracao entre modulos;
- dashboards e relatorios de negocio;
- integracoes internas.

## Nome do Servico

O nome `communication-gateway` ainda e aceitavel durante a fase inicial porque o foco operacional segue sendo provider/Z-API. Porem, o codigo ja contem responsabilidades de `communication-service`: conversa, mensagem canonica, outbound local e agente.

Recomendacao:

- manter o nome atual por enquanto para evitar churn prematuro;
- registrar explicitamente que o servico ja atua como gateway + service minimo;
- renomear futuramente para `communication-service` se conversas, agente, handoff e inbox continuarem vivendo aqui;
- manter `gateway` apenas se a camada de conversa/agente for extraida para outro servico.

Nao renomear o projeto nesta fase.

## Riscos Arquiteturais Atuais

- O inbound chama o agente de forma sincrona apos persistir a mensagem.
- O outbound chama o provider de forma sincrona.
- O mesmo servico mistura adapter tecnico e estado canonico de conversa.
- Ainda nao ha retry/backoff nem fila para chamadas externas.
- `communication_agent_runs` nao tem idempotencia propria no endpoint manual.
- Health check valida configuracao basica, mas nao prova conectividade real com Z-API ou n8n.
- Payloads de provider e agente podem crescer sem contrato versionado.
- Handoff humano esta apenas registrado como intencao, sem estado de atendimento.

## Decisoes Recomendadas

- Manter o modo fake como default em local/test.
- Exigir token de servico em todos os endpoints internos.
- Exigir assinatura/token de provider em producao quando Z-API estiver habilitado.
- Nao logar payload bruto completo por padrao.
- Preservar idempotencia inbound/outbound antes de adicionar filas.
- Tratar agente como efeito opcional: falhas nao devem quebrar inbound.
- Versionar contratos internos antes da primeira integracao real com Orchestra.
- Promover agent/outbound para queue antes de producao com trafego real.

## Proximos Passos Em Ordem

1. Congelar contratos HTTP internos e provider em versao inicial.
2. Adicionar jobs para outbound e agent dispatch.
3. Implementar retry/backoff com status tecnico claro.
4. Criar callback de delivery/read do provider.
5. Definir se este repositorio sera `communication-service` ou se tera extracao de gateway puro.
6. Modelar handoff humano e ownership de conversa.
7. Adicionar historico curto no payload do agente.
8. Criar metricas e dashboards operacionais.
9. Validar assinatura real Z-API conforme configuracao final do provider.
10. Integrar com Orchestra via contrato interno autenticado.
