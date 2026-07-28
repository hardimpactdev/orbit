<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @unless ($failed)
        <meta http-equiv="refresh" content="2;url={{ $refreshUri }}">
    @endunless
    <title>Waking {{ $name }}</title>
    <style>
        :root {
            color-scheme: light;
            --paper: #f6f5f0;
            --ink: #1d211d;
            --muted: #747a72;
            --line: #dcded7;
            --ready: #247451;
            --active: #ad7227;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 2rem;
            background: var(--paper);
            color: var(--ink);
            font-family: Charter, "Bitstream Charter", "Sitka Text", Cambria, serif;
        }

        main { width: min(100%, 28rem); }

        .mark {
            width: 2rem;
            height: 2rem;
            margin-bottom: 1.5rem;
            border: 2px solid var(--line);
            border-top-color: var(--active);
            border-radius: 50%;
            animation: orbit 1s linear infinite;
        }

        .failed .mark {
            border-color: #a9433b;
            animation: none;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.65rem, 5vw, 2.25rem);
            font-weight: 500;
            letter-spacing: -.035em;
        }

        p {
            margin: .65rem 0 2rem;
            color: var(--muted);
            line-height: 1.55;
        }

        ol {
            margin: 0;
            padding: 0;
            list-style: none;
            border-top: 1px solid var(--line);
        }

        li {
            display: flex;
            align-items: center;
            gap: .8rem;
            padding: .85rem 0;
            border-bottom: 1px solid var(--line);
            font-size: .98rem;
        }

        .state {
            width: .55rem;
            height: .55rem;
            flex: none;
            border: 1px solid var(--muted);
            border-radius: 50%;
        }

        li[data-status="active"] .state {
            border-color: var(--active);
            background: var(--active);
            box-shadow: 0 0 0 .22rem color-mix(in srgb, var(--active) 16%, transparent);
        }

        li[data-status="done"] .state {
            border-color: var(--ready);
            background: var(--ready);
        }

        li[data-status="failed"] .state {
            border-color: #a9433b;
            background: #a9433b;
        }

        a {
            display: inline-block;
            margin-top: 1.5rem;
            color: var(--ink);
            text-underline-offset: .2rem;
        }

        @keyframes orbit {
            to { transform: rotate(360deg); }
        }

        @media (prefers-reduced-motion: reduce) {
            .mark { animation: none; }
        }
    </style>
</head>
<body>
    <main @class(['failed' => $failed])>
        <div class="mark" aria-hidden="true"></div>
        <h1>{{ $failed ? 'Wake-up paused' : "Waking {$name}" }}</h1>
        <p>
            {{ $failed
                ? 'One of the preparation steps did not complete.'
                : 'Orbit is preparing this development environment. This page will continue automatically.' }}
        </p>

        <ol aria-label="Wake-up progress">
            @foreach ($steps as $step)
                <li data-status="{{ $step['status'] }}">
                    <span class="state" aria-hidden="true"></span>
                    <span>{{ $step['label'] }}</span>
                </li>
            @endforeach
        </ol>

        @if ($failed)
            <a href="{{ $retryUri }}">Try again</a>
        @endif
    </main>
</body>
</html>
