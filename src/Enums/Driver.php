<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Enums;

enum Driver: string
{
    case Twilio = 'twilio';
    case Telnyx = 'telnyx';
}
