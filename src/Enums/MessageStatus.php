<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Received = 'received';
    case Failed = 'failed';
    case Undelivered = 'undelivered';
    case Ambiguous = 'ambiguous'; // provider id missing after polling attempts
}
