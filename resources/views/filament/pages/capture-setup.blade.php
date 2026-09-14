<x-filament-panels::page>
    <div class="w-full space-y-4">

        <x-filament::section>
            <x-slot:heading>
                1. Instale a extensão
            </x-slot:heading>
            <p class="text-sm">
                Instale a extensão <strong>Jobora Job Capture</strong> no Chrome ou Firefox
                carregando o build de produção do repositório <code>vagas-extension</code>.
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
                Abra o popup da extensão, confirme que a URL da API é
                <code>http://localhost:8000</code> (ou <code>http://127.0.0.1:8000</code>)
                e cole o token no campo indicado.
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>
                4. Valide e conecte
            </x-slot:heading>
            <p class="text-sm">
                Clique em <strong>Salvar</strong>: a extensão valida o token na API e mostra
                <em>Conectado</em> em caso de sucesso. Abra uma busca no LinkedIn ou Indeed
                para começar a capturar — cada publicação aparece na listagem de capturas.
            </p>
        </x-filament::section>

    </div>
</x-filament-panels::page>
