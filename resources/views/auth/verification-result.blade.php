<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $verified ? 'Email verified' : 'Verification link unavailable' }} | Reclaim</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7f7; color: #17333b; font: 1rem/1.6 system-ui, sans-serif; }
        main { width: min(36rem, calc(100% - 2rem)); margin: 4rem auto; padding: clamp(1.25rem, 4vw, 2.5rem); background: white; border: 1px solid #d9e2e2; border-radius: 12px; overflow-wrap: anywhere; }
        .brand { font-size: 1.5rem; font-weight: 700; color: #087c7a; }
        h1 { font-size: clamp(1.5rem, 5vw, 2rem); line-height: 1.25; }
        nav { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: .65rem 1rem; border: 1px solid #087c7a; border-radius: 6px; color: #086765; text-decoration: none; font-weight: 600; }
        a:first-child { color: white; background: #087c7a; }
        a:hover { text-decoration: underline; }
        a:focus-visible { outline: 3px solid #17333b; outline-offset: 3px; }
        @media (max-width: 420px) { main { margin: 1rem auto; } nav a { width: 100%; } }
    </style>
</head>
<body>
<main>
    <div class="brand">Reclaim</div>
    @if ($verified)
        <h1>Your email is verified</h1>
        <p>You can now sign in to submit reports and claims.</p>
        <p>If you already have Reclaim open, return to that tab and choose Refresh status to continue where you left off.</p>
    @else
        <h1>This verification link is no longer valid</h1>
        <p>Sign in and request a new verification email. If your email is already verified, you can continue using your account.</p>
    @endif
    {{-- Links use deployment configuration only; signed URL parameters are never forwarded. --}}
    <nav aria-label="Continue in Reclaim">
        <a href="{{ rtrim(config('services.google.frontend_origin'), '/') }}/login">Sign in to Reclaim</a>
        <a href="{{ rtrim(config('services.google.frontend_origin'), '/') }}/">Browse found items</a>
    </nav>
</main>
</body>
</html>
