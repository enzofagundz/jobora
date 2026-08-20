<?php

use App\Services\ResumeService;

it('converts legacy HTML resume to plain text', function () {
    $text = ResumeService::plainText('<h1>ENZO</h1><p>+55 14 | Lins, SP<br><a href="http://x">linkedin.com/in/x</a></p><ul><li>Back-end: PHP</li></ul>');

    expect($text)->toContain('ENZO')
        ->and($text)->toContain('linkedin.com/in/x')
        ->and($text)->toContain('Back-end: PHP')
        ->and($text)->not->toContain('<')
        ->and($text)->not->toContain('</');
});

it('is idempotent for plain text', function () {
    expect(ResumeService::plainText('Currículo com Python.'))->toBe('Currículo com Python.');
});
