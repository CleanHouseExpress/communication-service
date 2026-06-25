# Contrato de Integracao Realtime

## Responsabilidades

### communication-service

- persiste o estado operacional;
- cria eventos Laravel broadcastaveis;
- publica em canais privados via broadcaster;
- envia somente payload resumido e sanitizado;
- nao autentica usuarios.

### orchestra-api

- autentica usuarios;
- resolve tenant, RBAC/TBAC e permissoes;
- autoriza assinaturas de canais privados;
- nunca encaminha o service token ao navegador.

### frontend

- autentica somente contra a orchestra-api;
- solicita autorizacao de canal na orchestra-api;
- conecta ao Reverb via Echo;
- ao reconectar, consulta as APIs para ressincronizar o estado.

## Fluxo de Publicacao

```text
communication-service
  -> evento ShouldBroadcast/ShouldQueue
  -> fila communication-realtime
  -> Laravel broadcaster
  -> Reverb
  -> canais privados
```

O worker do communication-service deve consumir:

```bash
php artisan queue:work --queue=communication-realtime
```

## Fluxo de Autorizacao

```text
frontend
  -> login/session/token na orchestra-api
  -> POST /broadcasting/auth na orchestra-api
  -> orchestra-api valida usuario + tenant + permissao
  -> orchestra-api assina canal privado
  -> frontend conecta/assina no Reverb
```

O endpoint `/broadcasting/auth` e apenas uma sugestao compatível com Echo. Ele deve
existir na orchestra-api, nao no communication-service.

## Canais

```text
tenant.{tenantId}.communication
conversation.{conversationId}
```

Validacao recomendada na orchestra-api:

```php
Broadcast::channel('tenant.{tenantId}.communication', function ($user, string $tenantId) {
    return $user->canAccessTenant($tenantId)
        && $user->can('communication.inbox.view', $tenantId);
});

Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) {
    return $user->canViewCommunicationConversation($conversationId);
});
```

Esse codigo e ilustrativo. A implementacao deve usar os servicos reais de
autorizacao e tenancy da orchestra-api.

## Nomes dos Eventos

```text
conversation.created
conversation.updated
conversation.assigned
conversation.returned_to_ai
conversation.closed
conversation.reopened
conversation.handoff_requested
message.received
message.sent
message.status_updated
timeline.updated
```

Como os eventos usam `broadcastAs()`, o Echo deve escutar com prefixo `.`.

## Payload Esperado

```json
{
  "tenant_id": "tenant-1",
  "conversation_id": "conversation-uuid",
  "event": "conversation.assigned",
  "timestamp": "2026-06-25T22:00:00-03:00",
  "resource": {
    "id": "conversation-uuid",
    "status": "open",
    "service_mode": "human",
    "handoff_status": "assigned",
    "assigned_external_user_id": "user-123",
    "assigned_external_user_name": "Maria"
  }
}
```

O consumidor deve tolerar campos adicionais dentro de `resource`, mas nao deve
depender de payload bruto, headers ou respostas do provider.

## Variaveis do communication-service

```env
COMMUNICATION_REALTIME_ENABLED=false
COMMUNICATION_REALTIME_QUEUE=communication-realtime

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=8080
REVERB_SCHEME=http
```

`COMMUNICATION_REALTIME_ENABLED` deve continuar `false` ate Reverb, worker e
autorizacao da orchestra-api estarem operacionais.

## Exemplo de Broadcast Config

Configuracao equivalente no communication-service:

```php
'default' => env('BROADCAST_CONNECTION', 'null'),

'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
        ],
    ],
],
```

## Exemplo Echo

Exemplo futuro no frontend:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${ORCHESTRA_API_URL}/broadcasting/auth`,
  auth: {
    headers: {
      Authorization: `Bearer ${userAccessToken}`,
    },
  },
});

echo
  .private(`tenant.${tenantId}.communication`)
  .listen('.conversation.updated', (payload) => {
    refreshConversationInInbox(payload);
  });

echo
  .private(`conversation.${conversationId}`)
  .listen('.message.received', (payload) => {
    appendMessage(payload.resource);
  })
  .listen('.message.status_updated', (payload) => {
    updateMessageStatus(payload.resource);
  });
```

O exemplo nao faz parte do runtime atual e nao deve ser copiado com tokens fixos.

## Criterios Antes de Habilitar

1. Reverb implantado com TLS no ambiente.
2. Chaves compartilhadas configuradas como secrets.
3. Worker consumindo `communication-realtime`.
4. Auth de canais implementada na orchestra-api.
5. Teste de isolamento entre tenants.
6. Estrategia de ressincronizacao após desconexao.
