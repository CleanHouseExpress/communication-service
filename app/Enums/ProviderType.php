<?php

namespace App\Enums;

enum ProviderType: string
{
    case Zapi = 'zapi';
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
}
