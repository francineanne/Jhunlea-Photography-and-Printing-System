<!DOCTYPE html>
{{-- Deliberately static: no layout, @vite, session, user or database, because anything that can fail here would hide the real error page.
     Same look as the other error pages (resources/views/components/layout/error-card.blade.php), drawn with inline CSS only.
     Colours follow the system light/dark setting, since the saved theme lives in a script. --}}
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong</title>
    <style>
        :root { color-scheme: light dark; --bg: #FBFBFB; --card: #FFFFFF; --border: #E5E5E5; --panel: #F5F5F5; --text: #171717; --muted: #525252; --brand: #C62828; --pill: #FEE2E2; --pilltext: #C62828; --body: #262626; --ring: #D4D4D4; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0F0F0F; --card: #171717; --border: #262626; --panel: #1F1F1F; --text: #FAFAFA; --muted: #A3A3A3; --pill: rgba(220,38,38,.2); --pilltext: #F87171; --body: #171717; --ring: #404040; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .card { position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; gap: 2.5rem; width: 100%; max-width: 72rem; padding: 1.5rem; border: 1px solid var(--border); border-radius: 12px; background: var(--card); box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .watermark { position: absolute; right: -1rem; bottom: -1.5rem; margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 120px; font-weight: 700; line-height: 1; letter-spacing: -0.05em; opacity: .03; pointer-events: none; user-select: none; }
        .art { position: relative; display: flex; align-items: center; justify-content: center; width: 100%; max-width: 440px; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 16px; background: var(--panel); padding: 1.5rem; }
        .art::before { content: ''; position: absolute; left: 50%; top: 50%; width: 16rem; height: 16rem; transform: translate(-50%, -50%); border-radius: 50%; background: radial-gradient(circle, rgba(198,40,40,.12), transparent 70%); }
        .art svg { position: relative; width: 100%; height: 100%; max-height: 280px; }
        .copy { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 1rem; width: 100%; }
        .brand { font-family: Georgia, 'Times New Roman', serif; font-weight: 700; letter-spacing: .12em; font-size: 1rem; line-height: 1; }
        .brand small { display: block; margin-top: .35rem; font-family: system-ui, sans-serif; font-size: .5rem; font-weight: 600; letter-spacing: .16em; color: var(--muted); }
        .pill { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; border-radius: 999px; background: var(--pill); color: var(--pilltext); font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .pill b { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }
        .dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--brand); animation: ping 1s cubic-bezier(0, 0, .2, 1) infinite; }
        @keyframes ping { 75%, 100% { transform: scale(2); opacity: 0; } }
        h1 { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 1.75rem; line-height: 2.25rem; font-weight: 600; letter-spacing: -0.01em; }
        p.msg { margin: 0; max-width: 32rem; color: var(--muted); line-height: 1.7; }
        a.btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; margin-top: .5rem; padding: 0 1.25rem; border-radius: 10px; background: var(--brand); color: #fff; font-weight: 600; font-size: .875rem; text-decoration: none; transition: background-color .28s cubic-bezier(.16, 1, .3, 1), transform .28s cubic-bezier(.16, 1, .3, 1); }
        a.btn:hover { background: #93000E; }
        a.btn:active { transform: scale(.98); }
        a.btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        @media (min-width: 640px) { .card { padding: 2.5rem; } h1 { font-size: 2.5rem; line-height: 3rem; } .watermark { font-size: 220px; right: -2rem; bottom: -2.5rem; } }
        @media (min-width: 1024px) { .card { flex-direction: row; padding: 3.5rem; gap: 3rem; } .card > * { width: 50%; } .art { max-width: 440px; margin: 0 auto; } }
        @media (prefers-reduced-motion: reduce) { .dot { animation: none; } a.btn { transition: none; } }
    </style>
</head>

<body>
    <main id="main-content" class="card">
        <p class="watermark" aria-hidden="true">500</p>

        <div class="art">
            <svg viewBox="0 0 400 300" fill="none" aria-hidden="true">
                <g stroke="var(--ring)" stroke-dasharray="3 6" opacity=".7"><line x1="20" y1="150" x2="380" y2="150" /><line x1="200" y1="20" x2="200" y2="280" /></g>
                <circle cx="200" cy="150" r="92" stroke="var(--ring)" stroke-width="4" stroke-dasharray="2 12" />
                <circle cx="200" cy="150" r="80" fill="#262626" />
                <g transform="translate(200 150) rotate(18)">
                    <path d="M0 -66C26 -66 52 -48 62 -24L24 -4C16 -18 8 -26 -4 -26Z" fill="#C62828" opacity=".95" />
                    <path d="M62 -24C72 -2 66 26 50 46L18 18C24 8 24 0 24 -4Z" fill="#B71C1C" opacity=".9" transform="rotate(14)" />
                    <path d="M50 46C30 64 4 70 -16 62L-4 24C6 24 14 22 18 18Z" fill="#991B1B" opacity=".9" transform="rotate(-10)" />
                    <path d="M-16 62C-44 56 -62 34 -64 8L-24 6C-22 16 -12 24 -4 24Z" fill="#C62828" opacity=".95" transform="rotate(22)" />
                    <path d="M-64 8C-66 -20 -52 -48 -28 -60L-4 -26C-16 -22 -22 -10 -24 6Z" fill="#D32F2F" opacity=".92" transform="rotate(-8)" />
                    <polygon points="0,-30 26,-15 26,15 0,30 -26,15 -26,-15" fill="#0A0A0A" stroke="#C62828" stroke-width="1.5" />
                </g>
            </svg>
        </div>

        <div class="copy">
            <span class="brand">JHUNLEA<small>PHOTOGRAPHY &amp; PRINTING</small></span>
            <span class="pill"><span class="dot" aria-hidden="true"></span><b>500</b> Server error</span>
            <h1>Something went wrong</h1>
            <p class="msg">Sorry, something went wrong on our side. Please try again in a few minutes.</p>
            <a class="btn" href="/">Go back home</a>
        </div>
    </main>
</body>

</html>
