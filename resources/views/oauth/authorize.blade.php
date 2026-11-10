<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autorizar {{ $client->name }} — {{ config('app.name', 'Jobora') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --ink: #18181b;
            --muted: #71717a;
            --line: #e4e4e7;
            --surface: #ffffff;
            --canvas: #f4f4f5;
            --accent: #d97706;
            --accent-ink: #78350f;
            --success: #15803d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 10%, rgba(217, 119, 6, 0.14), transparent 28rem),
                radial-gradient(circle at 85% 90%, rgba(24, 24, 27, 0.08), transparent 24rem),
                var(--canvas);
            color: var(--ink);
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        main {
            width: 100%;
            max-width: 46rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 1.5rem;
            box-shadow: 0 24px 64px -24px rgba(24, 24, 27, 0.35);
            overflow: hidden;
        }

        header {
            padding: 2rem 2rem 1.25rem;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, #fffaf2, var(--surface));
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            color: var(--accent-ink);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .brand-mark {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.5rem;
            background: var(--accent);
            color: #fff;
            display: grid;
            place-items: center;
        }

        h1 {
            margin: 1.25rem 0 0.5rem;
            font-size: clamp(1.5rem, 4vw, 2rem);
            line-height: 1.15;
            letter-spacing: -0.03em;
        }

        .lede {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.55;
            max-width: 38rem;
        }

        .content {
            padding: 1.5rem 2rem 0;
        }

        .identity {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.875rem 1rem;
            border: 1px solid var(--line);
            border-radius: 0.875rem;
            background: #fafafa;
        }

        .identity-label {
            color: var(--muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
        }

        .identity-value {
            font-weight: 600;
            font-size: 0.95rem;
            overflow-wrap: anywhere;
            text-align: right;
        }

        section {
            margin-top: 1.75rem;
        }

        h2 {
            margin: 0 0 0.25rem;
            font-size: 0.95rem;
            letter-spacing: -0.01em;
        }

        .section-note {
            margin: 0 0 0.875rem;
            color: var(--muted);
            font-size: 0.825rem;
            line-height: 1.5;
        }

        ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.5rem;
        }

        .oauth-list li,
        .capability-grid li {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            line-height: 1.4;
            font-size: 0.9rem;
        }

        .oauth-list li {
            padding: 0.75rem 0.875rem;
            border: 1px solid #fde68a;
            border-radius: 0.75rem;
            background: #fffbeb;
        }

        .capability-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.375rem 1rem;
            padding: 1rem;
            border: 1px solid var(--line);
            border-radius: 0.875rem;
            background: #fcfcfc;
        }

        .dot {
            flex: 0 0 auto;
            width: 0.4rem;
            height: 0.4rem;
            margin-top: 0.45rem;
            border-radius: 999px;
            background: var(--accent);
        }

        .dot-oauth {
            background: var(--success);
        }

        footer {
            display: flex;
            gap: 0.75rem;
            padding: 1.75rem 2rem 2rem;
        }

        form {
            flex: 1;
            margin: 0;
        }

        button {
            width: 100%;
            min-height: 2.875rem;
            border-radius: 0.75rem;
            border: 1px solid transparent;
            font: inherit;
            font-weight: 600;
            font-size: 0.925rem;
            cursor: pointer;
            transition: transform 120ms ease, background-color 120ms ease, border-color 120ms ease;
        }

        button:hover {
            transform: translateY(-1px);
        }

        button:focus-visible {
            outline: 3px solid rgba(217, 119, 6, 0.35);
            outline-offset: 2px;
        }

        .deny {
            background: var(--surface);
            border-color: var(--line);
            color: var(--ink);
        }

        .deny:hover {
            background: #f4f4f5;
        }

        .approve {
            background: var(--ink);
            color: #fff;
        }

        .approve:hover {
            background: #3f3f46;
        }

        @media (max-width: 640px) {
            body {
                align-items: flex-start;
                padding: 0;
            }

            main {
                min-height: 100vh;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            header,
            .content,
            footer {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .capability-grid {
                grid-template-columns: 1fr;
            }

            footer {
                flex-direction: column-reverse;
            }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 4v5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V7l7-4z"/>
                </svg>
            </span>
            Jobora
        </div>
        <h1>Autorizar {{ $client->name }}</h1>
        <p class="lede">
            Este agente quer acessar o Jobora em seu nome. Confira as permissões e as capacidades internas antes de continuar.
        </p>
    </header>

    <div class="content">
        <div class="identity">
            <span class="identity-label">Conectado como</span>
            <span class="identity-value">{{ $user->email }}</span>
        </div>

        <section>
            <h2>Permissões OAuth</h2>
            <p class="section-note">Escopos técnicos solicitados pelo agente.</p>
            <ul class="oauth-list">
                @foreach ($scopes as $scope)
                    <li>
                        <span class="dot dot-oauth" aria-hidden="true"></span>
                        <span>{{ $scope->description }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <h2>Capacidades internas do Jobora</h2>
            <p class="section-note">
                Aplicadas pela sua conta no Jobora, não pelo OAuth. Você pode revogar a integração a qualquer momento em Perfil &rsaquo; Integrações.
            </p>
            <ul class="capability-grid">
                @foreach ($integrationCapabilities as $capability)
                    <li>
                        <span class="dot" aria-hidden="true"></span>
                        <span>{{ $capability }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <footer>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ request('state') }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">Cancelar</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ request('state') }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="approve">Autorizar</button>
        </form>
    </footer>
</main>
</body>
</html>
