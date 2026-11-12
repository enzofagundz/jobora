<?php

use App\Mcp\Tools\JobPostings\ReviewJobPostingTool;

it('publica revisão como escrita local idempotente sem efeitos externos', function () {
    $metadata = app(ReviewJobPostingTool::class)->toArray();

    expect($metadata['annotations']['openWorldHint'] ?? false)->toBeFalse()
        ->and($metadata['annotations']['idempotentHint'] ?? false)->toBeTrue();
});
