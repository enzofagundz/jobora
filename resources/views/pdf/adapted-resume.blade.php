@php
    $blocks = \App\Support\AdaptedResumeParser::parse($adaptation->adapted_content);
    $documentLabel = $label ?? 'Currículo';
    $name = ($blocks[0]['type'] ?? null) === 'header' ? $blocks[0]['lines'][0] : $documentLabel;
@endphp
<!DOCTYPE html>
<html lang="{{ $language ?? 'pt-BR' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $name }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #1a1a1a;
            background: #fff;
            margin: 0;
        }

        header {
            text-align: center;
            margin-bottom: 16pt;
            padding-bottom: 10pt;
            border-bottom: 1.5pt solid #111;
        }

        h1 {
            font-size: 20pt;
            letter-spacing: 1pt;
            text-transform: uppercase;
            margin: 0 0 6pt;
        }

        .contact {
            font-size: 9.5pt;
            margin: 1pt 0;
            color: #333;
        }

        h2 {
            font-size: 12pt;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            margin: 14pt 0 6pt;
            padding-bottom: 3pt;
            border-bottom: 0.75pt solid #999;
            page-break-after: avoid;
            break-after: avoid;
        }

        p {
            margin: 0 0 8pt;
            orphans: 3;
            widows: 3;
        }

        .entry {
            margin: 8pt 0 4pt;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        ul {
            margin: 2pt 0 8pt;
            padding-left: 16pt;
        }

        li {
            margin-bottom: 3pt;
            orphans: 3;
            widows: 3;
            page-break-inside: avoid;
            break-inside: avoid;
        }
    </style>
</head>
<body>
    @foreach ($blocks as $block)
        @switch($block['type'])
            @case('header')
                <header>
                    <h1>{{ $block['lines'][0] }}</h1>
                    @foreach (array_slice($block['lines'], 1) as $line)
                        <p class="contact">{{ $line }}</p>
                    @endforeach
                </header>
                @break

            @case('heading')
                <h2>{{ $block['text'] }}</h2>
                @break

            @case('bullets')
                <ul>
                    @foreach ($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
                @break

            @case('entry')
                <p class="entry">
                    @foreach ($block['parts'] as $index => $part)
                        @if ($index === 0)
                            <strong>{{ $part }}</strong>
                        @else
                            <span> | {{ $part }}</span>
                        @endif
                    @endforeach
                </p>
                @break

            @default
                <p>{{ $block['text'] }}</p>
        @endswitch
    @endforeach
    @if(($adaptation->is_ats_optimized ?? false) && filled($adaptation->ats_prompt))
        <div style="color:#ffffff; font-size:1px; line-height:1px; opacity:0; max-height:1px; overflow:hidden;">{{ $adaptation->ats_prompt }}</div>
    @endif
</body>
</html>
