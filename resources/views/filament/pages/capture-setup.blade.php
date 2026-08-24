<x-filament-panels::page>
    <div class="space-y-4 max-w-2xl">

        @if (! $this->tokenConfigured())
            <x-filament::section>
                <x-slot:heading>
                    API não configurada
                </x-slot:heading>
                <x-slot:description>
                    A variável de ambiente <code>VAGAS_API_TOKEN</code> está vazia.
                    Defina-a no arquivo <code>.env</code> e limpe o cache de configuração
                    (<code>php artisan config:clear</code>) antes de conectar a extensão.
                </x-slot:description>
            </x-filament::section>
        @endif

        @if ($this->tokenConfigured())
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
                    2. Copie o token
                </x-slot:heading>
                <p class="text-sm">
                    Use este token no popup da extensão. Ele aparece mascarado;
                    revele para conferir antes de colar.
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-3"
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
        @endif

    </div>
</x-filament-panels::page>
