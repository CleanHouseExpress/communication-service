<?php

namespace App\Enums;

enum ConversationEventType: string
{
    case ConversationCreated = 'conversation_created';
    case MessageReceived = 'message_received';
    case MessageSent = 'message_sent';
    case AgentStarted = 'agent_started';
    case AgentFinished = 'agent_finished';
    case AgentSkipped = 'agent_skipped';
    case HandoffRequested = 'handoff_requested';
    case ConversationAssigned = 'conversation_assigned';
    case ConversationReturnedToAi = 'conversation_returned_to_ai';
    case ConversationClosed = 'conversation_closed';
    case ConversationReopened = 'conversation_reopened';
    case HumanMessageSent = 'human_message_sent';
}
