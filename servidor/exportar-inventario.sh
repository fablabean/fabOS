#!/usr/bin/env bash
#
# Copia del inventario para el equipo de desarrollo: activos, insumos y
# ubicaciones, con los catálogos de los que cuelgan (áreas, espacios, familias
# de riesgo, categorías de insumo), los precios por cantidad y referencias de
# precio de cada insumo, y sus fotos.
#
# No lleva personas, reservas, proyectos, compras ni movimientos de stock.
#
# Se corre en el servidor, desde la instalación, como el usuario fabos:
#
#     bash servidor/exportar-inventario.sh
#
# Deja ~/inventario-AAAAMMDD-HHMM.tar.gz con el SQL, las fotos y `cargar.sh`,
# que es lo que el equipo corre en su copia local (ver LEEME.txt del paquete).
#
# Para probarlo en local: COMPOSE="docker compose" bash servidor/exportar-inventario.sh

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="${COMPOSE:-docker compose -f compose.yaml -f compose.produccion.yaml}"
DB_USER="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"\r')"
DB_NAME="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"\r')"

# En el orden en que se cargan: cada tabla después de aquellas a las que apunta.
TABLAS=(areas spaces area_space risk_families locations supply_categories assets asset_dependencies supplies)

# Polimórficas: solo las filas que son de un insumo o de un activo.
POLIMORFICAS=(price_breaks referencias_de_precio)
TIPOS="'App\\Models\\Supply','App\\Models\\Asset'"

SELLO="$(date +%Y%m%d-%H%M)"
TRABAJO="$(mktemp -d)"
trap 'rm -rf "$TRABAJO"' EXIT
DIR="$TRABAJO/inventario-$SELLO"
mkdir -p "$DIR"

psql() {
    $COMPOSE exec -T pgsql psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -At "$@"
}

echo "· Volcando tablas…"
{
    lista="$(printf 'public.%s, ' "${TABLAS[@]}")"

    echo "-- Inventario de fabOS, $(date '+%Y-%m-%d %H:%M')"
    echo "-- Reemplaza el inventario de la base donde se cargue. Lo que depende de"
    echo "-- estas tablas (reservas, cursos, proyectos, compras…) se vacía también."
    echo "SET client_min_messages = warning;"
    echo "BEGIN;"
    echo "TRUNCATE ${lista%, } RESTART IDENTITY CASCADE;"
    for t in "${POLIMORFICAS[@]}"; do
        echo "DELETE FROM public.$t WHERE priceable_type IN ($TIPOS);"
    done
    echo "COMMIT;"

    # Avisa de las tablas que se apuntan a sí mismas (ubicaciones, categorías);
    # --disable-triggers ya lo resuelve, así que solo se muestra si falla.
    args=()
    for t in "${TABLAS[@]}"; do args+=(-t "public.$t"); done
    $COMPOSE exec -T pgsql pg_dump -U "$DB_USER" -d "$DB_NAME" \
        --data-only --disable-triggers --no-owner --no-privileges "${args[@]}" \
        2> "$TRABAJO/pg_dump.err" || { cat "$TRABAJO/pg_dump.err" >&2; exit 1; }

    for t in "${POLIMORFICAS[@]}"; do
        echo "COPY public.$t FROM stdin;"
        psql -c "COPY (SELECT * FROM $t WHERE priceable_type IN ($TIPOS) ORDER BY id) TO STDOUT"
        echo '\.'
    done

    # Los contadores, para que lo que el equipo cree después no choque con lo cargado.
    for t in "${TABLAS[@]}" "${POLIMORFICAS[@]}"; do
        echo "SELECT setval(pg_get_serial_sequence('public.$t', 'id'), COALESCE((SELECT MAX(id) FROM public.$t), 0) + 1, false);"
    done
} > "$DIR/inventario.sql"

echo "· Juntando fotos…"
psql -c "
    SELECT photo_path FROM assets WHERE photo_path IS NOT NULL
    UNION SELECT photo_path FROM supplies WHERE photo_path IS NOT NULL
    UNION SELECT ilustracion_path FROM supplies WHERE ilustracion_path IS NOT NULL
    UNION SELECT photo_path FROM areas WHERE photo_path IS NOT NULL
    UNION SELECT banner_path FROM areas WHERE banner_path IS NOT NULL
" | tr -d '\r' > "$TRABAJO/fotos-todas.txt"

: > "$TRABAJO/fotos.txt"
faltan=0
while IFS= read -r f; do
    [ -n "$f" ] || continue
    if [ -f "storage/app/public/$f" ]; then echo "$f" >> "$TRABAJO/fotos.txt"; else faltan=$((faltan + 1)); fi
done < "$TRABAJO/fotos-todas.txt"
tar -czf "$DIR/fotos.tar.gz" -C storage/app/public -T "$TRABAJO/fotos.txt"

cat > "$DIR/cargar.sh" <<'CARGAR'
#!/usr/bin/env bash
# Carga el inventario en la copia local de fabOS (Sail). Correr desde la raíz
# del proyecto, con los contenedores arriba y las migraciones al día:
#
#     bash /ruta/a/inventario-…/cargar.sh
set -euo pipefail
AQUI="$(cd "$(dirname "$0")" && pwd)"
echo "Esto reemplaza el inventario de tu base local y vacía lo que depende de él"
echo "(reservas, cursos, proyectos, compras, certifabs…). Usuarios y roles quedan."
read -r -p "¿Seguir? [s/N] " r
[ "$r" = s ] || [ "$r" = S ] || exit 1
DB_USER="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"\r')"
DB_NAME="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"\r')"
docker compose exec -T pgsql psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q < "$AQUI/inventario.sql" > /dev/null
mkdir -p storage/app/public
tar -xzf "$AQUI/fotos.tar.gz" -C storage/app/public
docker compose exec -T -u sail laravel.test php artisan storage:link > /dev/null 2>&1 || true
echo "Inventario cargado."
CARGAR

cat > "$DIR/LEEME.txt" <<LEEME
Inventario de fabOS, sacado de producción el $(date '+%Y-%m-%d %H:%M').

Trae: activos, insumos y ubicaciones, con áreas, espacios, familias de riesgo,
categorías de insumo, dependencias entre activos, precios por cantidad,
referencias de precio y fotos. No trae personas ni datos de uso.

Para cargarlo en tu copia local (Sail):
  1. Contenedores arriba y migraciones al día:
       docker compose exec -u sail laravel.test php artisan migrate
  2. Desde la raíz del proyecto:
       bash /ruta/a/inventario-$SELLO/cargar.sh

Ojo: reemplaza el inventario de tu base y vacía todo lo que depende de él,
directa o indirectamente: reservas, certifabs, cursos, proyectos, compras,
ventas, órdenes de trabajo, mantenimientos, preguntas frecuentes, recorridos…
Usuarios, roles y plantillas de notificación no se tocan. Lo más limpio es
cargarlo sobre una base recién hecha (migrate:fresh --seed).
LEEME

SALIDA="$HOME/inventario-$SELLO.tar.gz"
tar -czf "$SALIDA" -C "$TRABAJO" "inventario-$SELLO"

echo
for t in assets supplies locations; do
    printf '  %-10s %s\n' "$t" "$(psql -c "SELECT COUNT(*) FROM $t" | tr -d '\r')"
done
echo "  fotos      $(wc -l < "$TRABAJO/fotos.txt")$([ "$faltan" -gt 0 ] && echo " ($faltan referenciadas no están en disco)")"
echo
echo "Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
