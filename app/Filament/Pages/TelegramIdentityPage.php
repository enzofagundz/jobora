<?php

namespace App\Filament\Pages;

use App\Enums\AgentIntegrationScope;
use App\Mcp\Support\ToolException;
use App\Models\AgentIntegration;
use App\Services\TelegramIdentityService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TelegramIdentityPage extends Page
{
    protected string $view = 'filament.pages.telegram-identity';

    protected static ?string $slug = 'telegram-identity';

    protected static ?string $navigationLabel = 'Vínculo Telegram';

    protected static ?string $title = 'Vínculo Telegram';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 11;

    public ?string $integrationId = null;

    public ?string $confirmationChallengeId = null;

    public string $confirmationPassword = '';

    public ?array $lastRevokedBinding = null;

    public function mount(TelegramIdentityService $identities): void
    {
        $this->integrationId = (string) ($this->availableIntegrations()->first()?->id ?? '');
        $this->refreshIdentityState($identities);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Integração autorizada')->schema([
                Select::make('integrationId')
                    ->label('Integração Hermes')
                    ->options($this->availableIntegrations()->mapWithKeys(fn (AgentIntegration $integration): array => [$integration->id => $integration->name])->all())
                    ->required()
                    ->helperText('Somente integrações ativas com approvals:write podem criar o vínculo.'),
                TextInput::make('confirmationPassword')
                    ->label('Senha da sua conta')
                    ->password()
                    ->autocomplete('current-password')
                    ->revealable()
                    ->helperText('Senha será validada no servidor e removida após cada tentativa.'),
            ])->columns(2),
        ])->statePath('');
    }

    public function availableIntegrations(): Collection
    {
        return AgentIntegration::query()->where('user_id', auth()->id())->get()
            ->filter(fn (AgentIntegration $integration): bool => $integration->can(AgentIntegrationScope::ApprovalsWrite))
            ->values();
    }

    public function pendingChallenges(TelegramIdentityService $identities): array
    {
        return $identities->pendingChallenges(auth()->user());
    }

    public function currentBinding(TelegramIdentityService $identities): ?array
    {
        return $identities->currentBinding(auth()->user());
    }

    public function integrationName(int $integrationId): string
    {
        return AgentIntegration::query()->where('user_id', auth()->id())->find($integrationId)?->name ?? 'Integração indisponível';
    }

    public function startChallenge(TelegramIdentityService $identities): void
    {
        $integration = AgentIntegration::query()->where('user_id', auth()->id())->find($this->integrationId);

        if ($integration === null || ! $integration->can(AgentIntegrationScope::ApprovalsWrite)) {
            Notification::make()->danger()->title('Integração indisponível')->body('Escolha uma integração própria, ativa e autorizada para approvals:write.')->send();

            return;
        }

        try {
            $challenge = $identities->startChallenge(auth()->user(), $integration->id);
            Notification::make()->warning()->persistent()->title('Desafio temporário criado')
                ->body('Envie este código em conversa privada com Hermes. Código: '.$challenge['challenge'].' · expira em '.$challenge['expires_at'])
                ->send();
        } catch (AuthorizationException|ModelNotFoundException) {
            Notification::make()->danger()->title('Não foi possível iniciar o vínculo')->body('A integração não pertence à sua conta ou perdeu autorização.')->send();
        }
    }

    public function confirmChallenge(TelegramIdentityService $identities): void
    {
        $challengeId = $this->confirmationChallengeId;
        $password = $this->confirmationPassword;

        try {
            if (blank($challengeId) || blank($password)) {
                Notification::make()->warning()->title('Informe desafio e senha')->send();

                return;
            }

            $binding = $identities->confirmChallenge(auth()->user(), $challengeId, $password);
            $this->lastRevokedBinding = null;
            Notification::make()->success()->title('Vínculo confirmado')
                ->body('Conta Telegram '.$binding['telegram_user_id'].' vinculada à integração '.$this->integrationName((int) $binding['integration_id']).'.')
                ->send();
            $this->confirmationChallengeId = null;
        } catch (ValidationException) {
            Notification::make()->danger()->title('Senha incorreta')->body('Vínculo não foi alterado. Confira a senha da sua conta.')->send();
        } catch (ToolException $exception) {
            Notification::make()->warning()->title('Desafio não pode ser confirmado')->body($exception->getMessage())->send();
        } catch (AuthorizationException|ModelNotFoundException) {
            Notification::make()->danger()->title('Desafio indisponível')->body('Desafio não pertence à sua conta ou integração perdeu autorização.')->send();
        } finally {
            $this->confirmationPassword = '';
        }

        $this->refreshIdentityState($identities);
    }

    public function revokeBinding(TelegramIdentityService $identities): void
    {
        $binding = $identities->currentBinding(auth()->user());

        if ($binding === null) {
            Notification::make()->warning()->title('Vínculo não está ativo')->send();

            return;
        }

        try {
            $this->lastRevokedBinding = $identities->revokeBinding(auth()->user(), (string) $binding['id']);
            Notification::make()->success()->title('Vínculo revogado')
                ->body('Aprovações antigas não serão reativadas. Novo vínculo exige outro desafio.')->send();
            $this->confirmationChallengeId = null;
            $this->refreshIdentityState($identities);
        } catch (AuthorizationException|ModelNotFoundException) {
            Notification::make()->danger()->title('Não foi possível revogar o vínculo')->send();
        }
    }

    public function refreshIdentityState(TelegramIdentityService $identities): void
    {
        $pending = $identities->pendingChallenges(auth()->user());
        if (! collect($pending)->contains(fn (array $challenge): bool => (string) $challenge['challenge_id'] === $this->confirmationChallengeId)) {
            $this->confirmationChallengeId = $pending[0]['challenge_id'] ?? null;
        }
    }
}
