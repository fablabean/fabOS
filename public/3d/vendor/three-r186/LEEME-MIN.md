# three.js r186 minificado

`three.core.min.js` y `three.module.min.js` son los mismos `three.core.js` y
`three.module.js` de esta carpeta, minificados con esbuild 0.28 (mismo código y
mismas exportaciones; se conserva la licencia MIT). Las páginas públicas de la
Galería 3D usan estos: pesan 766 KB en vez de 2,1 MB (unos 200 KB comprimidos),
lo que en un celular se nota al cargar y al leer el código.

Para regenerarlos (por ejemplo, al cambiar de versión de three.js):

    esbuild three.core.js --minify --format=esm --legal-comments=inline --outfile=three.core.min.js
    sed "s#from './three.core.js'#from './three.core.min.js'#g" three.module.js > /tmp/three.module.js
    esbuild /tmp/three.module.js --minify --format=esm --legal-comments=inline --outfile=three.module.min.js
