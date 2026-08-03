<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @unless ($failed)
        <meta http-equiv="refresh" content="2;url={{ $refreshUri }}">
    @endunless
    <title>{{ $name }}</title>
    @php
        // Perspective timing: keyframe % from time density; degrees are uniform polar
        // angle (CSS rotate). CSS rotate is polar angle, so compensate with exact polar
        // ellipse arc-length metric (not the parametric-ellipse derivative alone).
        // timeDensity(θ) = polarMetric(θ) * depth(θ)
        //   D = rx²sin²θ + ry²cos²θ
        //   r = rx·ry / √D
        //   r′ = rx·ry·(rx²−ry²)·sinθ·cosθ / D^(3/2)
        //   polarMetric = hypot(r, r′)
        //   depth = exp(s · (−sin θ))  pure C∞ first harmonic — no tanh plateaus
        // Screen speed ∝ 1/depth: top slowest, bottom fastest, smooth throughout.
        // theta = −π/2 at top (CSS rotate 0°).
        $rx = 41.916;
        $ry = 19.809;
        $samples = 128;
        $strength = 0.25;
        $time = [0.0];
        $prevTheta = -M_PI / 2;

        $timeDensity = static function (float $theta) use ($rx, $ry, $strength): float {
            $sinT = sin($theta);
            $cosT = cos($theta);
            $D = ($rx * $rx) * ($sinT * $sinT) + ($ry * $ry) * ($cosT * $cosT);
            $sqrtD = sqrt($D);
            $r = ($rx * $ry) / $sqrtD;
            $rPrime = ($rx * $ry) * ($rx * $rx - $ry * $ry) * $sinT * $cosT / ($D * $sqrtD);
            $polarMetric = hypot($r, $rPrime);
            $depth = exp($strength * (-$sinT));

            return $polarMetric * $depth;
        };

        for ($k = 1; $k <= $samples; $k++) {
            $theta = -M_PI / 2 + 2 * M_PI * ($k / $samples);
            $dTheta = $theta - $prevTheta;
            $time[$k] = $time[$k - 1] + 0.5 * ($timeDensity($prevTheta) + $timeDensity($theta)) * $dTheta;
            $prevTheta = $theta;
        }

        $timeTotal = $time[$samples];
        $keyframes = [];

        for ($k = 0; $k <= $samples; $k++) {
            $pct = $k === 0 ? 0.0 : ($k === $samples ? 100.0 : 100.0 * ($time[$k] / $timeTotal));
            $deg = 360.0 * ($k / $samples);
            $keyframes[] = [
                'pct' => number_format($pct, 4, '.', ''),
                'deg' => number_format($deg, 4, '.', ''),
            ];
        }

        // Exact production mark as a CSS mask (white = visible).
        $markPath = 'M50 25C77.6143 25 100 36.1929 100 50C99.9996 63.8069 77.614 75 50 75C22.386 75 0.000366987 63.8069 0 50C0 36.1929 22.3858 25 50 25ZM49.7764 32.0107C32.7857 32.0108 15.7344 38.9923 15.7344 46.9102C15.7346 54.8279 28.3485 61.2461 49.5654 61.2461C70.7823 61.2461 83.3962 54.8279 83.3965 46.9102C83.3965 38.9923 66.7672 32.0107 49.7764 32.0107Z';
        $maskSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 25 100 50">'
            .'<path fill="white" d="'.$markPath.'"/></svg>';
        $maskDataUri = 'data:image/svg+xml,'.rawurlencode($maskSvg);

        // 36% trail BEHIND the head for +clockwise rotate: transparent ahead (0–64%),
        // fade transparent→white into the 100%/0° seam (opaque leading edge).
        $trailPct = 36;
        $trailStartPct = 100 - $trailPct;
    @endphp
    <style>
        :root {
            color-scheme: dark;
            --background: #000;
            --foreground: #fff;
            --track: #202020;
            --logo-w: 64px;
            --logo-h: 32px;
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
            position: relative;
            width: var(--logo-w);
            height: var(--logo-h);
            margin: 0 auto;
            isolation: isolate;
            contain: layout paint style;
        }

        .logo-track {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
        }

        .logo-clip {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            -webkit-mask-image: url("{{ $maskDataUri }}");
            mask-image: url("{{ $maskDataUri }}");
            -webkit-mask-size: 100% 100%;
            mask-size: 100% 100%;
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
            -webkit-mask-position: center;
            mask-position: center;
            pointer-events: none;
        }

        .logo-rotor {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 96px;
            height: 96px;
            margin: 0;
            transform: translate3d(-50%, -50%, 0) rotate(0deg);
            transform-origin: 50% 50%;
            will-change: transform;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            isolation: isolate;
            contain: layout style;
            background: conic-gradient(
                from 0deg at 50% 50%,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0) {{ $trailStartPct }}%,
                #fff 100%
            );
            animation: orbit-spin 0.325s linear infinite;
        }

        @keyframes orbit-spin {
@foreach ($keyframes as $frame)
            {{ $frame['pct'] }}% {
                transform: translate3d(-50%, -50%, 0) rotate({{ $frame['deg'] }}deg);
            }
@endforeach
        }

        .retry {
            display: block;
            margin-top: 28px;
            color: #a1a1aa;
            font-size: 13px;
            text-align: center;
            text-underline-offset: 3px;
        }
    </style>
</head>
<body>
    <main>
        <div class="logo" role="img" aria-label="Orbit">
            <svg class="logo-track" viewBox="0 25 100 50" fill="none" aria-hidden="true">
                <path
                    d="M50 25C77.6143 25 100 36.1929 100 50C99.9996 63.8069 77.614 75 50 75C22.386 75 0.000366987 63.8069 0 50C0 36.1929 22.3858 25 50 25ZM49.7764 32.0107C32.7857 32.0108 15.7344 38.9923 15.7344 46.9102C15.7346 54.8279 28.3485 61.2461 49.5654 61.2461C70.7823 61.2461 83.3962 54.8279 83.3965 46.9102C83.3965 38.9923 66.7672 32.0107 49.7764 32.0107Z"
                    fill="#202020"
                />
            </svg>
            <div class="logo-clip">
                <div class="logo-rotor"></div>
            </div>
        </div>

        @if ($failed)
            <a class="retry" href="{{ $retryUri }}">Try again</a>
        @endif
    </main>
</body>
</html>
