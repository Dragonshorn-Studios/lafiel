<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('code') — Lafiel</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #F7F7F3; color: #17202B; font-family: ui-sans-serif, system-ui, sans-serif; }
        .panel { text-align: center; padding: 64px 48px; border: 1px solid #D7DDE3; border-radius: 10px; background: #FFFFFF; }
        .code { font-family: ui-monospace, monospace; font-size: 64px; font-weight: 600; color: #22314C; margin: 0; }
        .label { font-size: 15px; color: #52606E; margin-top: 12px; }
        a { color: #416B9B; text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="panel">
        <p class="code">@yield('code')</p>
        <p class="label">@yield('message')</p>
        <p class="label"><a href="/">{{ __('Back to the fleet ledger') }}</a></p>
    </div>
</body>
</html>
