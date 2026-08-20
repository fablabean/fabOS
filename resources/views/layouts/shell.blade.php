<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'fabOS')</title>
    {{-- Estilos en linea a proposito: el arranque no depende de compilar assets. --}}
    <style>
        :root{
            --ground:#E8E8E2; --surface:#F6F6F2; --ink:#191A16; --ink-soft:#3D4038;
            --muted:#6E7066; --rule:#C7C7BD; --accent:#0D6E63; --danger:#9B2C2C;
        }
        @media (prefers-color-scheme:dark){
            :root{
                --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
                --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --danger:#E08585;
            }
        }
        *{box-sizing:border-box}
        body{
            margin:0;background:var(--ground);color:var(--ink);
            font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;
            line-height:1.6;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1.5rem;
        }
        .card{
            width:100%;max-width:26rem;background:var(--surface);border:1px solid var(--rule);
            border-radius:6px;padding:2rem;
        }
        .brand{font-weight:800;letter-spacing:-.03em;font-size:1.9rem;margin:0}
        .brand em{font-style:normal;color:var(--accent)}
        .powered{
            font-family:ui-monospace,Consolas,monospace;font-size:.62rem;letter-spacing:.16em;
            text-transform:uppercase;color:var(--muted);margin:.35rem 0 1.6rem;
        }
        h1{font-size:1.12rem;margin:0 0 .4rem;letter-spacing:-.01em}
        p.help{color:var(--ink-soft);font-size:.92rem;margin:0 0 1.4rem}
        label{
            display:block;font-family:ui-monospace,Consolas,monospace;font-size:.66rem;
            letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;
        }
        input{
            width:100%;padding:.7rem .8rem;font-size:1rem;font-family:inherit;
            background:var(--ground);color:var(--ink);
            border:1px solid var(--rule);border-radius:4px;
        }
        input:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
        input.code{
            font-family:ui-monospace,Consolas,monospace;font-size:1.6rem;letter-spacing:.5em;
            text-align:center;padding-left:.5em;
        }
        button{
            width:100%;margin-top:1.1rem;padding:.75rem 1rem;font-size:.95rem;font-family:inherit;
            font-weight:600;cursor:pointer;background:var(--accent);color:var(--surface);
            border:0;border-radius:4px;
        }
        button:hover{filter:brightness(1.08)}
        button:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
        .msg{
            font-size:.88rem;padding:.6rem .8rem;border-radius:4px;margin-bottom:1.1rem;
            border-left:3px solid var(--accent);background:color-mix(in srgb,var(--accent) 10%,transparent);
        }
        .msg.error{border-left-color:var(--danger);background:color-mix(in srgb,var(--danger) 10%,transparent)}
        .foot{margin-top:1.4rem;font-size:.82rem;color:var(--muted)}
        .foot a{color:var(--accent)}
        .who{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;color:var(--ink-soft);word-break:break-all}
    </style>
</head>
<body>
    <main class="card">
        <p class="brand">fab<em>OS</em></p>
        <p class="powered">Powered by {{ config('fabos.lab.name') }}</p>

        @if (session('status'))
            <div class="msg">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="msg error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
