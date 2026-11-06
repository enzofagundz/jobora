<x-filament-panels::page>
    @php
        $binding = $this->currentBinding(app(\App\Services\TelegramIdentityService::class));
        $pendingChallenges = $this->pendingChallenges(app(\App\Services\TelegramIdentityService::class));
        $integrations = $this->availableIntegrations();
    @endphp

    <div class="space-y-6">
        <x-filament::section icon="heroicon-o-shield-check" heading="Confiança delegada ao Hermes">
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                <p>Hermes atesta que recebeu sua interação humana em conversa privada. Desafio e confirmação no painel não comprovam consentimento contra um Hermes comprometido.</p>
                <p class="font-medium">Este vínculo não aprova candidatura nem autoriza envio por si só.</p>
                <p>{{ \App\Services\TelegramIdentityService::TRUST_STATEMENT }}</p>
                @if ($binding)
                    <p>{{ $binding['trust_statement'] }}</p>
                @endif
            </div>
        </x-filament::section>

        @if ($binding)
            <x-filament::section icon="heroicon-o-link" heading="Vínculo Telegram atual">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Estado</p><p class="mt-1 font-semibold">{{ $binding['status'] === 'active' ? 'Ativo' : 'Sem autorização' }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Integração</p><p class="mt-1">{{ $this->integrationName((int) $binding['integration_id']) }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">ID Telegram</p><p class="mt-1 font-mono">{{ $binding['telegram_user_id'] }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Chat privado</p><p class="mt-1 font-mono">{{ $binding['chat_id'] }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Mensagem de vínculo</p><p class="mt-1 font-mono">{{ $binding['message_id'] }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Confirmado em</p><p class="mt-1">{{ $binding['confirmed_at'] }}</p></div>
                    </div>
                    @if ($binding['revoked_at'])
                        <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-200">Vínculo revogado em {{ $binding['revoked_at'] }}.</p>
                    @else
                        <x-filament::button color="danger" wire:click="revokeBinding" wire:confirm="Revogar vínculo Telegram? Aprovações antigas não serão reativadas.">Revogar vínculo</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @else
            <x-filament::section icon="heroicon-o-link" heading="Criar vínculo">
                <div class="space-y-4">
                    @if ($lastRevokedBinding)
                        <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">Vínculo {{ $lastRevokedBinding['id'] }} revogado em {{ $lastRevokedBinding['revoked_at'] }}. Aprovações antigas não serão reativadas.</p>
                    @endif
                    @if ($integrations->isEmpty())
                        <p class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-200">
                            Nenhuma integração ativa tem permissão approvals:write. Revise suas integrações antes de continuar.
                        </p>
                    @else
                        {{ $this->form }}
                        <ol class="list-decimal space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-300">
                            <li>Inicie desafio. Código aparece uma vez nesta confirmação.</li>
                            <li>Envie código a Hermes em conversa privada no Telegram.</li>
                            <li>Atualize estado depois que Hermes registrar a mensagem.</li>
                            <li>Confira ID e chat retornados. Confirme com senha real da sua conta.</li>
                        </ol>
                        <x-filament::button wire:click="startChallenge" icon="heroicon-o-key">Iniciar desafio</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if ($pendingChallenges)
            <x-filament::section icon="heroicon-o-clock" heading="Desafios apresentados pelo Hermes">
                <div class="space-y-5">
                    @foreach ($pendingChallenges as $challenge)
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="space-y-2 text-sm">
                                    <p><span class="font-medium">Integração:</span> {{ $this->integrationName((int) $challenge['integration_id']) }}</p>
                                    <p><span class="font-medium">ID Telegram:</span> <span class="font-mono">{{ $challenge['telegram_user_id'] }}</span></p>
                                    <p><span class="font-medium">Chat privado:</span> <span class="font-mono">{{ $challenge['chat_id'] }}</span></p>
                                    <p><span class="font-medium">Mensagem:</span> <span class="font-mono">{{ $challenge['message_id'] }}</span></p>
                                    <p><span class="font-medium">Expira:</span> {{ $challenge['expires_at'] }}</p>
                                </div>
                                <x-filament::badge color="warning">Aguardando confirmação humana</x-filament::badge>
                            </div>
                            @if ($loop->first)
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="confirmationChallengeId" aria-label="Desafio a confirmar">
                                            @foreach ($pendingChallenges as $option)
                                                <option value="{{ $option['challenge_id'] }}">{{ $this->integrationName((int) $option['integration_id']) }} · {{ $option['telegram_user_id'] }} · expira {{ $option['expires_at'] }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="password" wire:model="confirmationPassword" autocomplete="current-password" placeholder="Senha da sua conta" aria-label="Senha da sua conta" />
                                    </x-filament::input.wrapper>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-3">
                                    <x-filament::button wire:click="confirmChallenge" icon="heroicon-o-lock-closed">Confirmar identidade com senha</x-filament::button>
                                    <x-filament::button color="gray" wire:click="refreshIdentityState">Atualizar desafios</x-filament::button>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </x-filament::section>
        @elseif (! $binding)
            <x-filament::section icon="heroicon-o-clock" heading="Confirmação pendente">
                <p class="text-sm text-gray-600 dark:text-gray-300">Desafios expiram em 10 minutos. Envie o código em conversa privada, depois atualize esta página. Código não reaparece após recarga.</p>
                <x-filament::button class="mt-3" color="gray" wire:click="refreshIdentityState">Atualizar desafios</x-filament::button>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
