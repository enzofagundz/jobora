<?php

use App\Mcp\Tools\Matching\GetMatchingTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

it('advertises matching reads as read only', function () {
    expect((new ReflectionClass(GetMatchingTool::class))->getAttributes(IsReadOnly::class))->toHaveCount(1);
});
