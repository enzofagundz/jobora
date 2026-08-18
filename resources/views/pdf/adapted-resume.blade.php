<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Currículo adaptado — {{ $posting->role ?? 'Vaga '.$posting->id }}</title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #000;
            background: #fff;
            margin: 0;
        }
        .header {
            margin-bottom: 16pt;
        }
        .header h1 {
            font-size: 16pt;
            margin: 0 0 2pt;
        }
        .header p {
            font-size: 10pt;
            margin: 0;
            color: #333;
        }
        .resume {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Currículo</h1>
        <p>Versão adequada para a vaga: {{ $posting->role ?? 'Vaga '.$posting->id }}{{ $posting->company ? ' — '.$posting->company : '' }}</p>
    </div>
    <div class="resume">{{ $adaptation->adapted_content }}</div>
</body>
</html>
