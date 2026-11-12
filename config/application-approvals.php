<?php

return [
    'ttl_hours' => (int) env('APPLICATION_APPROVAL_TTL_HOURS', 24),
    'challenge_ttl_minutes' => (int) env('TELEGRAM_LINK_CHALLENGE_TTL_MINUTES', 10),
];
