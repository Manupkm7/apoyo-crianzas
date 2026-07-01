# Cloudflare Tunnel — Guía de configuración

## ¿Qué es y por qué lo usamos?

Cloudflare Tunnel permite exponer la aplicación en internet **sin abrir puertos en el router ni tener una IP fija**. El contenedor `cloudflared` establece una conexión saliente segura hacia los servidores de Cloudflare, que enrutan el tráfico por ese canal.

```
Usuario
  ↓
Cloudflare (DNS + CDN + SSL automático)
  ↓
cloudflared (Docker, conexión saliente)
  ↓
nginx (Docker, distribuye el tráfico)
  ├── /api/*  → laravel.test:80  (API de Laravel)
  └── /*      → frontend:5173   (Vite / React)
```

---

## Opción A — Sin dominio propio (Quick Tunnel)

**No necesitás cuenta ni dominio.** Esta es la configuración activa por defecto en `compose.yaml`.

### Paso 1 — Levantar todo

```bash
docker compose up -d
```

### Paso 2 — Obtener la URL pública

```bash
docker compose logs cloudflared
```

Buscá una línea como esta:

```
Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):
https://palabras-random-aqui.trycloudflare.com
```

Esa es tu URL pública. Se puede compartir y acceder desde cualquier lugar.

> **Limitación:** la URL cambia cada vez que se reinicia el contenedor `cloudflared`. Para una URL fija, usá la Opción B.

### Agregar al .env del backend (recomendado)

Para que Laravel genere URLs correctas con HTTPS:

```
APP_URL=https://palabras-random-aqui.trycloudflare.com
TRUSTED_PROXIES=*
```

> **¿Qué hace `TRUSTED_PROXIES`?** Laravel necesita saber que está detrás de un proxy (nginx → cloudflared). Sin esto, ignora los headers de HTTPS y puede generar links incorrectos.

---

## Opción B — Con dominio propio (Tunnel nombrado)

Necesitás un dominio gestionado en Cloudflare. Los dominios `.com` arrancan desde ~$10/año; también se puede usar un dominio comprado en otro lado y transferir solo el DNS a Cloudflare (gratis).

### Paso 1 — Crear el tunnel en el panel

1. Ir a [Cloudflare Dashboard](https://dash.cloudflare.com) → **Zero Trust → Networks → Tunnels**
2. **"Create a tunnel"** → tipo **"Cloudflared"** → darle un nombre (ej: `crianza-dev`)
3. En **"Install connector"** → seleccionar **"Docker"** → copiar el **token** (la parte larga)

### Paso 2 — Configurar el destino

En la sección **"Route tunnel"**:

| Campo | Valor |
|---|---|
| Subdomain | `crianza` (o el que quieras) |
| Domain | tu dominio en Cloudflare |
| Type | `HTTP` |
| URL | `nginx:80` |

### Paso 3 — Cambiar el compose.yaml

En `backend/compose.yaml`, reemplazar el servicio `cloudflared`:

```yaml
cloudflared:
    image: 'cloudflare/cloudflared:latest'
    command: tunnel --no-autoupdate run
    environment:
        TUNNEL_TOKEN: '${CLOUDFLARE_TUNNEL_TOKEN}'
    depends_on:
        - nginx
    networks:
        - sail
    restart: unless-stopped
```

### Paso 4 — Agregar al .env del backend

```
CLOUDFLARE_TUNNEL_TOKEN=pegar-aquí-el-token-largo
APP_URL=https://crianza.tu-dominio.com
FRONTEND_URL=https://crianza.tu-dominio.com
TRUSTED_PROXIES=*
```

### Paso 5 — HMR del frontend (opcional)

Para que Vite actualice el navegador automáticamente al guardar un archivo, agregar en `frontend/.env`:

```
VITE_HMR_HOST=crianza.tu-dominio.com
```

Sin esto el HMR no funciona de forma remota (hay que recargar manualmente), pero la app funciona igual.

---

## Acceso local (sin pasar por el tunnel)

| URL | Qué muestra |
|---|---|
| `http://localhost:8080` | La app completa pasando por nginx (igual que el tunnel) |
| `http://localhost:80` | Solo la API de Laravel (sin frontend) |

---

## Comandos útiles

| Comando | ¿Qué hace? |
|---|---|
| `docker compose logs cloudflared` | Ver la URL generada y el estado del tunnel |
| `docker compose logs cloudflared -f` | Ver logs en tiempo real |
| `docker compose logs nginx -f` | Ver requests entrantes |
| `docker compose restart cloudflared` | Reiniciar el tunnel (genera nueva URL en modo Quick) |
| `docker compose stop cloudflared` | Apagar el tunnel (la app sigue en localhost:8080) |

---

## Solución de problemas

**No aparece la URL en los logs:**
- Esperar 20-30 segundos, el tunnel puede tardar en conectar
- Correr `docker compose logs cloudflared` nuevamente

**La app abre pero la API no responde (error en el login):**
- Ver logs de nginx: `docker compose logs nginx`
- Verificar que el backend esté corriendo: `docker compose logs laravel.test`

**Laravel devuelve URLs con `http://` en lugar de `https://`:**
- Agregar `TRUSTED_PROXIES=*` al `backend/.env`
- Reiniciar el backend: `docker compose restart laravel.test`
