<?php

namespace App\Enums;

enum DiscoveryAccessOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}
