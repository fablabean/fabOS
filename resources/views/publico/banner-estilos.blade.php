    /* ==================== EL BANNER ====================
       Menos cosas y más grandes. Antes el banner cargaba con todo —seis
       frases, tres botones, cinco cifras y un mapa de módulos debajo— y lo
       que consigue una portada así es que no se lea ninguna. Aquí queda una
       frase, una línea y un botón; las cifras bajan a su propia franja. */

    .hero{
        position:relative;overflow:hidden;isolation:isolate;
        background:var(--banner);color:var(--banner-ink);
        min-height:clamp(28rem,80vh,44rem);
        display:flex;align-items:center;
        padding:clamp(3.5rem,10vh,6rem) 1.4rem clamp(3rem,8vh,5rem);
    }

    /* La trama de fondo: la retícula de un plano. Se queda porque no compite
       con nada, y porque en un color plano evita que el fondo sea un vacío. */
    .hero::before{
        content:"";position:absolute;inset:0;pointer-events:none;
        background-image:
            linear-gradient(to right, rgba(243,244,236,.05) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(243,244,236,.05) 1px, transparent 1px);
        background-size:64px 64px;
        -webkit-mask-image:radial-gradient(120% 95% at 10% 0%, #000 20%, transparent 75%);
        mask-image:radial-gradient(120% 95% at 10% 0%, #000 20%, transparent 75%);
    }
    .hero::after{
        content:"";position:absolute;inset:0;pointer-events:none;
        background:radial-gradient(60% 100% at 88% 15%, rgba(92,201,184,.16), transparent 60%);
    }

    /* ---------- los fondos, apilados ----------
       Van apilados y se cruzan por opacidad: así ninguno «salta» al entrar y
       el texto nunca se mueve de sitio. */
    .laminas{position:absolute;inset:0;z-index:-1;pointer-events:none}
    .lamina{
        position:absolute;inset:0;opacity:0;transition:opacity 1.1s ease;
        background-color:var(--color,var(--banner));
        background-position:var(--pos,center);background-size:cover;background-repeat:no-repeat;
    }
    .lamina.activa{opacity:1;animation:respira 16s ease-out both}
    /* Un zoom muy lento: la foto respira, y nada se mueve de sitio. */
    @keyframes respira{from{transform:scale(1.005)}to{transform:scale(1.08)}}

    .lamina video{
        position:absolute;inset:0;width:100%;height:100%;
        object-fit:cover;object-position:var(--pos,center);
    }

    /* El velo. No es decoración: es lo que hace legible el texto. Su fuerza
       la decide quien sube la foto, porque depende de la foto. */
    .lamina::after{
        content:"";position:absolute;inset:0;opacity:var(--velo,.7);
        background:
            linear-gradient(100deg, rgba(12,14,11,.96) 0%, rgba(12,14,11,.62) 45%, rgba(12,14,11,.12) 100%),
            linear-gradient(to top, rgba(12,14,11,.85), transparent 55%);
    }
    .lamina.centro::after{
        background:
            radial-gradient(85% 75% at 50% 50%, rgba(12,14,11,.82), rgba(12,14,11,.5) 60%, rgba(12,14,11,.25)),
            linear-gradient(to top, rgba(12,14,11,.8), transparent 60%);
    }

    /* ---------- el texto ---------- */
    /* `min-width:0` no es cosmética: un elemento flexible mide por defecto lo
       que su contenido pide como mínimo, y puede crecer MÁS ancho que la
       pantalla. En un teléfono eso ensanchaba el documento entero —el banner,
       las cifras y todo lo de abajo salían cortados por la derecha—. */
    .hero .in{position:relative;z-index:1;width:100%;min-width:0;max-width:70rem;margin:0 auto}
    .hero .in.centro{text-align:center;display:flex;flex-direction:column;align-items:center}

    /* Rejilla de una sola celda: todas las diapositivas ocupan la misma, así
       que el banner mide lo que la más alta y no cambia de altura al rotar.
       Con `min-height` a ojo, la lámina de texto largo se salía. */
    .texto{display:grid;width:100%}
    .diapo{
        grid-area:1/1;opacity:0;visibility:hidden;pointer-events:none;
        transition:opacity .5s ease;
        /* Centrada dentro de la celda: como la celda la mide la lámina más
           alta, una lámina corta se quedaba pegada arriba con un hueco debajo. */
        display:flex;flex-direction:column;justify-content:center;
        min-width:0;
    }
    .diapo.activa{opacity:1;visibility:visible;pointer-events:auto}

    .hero .rotulo{color:var(--banner-muted);margin:0 0 .9rem}
    .hero h1{
        color:var(--banner-ink);margin:0 0 1rem;max-width:15ch;
        font-size:clamp(2.1rem,6.2vw,4.3rem);line-height:1.02;
        letter-spacing:-.045em;font-weight:800;
    }
    .in.centro h1{max-width:19ch;margin-left:auto;margin-right:auto}
    .hero h1 em{font-style:normal;color:var(--banner-accent)}
    .hero .dice{
        color:var(--banner-muted);font-size:clamp(1rem,1.5vw,1.15rem);
        max-width:46ch;margin:0 0 1.9rem;
    }
    .in.centro .dice{margin-left:auto;margin-right:auto}
    .hero .acciones{display:flex;gap:.7rem;flex-wrap:wrap}
    .in.centro .acciones{justify-content:center}

    /* ---------- el QR ----------
       Una tarjeta clara sobre el fondo oscuro: el QR necesita contraste y
       borde blanco para leerse desde lejos. Debajo de los botones en
       pantallas medianas; a la derecha, a media altura, cuando hay sitio y
       el texto va a la izquierda. */
    .hero .qr{
        display:inline-flex;align-items:center;gap:.9rem;margin-top:1.6rem;
        padding:.6rem .9rem .6rem .6rem;border-radius:12px;
        background:rgba(243,244,236,.94);color:#111;text-decoration:none;
        box-shadow:0 10px 30px rgba(0,0,0,.25);
    }
    .hero .qr .codigo{display:block;line-height:0;border-radius:6px;overflow:hidden;background:#fff}
    .hero .qr .codigo svg{display:block;width:8rem;height:8rem}
    .hero .qr .dice-qr{font-weight:600;font-size:.95rem;max-width:11ch;line-height:1.25}
    .in.centro .qr{align-self:center}
    @media (min-width:960px){
        .hero .in:not(.centro) .qr{
            position:absolute;right:0;top:50%;transform:translateY(-50%);margin:0;
            flex-direction:column;text-align:center;padding:.8rem;
        }
        .hero .in:not(.centro) .qr .codigo svg{width:10rem;height:10rem}
    }
    /* En el teléfono el QR no sirve: se esconde y el texto queda de botón. */
    @media (max-width:640px){
        .hero .qr{padding:0;background:transparent;box-shadow:none;margin-top:.9rem}
        .hero .qr .codigo{display:none}
        .hero .qr .dice-qr{
            max-width:none;display:inline-block;padding:.6rem 1rem;border-radius:999px;
            border:1px solid rgba(243,244,236,.5);color:var(--banner-ink);
        }
    }

    /* ---------- los puntos ----------
       Cada barra se llena mientras dura su lámina: se ve cuánto falta para
       que cambie, en vez de que cambie de repente mientras se lee. */
    .puntos{display:flex;gap:.5rem;margin-top:2.2rem}
    .in.centro .puntos{justify-content:center}
    .puntos button{
        margin:0;padding:0;width:2.6rem;height:3px;border:0;border-radius:2px;
        cursor:pointer;position:relative;overflow:hidden;
        background:rgba(243,244,236,.22);
    }
    .puntos button::after{
        content:"";position:absolute;inset:0;background:var(--banner-accent);
        transform:scaleX(0);transform-origin:left;
    }
    .puntos button[aria-current="true"]::after{
        animation:avance var(--intervalo,7s) linear forwards;
    }
    /* Detenido para leer: la barra se para donde iba, no vuelve a empezar. */
    .hero.quieto .puntos button::after{animation-play-state:paused}
    @keyframes avance{from{transform:scaleX(0)}to{transform:scaleX(1)}}

    /* ==================== EFECTOS DEL TÍTULO ====================
       Son efectos de ENTRADA, no bucles: algo que se mueve sin parar detrás
       de un texto que hay que leer cansa a los diez segundos.

       Las unidades (.u) las crea el script partiendo el título en palabras o
       en letras. Sin script no existen, y el título se ve tal cual: por eso
       el estado escondido se declara sobre .u y nunca sobre el h1. */
    .diapo h1 .u{
        display:inline-block;overflow:hidden;vertical-align:bottom;
        /* La máscara recortaría las tildes y las jotas sin este respiro. */
        padding-bottom:.14em;margin-bottom:-.14em;
    }
    .diapo h1 .u > i{display:inline-block;font-style:inherit}

    /* Las palabras suben */
    .efecto-subir h1 .u > i{transform:translateY(115%);opacity:0}
    .diapo.anima.efecto-subir h1 .u > i{
        animation:subir .75s cubic-bezier(.22,.68,.2,1) forwards;
        animation-delay:calc(var(--i) * 65ms);
    }
    @keyframes subir{to{transform:none;opacity:1}}

    /* Cortina: una barra de color barre la palabra y la deja escrita */
    .efecto-cortina h1 .u{position:relative}
    .efecto-cortina h1 .u > i{clip-path:inset(0 100% 0 0)}
    .diapo.anima.efecto-cortina h1 .u > i{
        animation:descubre .5s ease forwards;
        animation-delay:calc(var(--i) * 110ms + .2s);
    }
    @keyframes descubre{to{clip-path:inset(0 0 0 0)}}
    .efecto-cortina h1 .u::after{
        content:"";position:absolute;inset:0;background:var(--banner-accent);
        transform:scaleX(0);transform-origin:left;pointer-events:none;
    }
    .diapo.anima.efecto-cortina h1 .u::after{
        animation:barre .7s ease forwards;
        animation-delay:calc(var(--i) * 110ms);
    }
    @keyframes barre{
        0%{transform:scaleX(0);transform-origin:left}
        45%{transform:scaleX(1);transform-origin:left}
        55%{transform:scaleX(1);transform-origin:right}
        100%{transform:scaleX(0);transform-origin:right}
    }

    /* Entra desenfocado */
    .efecto-desenfoque h1 .u{overflow:visible}
    .efecto-desenfoque h1 .u > i{opacity:0;filter:blur(14px);transform:translateY(.18em)}
    .diapo.anima.efecto-desenfoque h1 .u > i{
        animation:enfoca .8s ease forwards;
        animation-delay:calc(var(--i) * 80ms);
    }
    @keyframes enfoca{to{opacity:1;filter:blur(0);transform:none}}

    /* Máquina de escribir. Las letras ocupan su sitio desde el principio
       aunque no se vean: si aparecieran una a una, la línea se recompondría
       a cada letra y el título bailaría entero. */
    .efecto-maquina h1 .u{overflow:visible}
    .efecto-maquina h1 .u.oculta{opacity:0}
    .cursor{
        display:inline-block;width:.055em;height:.92em;background:var(--banner-accent);
        vertical-align:-.06em;margin-left:.04em;animation:parpadea 1s steps(1) infinite;
    }
    @keyframes parpadea{50%{opacity:0}}

    /* Brillo sobre lo resaltado. Aquí el título no se parte: el barrido de
       luz se recorta contra el texto de la palabra entera, y una palabra
       partida en trozos con opacidad propia rompe ese recorte. */
    /* Escondido solo si el script está vivo: sin él no hay nada que lo
       vuelva a encender, y el titular se quedaría invisible. */
    .hero.js .efecto-brillo h1{opacity:0}
    .diapo.anima.efecto-brillo h1{animation:asoma .9s ease forwards}
    @keyframes asoma{to{opacity:1}}
    .efecto-brillo h1 em{
        background:linear-gradient(100deg,
            var(--banner-accent) 42%, #FFFFFF 50%, var(--banner-accent) 58%);
        background-size:280% 100%;
        -webkit-background-clip:text;background-clip:text;color:transparent;
        animation:brilla 3.6s linear infinite;
    }
    @keyframes brilla{from{background-position:130% 0}to{background-position:-30% 0}}

    /* Quien pide menos movimiento lo recibe todo quieto y completo. Lo que se
       quita es la animación, nunca el contenido. */
    @media (prefers-reduced-motion:reduce){
        .lamina,.diapo{transition:none}
        .lamina.activa{animation:none}
        .diapo h1,.diapo h1 .u,.diapo h1 .u > i{
            animation:none !important;opacity:1 !important;
            transform:none !important;filter:none !important;clip-path:none !important;
        }
        .diapo h1 .u::after{animation:none;transform:scaleX(0)}
        .efecto-brillo h1 em{
            animation:none;background:none;color:var(--banner-accent);
            -webkit-text-fill-color:var(--banner-accent);
        }
        .puntos button::after,.cursor{animation:none}
    }

    /* ---------- las cifras ----------
       Fuera del banner: son una prueba, no un titular. Dentro competían con
       la frase principal y hacían del banner una ficha técnica. */
    .cifras{
        display:flex;gap:clamp(1.6rem,4vw,3rem);flex-wrap:wrap;align-items:baseline;
        max-width:70rem;margin:0 auto;padding:1.3rem 1.4rem 1.5rem;
        border-bottom:1px solid var(--rule);
    }
    .cifra b{display:block;font-size:1.7rem;letter-spacing:-.03em;color:var(--ink);line-height:1.2}
    .cifra span{
        font-family:ui-monospace,Consolas,monospace;font-size:.64rem;
        letter-spacing:.14em;text-transform:uppercase;color:var(--muted);
    }
