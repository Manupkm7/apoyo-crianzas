#!/bin/bash
# deploy.sh — Script de deploy para el Sistema de Apoyo a la Crianza
#
# Uso: bash deploy/deploy.sh
#
# Lo que hace:
#   1. Actualiza el código del backend (git pull)
#   2. Actualiza y buildea el frontend (git pull + npm run build)
#   3. Reconstruye la imagen Docker del backend y levanta los servicios
#   4. Corre las migraciones de base de datos
#   5. Limpia la caché de Laravel

set -e  # Detiene el script si cualquier comando falla

BACKEND_DIR="$(cd "$(dirname "$0")/.." && pwd)"
FRONTEND_DIR="$(cd "$BACKEND_DIR/../proyecto-crianzas-frontend" && pwd)"  # ajustar al nombre real de la carpeta del frontend

echo "=== Deploy del Sistema de Apoyo a la Crianza ==="
echo ""

# ── 1. Actualizar backend ─────────────────────────────────────────────────
echo "→ Actualizando backend..."
cd "$BACKEND_DIR"
git pull

# ── 2. Actualizar y buildear frontend ────────────────────────────────────
echo "→ Actualizando y buildeando frontend..."
cd "$FRONTEND_DIR"
git pull
npm install --silent
npm run build
echo "   Build del frontend listo en: $FRONTEND_DIR/dist"

# ── 3. Levantar servicios Docker ─────────────────────────────────────────
echo "→ Reconstruyendo imagen del backend y levantando servicios..."
cd "$BACKEND_DIR"
docker compose -f compose.prod.yaml up -d --build

# nginx resuelve el hostname 'app' una sola vez al arrancar (fastcgi_pass no
# usa variables) y no vuelve a mirar el DNS de Docker después. Si 'app' se
# recreó arriba, su IP en la red 'crianza' cambió, y nginx se queda pegado a
# la IP vieja -> "connect() failed (111: Connection refused)" -> 502 en TODO
# el sitio hasta reiniciar nginx a mano. Se lo reinicia siempre acá para que
# nunca dependa de que alguien lo note.
echo "→ Reiniciando nginx (evita que quede con la IP vieja de 'app')..."
docker compose -f compose.prod.yaml restart nginx

# Esperar a que la base de datos esté lista
echo "→ Esperando a que PostgreSQL esté disponible..."
sleep 5

# ── 4. Migraciones ────────────────────────────────────────────────────────
echo "→ Ejecutando migraciones..."
docker compose -f compose.prod.yaml exec app php artisan migrate --force

# ── 5. Limpiar caché de Laravel ───────────────────────────────────────────
echo "→ Limpiando caché..."
docker compose -f compose.prod.yaml exec app php artisan config:cache
docker compose -f compose.prod.yaml exec app php artisan route:cache
docker compose -f compose.prod.yaml exec app php artisan view:cache

# ── 6. Reiniciar el worker de colas ──────────────────────────────────────
# queue:work es un proceso de larga vida: bootea una sola vez y no ve el
# código ni la config nueva hasta que se le pide reiniciar. Sale tras el
# job en curso y 'restart: unless-stopped' lo vuelve a levantar.
echo "→ Reiniciando el worker de colas..."
docker compose -f compose.prod.yaml exec app php artisan queue:restart

echo ""
echo "=== Deploy completado ==="
