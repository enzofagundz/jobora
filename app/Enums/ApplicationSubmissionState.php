<?php

namespace App\Enums;

enum ApplicationSubmissionState: string
{
    case Reserved = 'reserved';
    case Sending = 'sending';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
