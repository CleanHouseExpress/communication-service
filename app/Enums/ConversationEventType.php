<?php

namespace App\Enums;

enum ConversationEventType: string
{
    case ConversationCreated = 'conversation_created';
    case MessageReceived = 'message_received';
    case MessageSent = 'message_sent';
    case MessageDelivered = 'message_delivered';
    case MessageRead = 'message_read';
    case MessageFailed = 'message_failed';
    case AgentStarted = 'agent_started';
    case AgentFinished = 'agent_finished';
    case AgentSkipped = 'agent_skipped';
    case AgentFailed = 'agent_failed';
    case OutboundFailed = 'outbound_failed';
    case JobFailed = 'job_failed';
    case HandoffRequested = 'handoff_requested';
    case ConversationAssigned = 'conversation_assigned';
    case ConversationReturnedToAi = 'conversation_returned_to_ai';
    case ConversationClosed = 'conversation_closed';
    case ConversationReopened = 'conversation_reopened';
    case HumanMessageSent = 'human_message_sent';
}
