<?php

namespace App\Services;

use App\Models\Resume;

class ResumeService
{
    /**
     * Save the current resume. A single row is guaranteed: saving always
     * updates the existing row (new submissions replace the previous text).
     */
    public function save(string $content): Resume
    {
        $resume = Resume::query()->first() ?? new Resume;

        $resume->content = $content;
        $resume->save();

        return $resume;
    }

    public function current(): ?Resume
    {
        return Resume::query()->first();
    }

    public function content(): ?string
    {
        return $this->current()?->content;
    }
}
