<!DOCTYPE html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Accedi a {{ config('app.name') }}</title>
        <style>
            :root { font-family: ui-sans-serif, system-ui, sans-serif; color: #1c1917; background: #f5f5f4; }
            * { box-sizing: border-box; }
            body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 1rem; }
            .card { width: min(100%, 26rem); border: 1px solid #e7e5e4; border-radius: 1rem; background: #fff; padding: 1.5rem; box-shadow: 0 16px 40px rgb(28 25 23 / .08); }
            h1 { margin: 0; font-size: 1.35rem; }
            p { color: #78716c; }
            form { display: grid; gap: 1rem; margin-top: 1.25rem; }
            label { display: grid; gap: .35rem; font-size: .82rem; font-weight: 650; }
            input[type="email"], input[type="password"] { width: 100%; border: 1px solid #d6d3d1; border-radius: .65rem; padding: .7rem; font: inherit; }
            .remember { display: flex; align-items: center; gap: .5rem; font-weight: 400; }
            button { border: 0; border-radius: .7rem; background: #1c1917; padding: .75rem 1rem; color: #fff; font: inherit; font-weight: 650; cursor: pointer; }
            .error { color: #b91c1c; font-size: .78rem; }
            .footer { margin-bottom: 0; font-size: .88rem; }
            a { color: #1c1917; font-weight: 650; }
        </style>
    </head>
    <body>
        <main class="card">
            <h1>{{ config('app.name') }}</h1>
            <p>Accedi per usare i tuoi assistenti.</p>
            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <label>Email
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                    @error('email') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label>Password
                    <input type="password" name="password" required autocomplete="current-password">
                    @error('password') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="remember"><input type="checkbox" name="remember" value="1"> Ricordami</label>
                <button type="submit">Accedi</button>
            </form>
            <p class="footer">Non hai un account? <a href="{{ route('register') }}">Registrati</a></p>
        </main>
    </body>
</html>
