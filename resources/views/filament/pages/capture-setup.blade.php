<x-filament-panels::page>
    <div class="w-full space-y-4">

        <x-filament::section>
            <x-slot:heading>
                1. Instale a extensão
            </x-slot:heading>
            <p class="text-sm">
                Use a extensão Jobora compatível com o fluxo V2 e os domínios permitidos no seu navegador.
                A extensão registra execuções, resolve acessos e envia capturas duráveis pela API deste Jobora.
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>
                2. Sua credencial
            </x-slot:heading>

            @if ($this->hasPlainToken())
                <div class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Copie o token agora — ele só será exibido integralmente uma vez. Após sair ou recarregar a página, apenas a versão mascarada permanecerá.
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3"
                     wire:key="credential-{{ md5($this->fullToken() ?: 'empty') }}"
                     x-data="{ revealed: false }"
                >
                    <code class="min-w-0 flex-1 break-all rounded bg-gray-100 px-3 py-1.5 font-mono text-sm dark:bg-gray-800"
                          x-show="revealed"
                          x-cloak
                    >{{ $this->fullToken() }}</code>
                    <code class="min-w-0 flex-1 break-all rounded bg-gray-100 px-3 py-1.5 font-mono text-sm dark:bg-gray-800"
                          x-show="! revealed">{{ $this->maskedToken() }}</code>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            x-on:click="revealed = ! revealed"
                        >
                            <span x-text="revealed ? 'Ocultar' : 'Revelar'">Revelar</span>
                        </x-filament::button>
                        <x-filament::button
                            color="gray"
                            size="sm"
                            x-on:click="await navigator.clipboard.writeText({{ Js::from($this->fullToken()) }})"
                        >
                            Copiar token
                        </x-filament::button>
                    </div>
                </div>
            @elseif ($this->tokenConfigured())
                <p class="text-sm">
                    Sua credencial está configurada. Por segurança, o token completo só aparece no momento da geração.
                    Copie-o novamente regenerando a credencial — o token anterior será invalidado.
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded bg-gray-100 px-3 py-1.5 font-mono text-sm dark:bg-gray-800">{{ $this->maskedToken() }}</code>
                    <span class="text-xs text-gray-500">mascarado</span>
                </div>
            @else
                <p class="text-sm">
                    Você ainda não possui credencial para a extensão. Use o botão <strong>Gerar credencial</strong> no cabeçalho da página.
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    A credencial pertence à sua conta e identifica suas capturas. Não é compartilhada com outros usuários.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>
                3. Cole no popup da extensão
            </x-slot:heading>
            <p class="text-sm">
                Abra as configurações da extensão, informe <code>{{ url('/api') }}</code> como base da API
                e cole sua credencial individual. Não compartilhe esse token: ele identifica sua conta.
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>
                4. Valide e conecte
            </x-slot:heading>
            <p class="text-sm">
                Salve a configuração. A extensão valida a credencial e envia cada captura como um envelope
                vinculado à execução, com estado de ingestão, cobertura e prazo de retenção do HTML bruto.
                Consulte execuções em <strong>Execuções de descoberta</strong> e capturas em <strong>Capturas</strong>.
            </p>
        </x-filament::section>

    </div>
</x-filament-panels::page>
