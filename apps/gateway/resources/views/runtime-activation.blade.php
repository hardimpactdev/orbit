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
            color-scheme: dark;
            --background: #000;
            --foreground: #fff;
            --track: #27272a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--background);
            color: var(--foreground);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        main {
            width: min(100%, 158px);
        }

        .logo {
            width: 64px;
            height: 32px;
            display: block;
            margin: 0 auto 48px;
        }

        .progress {
            height: 4px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--track);
        }

        .progress-value {
            height: 100%;
            border-radius: inherit;
            background: var(--foreground);
        }

        @media (prefers-reduced-motion: no-preference) {
            .progress-value {
                transition: width 700ms cubic-bezier(.22, 1, .36, 1);
            }
        }

        .retry {
            display: block;
            margin-top: 28px;
            color: #a1a1aa;
            font-size: 13px;
            text-align: center;
            text-underline-offset: 3px;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>
    <main>
        <svg class="logo" viewBox="0 25 100 50" fill="none" aria-label="Orbit">
            <path d="M50 25C77.6143 25 100 36.1929 100 50C99.9996 63.8069 77.614 75 50 75C22.386 75 0.000366987 63.8069 0 50C0 36.1929 22.3858 25 50 25ZM49.7764 32.0107C32.7857 32.0108 15.7344 38.9923 15.7344 46.9102C15.7346 54.8279 28.3485 61.2461 49.5654 61.2461C70.7823 61.2461 83.3962 54.8279 83.3965 46.9102C83.3965 38.9923 66.7672 32.0107 49.7764 32.0107Z" fill="currentColor"/>
        </svg>

        <div
            class="progress"
            role="progressbar"
            aria-label="Wake-up progress"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $progress }}"
        >
            <div class="progress-value" data-progress="{{ $progress }}" style="width: {{ $progress }}%"></div>
        </div>

        <div class="sr-only" aria-live="polite">
            <p>{{ $failed ? 'Wake-up paused' : "Waking {$name}" }}</p>
            <ol>
                @foreach ($steps as $step)
                    <li>{{ $step['label'] }}: {{ $step['status'] }}</li>
                @endforeach
            </ol>
        </div>

        @if ($failed)
            <a class="retry" href="{{ $retryUri }}">Try again</a>
        @endif
    </main>
    <script nonce="{{ $nonce }}">
        const progressElement = document.querySelector('.progress-value');
        const targetProgress = Number.parseFloat(progressElement.dataset.progress ?? '0');
        const storageKey = `orbit-runtime-progress:${window.location.pathname}`;
        let previousProgress = 0;

        try {
            previousProgress = Number.parseFloat(window.sessionStorage.getItem(storageKey) ?? '0');
            window.sessionStorage.setItem(storageKey, String(targetProgress));
        } catch {
            previousProgress = 0;
        }

        if (! Number.isFinite(previousProgress) || targetProgress < previousProgress) {
            previousProgress = 0;
        }

        progressElement.style.transition = 'none';
        progressElement.style.width = `${previousProgress}%`;
        progressElement.getBoundingClientRect();
        progressElement.style.removeProperty('transition');

        window.requestAnimationFrame(() => {
            progressElement.style.width = `${targetProgress}%`;
        });
    </script>
</body>
</html>
