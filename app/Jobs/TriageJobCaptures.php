<?php

namespace App\Jobs;

use App\Enums\JobCaptureTriageStatus;
use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
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

    public function uniqueVia(): CacheRepository
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        try {
            return Cache::store($store);
        } catch (Throwable) {
            return Cache::store('file');
        }
    }

    public function handle(JobTriageService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $captures = $user->jobCaptures()->whereIn('id', $this->captureIds)->get();

        if ($captures->isEmpty()) {
            return;
        }

        $service->triageBatch(
            $captures,
            $user,
        );

        $fresh = $user->jobCaptures()->whereIn('id', $this->captureIds)->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $completed = $fresh->where('triage_status', JobCaptureTriageStatus::Completed)->count();
        $failed = $fresh->where('triage_status', JobCaptureTriageStatus::Failed)->count();

        $triageUrl = JobCaptureResource::getUrl('index');
        $triageAction = [Action::make('view')->label('Ver capturas')->url($triageUrl)->markAsRead()];

        if ($failed === 0 && $completed > 0) {
            Notification::make()
                ->success()
                ->title('Triagem concluída')
                ->body("{$completed} captura(s) processada(s) com sucesso.")
                ->actions($triageAction)
                ->sendToDatabase($user, isEventDispatched: true);
        } elseif ($completed > 0 && $failed > 0) {
            Notification::make()
                ->warning()
                ->title('Triagem concluída com erros')
                ->body("{$completed} captura(s) processada(s); {$failed} falha(s).")
                ->actions($triageAction)
                ->sendToDatabase($user, isEventDispatched: true);
        } elseif ($completed === 0 && $failed > 0) {
            Notification::make()
                ->danger()
                ->title('Falha na triagem')
                ->body('Nenhuma captura foi processada com sucesso.')
                ->actions($triageAction)
                ->sendToDatabase($user, isEventDispatched: true);
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
            ->where('user_id', $this->userId)
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
                ->actions([
                    Action::make('view')->label('Ver capturas')->url(JobCaptureResource::getUrl('index'))->markAsRead(),
                ])
                ->sendToDatabase($recipient, isEventDispatched: true);
        }
    }
}
