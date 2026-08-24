<?php

namespace App\Jobs;

use App\Enums\JobCaptureTriageStatus;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TriageJobCaptures implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 130;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $captureIds
     */
    public function __construct(
        public array $captureIds,
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        $sorted = $this->captureIds;
        sort($sorted);

        return 'triage:'.$this->userId.':'.md5(implode(',', $sorted));
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(JobTriageService $service): void
    {
        $captures = JobCapture::query()->whereIn('id', $this->captureIds)->get();

        $service->triageBatch(
            $captures,
            User::find($this->userId),
        );

        $fresh = JobCapture::query()->whereIn('id', $this->captureIds)->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $completed = $fresh->where('triage_status', JobCaptureTriageStatus::Completed)->count();
        $failed = $fresh->where('triage_status', JobCaptureTriageStatus::Failed)->count();

        $recipient = User::find($this->userId);

        if (! $recipient) {
            return;
        }

        if ($failed === 0 && $completed > 0) {
            Notification::make()
                ->success()
                ->title('Triagem concluída')
                ->body("{$completed} captura(s) processada(s) com sucesso.")
                ->sendToDatabase($recipient, isEventDispatched: true);
        } elseif ($completed > 0 && $failed > 0) {
            Notification::make()
                ->warning()
                ->title('Triagem concluída com erros')
                ->body("{$completed} captura(s) processada(s); {$failed} falha(s).")
                ->sendToDatabase($recipient, isEventDispatched: true);
        } elseif ($completed === 0 && $failed > 0) {
            Notification::make()
                ->danger()
                ->title('Falha na triagem')
                ->body('Nenhuma captura foi processada com sucesso.')
                ->sendToDatabase($recipient, isEventDispatched: true);
        }
    }

    /**
     * Ensure captures stuck in processing are marked failed if the job
     * itself fails (e.g., timeout or unhandled exception).
     */
    public function failed(?Throwable $exception): void
    {
        JobCapture::query()
            ->whereIn('id', $this->captureIds)
            ->where('triage_status', JobCaptureTriageStatus::Processing)
            ->update([
                'triage_status' => JobCaptureTriageStatus::Failed,
                'triage_error' => $exception?->getMessage() ?? 'Job falhou (timeout)',
            ]);

        $recipient = User::find($this->userId);

        if ($recipient) {
            Notification::make()
                ->danger()
                ->title('Falha na triagem')
                ->body('Nenhuma captura foi processada com sucesso.')
                ->sendToDatabase($recipient, isEventDispatched: true);
        }
    }
}
