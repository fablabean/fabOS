{{--
    El buscador del menú lateral, y un menú más apretado (§19).

    El panel pasó de diez entradas a cuarenta y cuatro. A esa altura el menú
    ya no se recorre con la vista: se busca. Esto filtra las entradas al
    escribir —sin tildes y sin mayúsculas, que nadie las teclea buscando— y
    abre los grupos plegados mientras hay algo escrito, porque una entrada que
    coincide dentro de un grupo cerrado no sirve de nada.

    Va aquí, en la barra, y no en el buscador global de Filament: aquel busca
    REGISTROS —un equipo, una persona—; este busca PANTALLAS. Son preguntas
    distintas y mezclarlas en una caja deja las dos peor.
--}}
<div class="fi-buscador-menu" x-data="{}">
    <input type="search" id="buscar-menu" placeholder="Buscar en el menú…" autocomplete="off"
           aria-label="Buscar en el menú" spellcheck="false">
    <kbd aria-hidden="true">/</kbd>
</div>

<style>
    /* ---------- el buscador ---------- */
    .fi-buscador-menu{position:relative;margin:0 .25rem .6rem}
    .fi-buscador-menu input{
        width:100%;box-sizing:border-box;font-size:.85rem;line-height:1.2;
        padding:.45rem 2rem .45rem .7rem;border-radius:.5rem;
        border:1px solid rgba(128,128,128,.28);background:rgba(128,128,128,.07);
        color:inherit;outline:none;
    }
    .fi-buscador-menu input:focus{border-color:rgb(var(--primary-500));background:transparent}
    .fi-buscador-menu input::-webkit-search-cancel-button{cursor:pointer}
    .fi-buscador-menu kbd{
        position:absolute;right:.55rem;top:50%;transform:translateY(-50%);
        font-size:.65rem;line-height:1;padding:.15rem .35rem;border-radius:.3rem;
        border:1px solid rgba(128,128,128,.35);color:rgba(128,128,128,.9);
        font-family:ui-monospace,Consolas,monospace;pointer-events:none;
    }
    .fi-buscador-menu input:focus + kbd{display:none}

    /* Plegada la barra, el buscador no cabe: se esconde con ella. */
    .fi-sidebar:not(.fi-sidebar-open) .fi-buscador-menu{display:none}

    /* ---------- menú más apretado ----------
       Filament deja aire de sobra entre líneas —piensa en menús de diez
       entradas—. Con cuarenta y cuatro, ese aire es lo que obliga a hacer
       scroll para llegar a Laboratorio. Se aprieta la línea y el hueco
       entre grupos, no la letra: sigue leyéndose igual. */
    .fi-sidebar .fi-sidebar-nav-groups{gap:.8rem}
    .fi-sidebar .fi-sidebar-group-items{gap:0}
    .fi-sidebar .fi-sidebar-item-btn{padding-top:.32rem;padding-bottom:.32rem}
    .fi-sidebar .fi-sidebar-group-btn{padding-top:.25rem;padding-bottom:.25rem}
    .fi-sidebar .fi-sidebar-item-label{line-height:1.25}

    /* ---------- mientras se filtra ----------
       Los grupos plegados se abren a la fuerza. Alpine los cierra con
       estilos en línea (display, height, overflow); el !important es la
       única manera de ganarle desde una hoja. Al borrar lo escrito se quita
       la clase y cada grupo vuelve a como lo tenía la persona. */
    .fi-sidebar-nav.filtrando .fi-sidebar-group-items{
        display:flex !important;height:auto !important;overflow:visible !important;
    }
    .fi-sidebar-nav.filtrando .fi-sidebar-group-collapse-btn{visibility:hidden}
    .fi-sidebar-nav.filtrando .fi-sidebar-group[hidden],
    .fi-sidebar-nav.filtrando .fi-sidebar-item[hidden]{display:none !important}
    .fi-sidebar-nav .fi-buscador-nada{
        display:none;padding:.6rem .75rem;font-size:.82rem;color:rgba(128,128,128,.95);
    }
    .fi-sidebar-nav.filtrando.sin-resultados .fi-buscador-nada{display:block}
</style>

<script>
(function () {
    const caja = document.getElementById('buscar-menu');
    if (! caja || caja.dataset.listo) return;
    caja.dataset.listo = '1';

    const nav = caja.closest('.fi-sidebar-nav');
    if (! nav) return;

    // El aviso de «nada coincide», al final de la lista.
    const nada = document.createElement('p');
    nada.className = 'fi-buscador-nada';
    nada.textContent = 'Nada en el menú se llama así.';
    nav.appendChild(nada);

    // Sin tildes y en minúsculas por los dos lados: se busca «formacion» y se
    // tiene que encontrar «Formación».
    const plano = (t) => (t || '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().trim();

    function filtrar() {
        const q = plano(caja.value);
        const filtrando = q !== '';
        nav.classList.toggle('filtrando', filtrando);

        let visibles = 0;

        nav.querySelectorAll('.fi-sidebar-group').forEach((grupo) => {
            let delGrupo = 0;

            grupo.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                const etiqueta = item.querySelector('.fi-sidebar-item-label');
                const coincide = ! filtrando || plano(etiqueta?.textContent).includes(q);
                item.hidden = ! coincide;
                if (coincide) delGrupo++;
            });

            // El nombre del grupo también cuenta: buscar «finanzas» debe
            // enseñar Finanzas entero, no nada.
            const rotulo = grupo.querySelector('.fi-sidebar-group-label');
            if (filtrando && delGrupo === 0 && plano(rotulo?.textContent).includes(q)) {
                grupo.querySelectorAll('.fi-sidebar-item').forEach((i) => { i.hidden = false; });
                delGrupo = grupo.querySelectorAll('.fi-sidebar-item').length;
            }

            grupo.hidden = filtrando && delGrupo === 0;
            visibles += delGrupo;
        });

        nav.classList.toggle('sin-resultados', filtrando && visibles === 0);
    }

    caja.addEventListener('input', filtrar);

    // Escape limpia; Enter abre lo único que queda, que es lo que se quería.
    caja.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { caja.value = ''; filtrar(); caja.blur(); }
        if (e.key === 'Enter') {
            const visibles = [...nav.querySelectorAll('.fi-sidebar-item:not([hidden]) a.fi-sidebar-item-btn')]
                .filter((a) => ! a.closest('.fi-sidebar-group[hidden]'));
            if (visibles.length === 1) visibles[0].click();
        }
    });

    // La barra «/» lleva al buscador desde cualquier sitio, salvo que se esté
    // escribiendo en otro campo.
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
        e.preventDefault();
        caja.focus();
        caja.select();
    });
})();
</script>
