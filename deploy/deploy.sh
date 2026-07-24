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
FRONTEND_DIR="$(cd "$BACKEND_DIR/../frontend" && pwd)"

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

echo ""
echo "=== Deploy completado ==="
