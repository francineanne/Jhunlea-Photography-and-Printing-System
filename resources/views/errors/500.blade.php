<!DOCTYPE html>
{{-- Deliberately static: no layout, @vite, session, user or database, because anything that can fail here would hide the real error page.
     Colours follow the system light/dark setting, since the saved theme lives in a script. --}}
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong</title>
    <style>
        :root { color-scheme: light dark; --bg: #FBFBFB; --text: #171717; --muted: #525252; --brand: #C62828; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0F0F0F; --text: #FAFAFA; --muted: #A3A3A3; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; text-align: center; }
        main { max-width: 32rem; }
        .mark { display: inline-block; margin-bottom: 2rem; line-height: 1; letter-spacing: 0.12em; font-weight: 700; font-size: 1.25rem; font-family: Georgia, 'Times New Roman', serif; }
        .mark small { display: block; margin-top: 0.4rem; font-family: system-ui, sans-serif; font-size: 0.625rem; font-weight: 600; letter-spacing: 0.16em; color: var(--muted); }
        .code { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 5rem; font-weight: 600; color: var(--brand); line-height: 1; }
        h1 { margin: 1rem 0 0; font-family: Georgia, 'Times New Roman', serif; font-size: 1.875rem; font-weight: 600; }
        p.msg { margin: 0.75rem 0 0; color: var(--muted); line-height: 1.6; }
        a.btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; margin-top: 2rem; padding: 0 1.25rem; border-radius: 10px; background: var(--brand); color: #fff; font-weight: 600; font-size: 0.875rem; text-decoration: none; }
        a.btn:hover { background: #B71C1C; }
        a.btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    </style>
</head>

<body>
    <main id="main-content">
        <span class="mark">JHUNLEA<small>PHOTOGRAPHY &amp; PRINTING</small></span>
        <p class="code" aria-hidden="true">500</p>
        <h1>Something went wrong</h1>
        <p class="msg">Sorry, something went wrong on our side. Please try again in a few minutes.</p>
        <a class="btn" href="/">Go back home</a>
    </main>
</body>

</html>
