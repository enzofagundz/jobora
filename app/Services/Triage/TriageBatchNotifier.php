<?php

namespace App\Services\Triage;

use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Aviso final único de um lote de triagem: encaminhadas, barradas pelo
 * pré-filtro e falhas. O tom acompanha o resultado do lote.
 */
final class TriageBatchNotifier
{
    public function send(User $user, int $forwarded, int $barred, int $failed): void
    {
        if ($forwarded + $barred + $failed === 0) {
            return;
        }

        $completed = max(0, $forwarded - $failed) + $barred;

        $body = "{$forwarded} captura(s) encaminhada(s) à IA, {$barred} barrada(s) pelo pré-filtro e {$failed} falha(s).";

        $notification = match (true) {
            $failed === 0 => Notification::make()->success()->title('Triagem concluída'),
            $completed > 0 => Notification::make()->warning()->title('Triagem concluída com erros'),
            default => Notification::make()->danger()->title('Falha na triagem'),
        };

        $notification
            ->body($body)
            ->actions($this->viewAction())
            ->sendToDatabase($user, isEventDispatched: true);
    }

    public function sendFailure(User $user): void
    {
        Notification::make()
            ->danger()
            ->title('Falha na triagem')
            ->body('Nenhuma captura foi processada com sucesso.')
            ->actions($this->viewAction())
            ->sendToDatabase($user, isEventDispatched: true);
    }

    /**
     * @return array<int, Action>
     */
    private function viewAction(): array
    {
        return [
            Action::make('view')
                ->label('Ver capturas')
                ->url(JobCaptureResource::getUrl('index'))
                ->markAsRead(),
        ];
    }
}
