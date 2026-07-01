# Configuración del Entorno Local

## Requisitos previos

- **Docker Desktop** instalado y corriendo (en Windows: Docker Desktop con WSL2 habilitado)
- **PHP 8.4 + Composer** instalados localmente (solo para el primer `composer install`; después todo corre dentro de Docker)

---

## Instalación paso a paso

### Paso 1 — Instalar dependencias PHP

Desde la carpeta `backend/`, ejecutar:

```bash
composer install
```

Esto descarga todas las librerías necesarias a la carpeta `vendor/`. Solo hace falta hacerlo una vez (o cuando cambie el `composer.json`).

---

### Paso 2 — Configurar el archivo `.env`

El archivo `.env` ya viene configurado en el repositorio. Si estuvieras arrancando desde cero, lo copiarías así:

```bash
cp .env.example .env
php artisan key:generate
```

**En Windows (obligatorio):** agregar estas dos líneas al final del `.env`:

```
WWWGROUP=0
WWWUSER=0
```

**¿Por qué?** Docker Sail necesita saber con qué usuario de Linux corre la aplicación dentro del contenedor. En Windows, el valor `0` (root) evita errores de permisos durante la construcción.

---

### Paso 3 — Construir el contenedor de la aplicación

```bash
docker compose build laravel.test
```

Descarga la imagen base de PHP 8.4 y configura el entorno. Puede tardar varios minutos la primera vez.

---

### Paso 4 — Levantar todos los servicios

```bash
docker compose up -d
```

Esto levanta tres servicios:
- `laravel.test` — La aplicación PHP/Laravel (puerto 80)
- `pgsql` — La base de datos PostgreSQL (puerto 5432)
---

### Paso 5 — Ejecutar las migraciones

Las migraciones crean todas las tablas en la base de datos:

```bash
docker compose exec laravel.test php artisan migrate
```

---

### Paso 6 — Cargar los datos iniciales (Seeder)

```bash
docker compose exec laravel.test php artisan db:seed
```

Esto crea:
- Los 4 roles del sistema (admin, coordinador, institución, representante)
- Los permisos correspondientes a cada rol
- La institución "Municipalidad - Administración Central"
- El usuario administrador inicial

**Credenciales del admin inicial:**
- Email: `admin@crianza.local`
- Contraseña: `Crianza2026!Admin#`

> ⚠️ Cambiar la contraseña del admin inmediatamente después del primer acceso en producción.

---

### Paso 7 — Verificar que todo funciona

Probar el endpoint de login con una herramienta como Postman o curl:

```bash
curl -X POST http://localhost/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@crianza.local","password":"Crianza2026!Admin#"}'
```

Si todo está bien, el sistema devuelve un token de acceso.

---

## Comandos útiles del día a día

| Comando | ¿Qué hace? |
|---|---|
| `docker compose up -d` | Inicia todos los servicios en segundo plano |
| `docker compose down` | Detiene todos los servicios |
| `docker compose exec laravel.test php artisan migrate` | Ejecuta nuevas migraciones |
| `docker compose exec laravel.test php artisan migrate:rollback` | Deshace la última migración |
| `docker compose exec laravel.test php artisan db:seed` | Carga datos iniciales |
| `docker compose exec laravel.test php artisan tinker` | Consola interactiva de PHP |
| `docker compose logs laravel.test` | Ver los logs de la aplicación |
| `docker compose logs pgsql` | Ver los logs de PostgreSQL |

---

## Estructura de carpetas relevante

```
proyecto-crianza/
├── backend/                  ← Código del backend Laravel
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/  ← Controladores de la API
│   │   │   └── Middleware/   ← Filtros de seguridad HTTP
│   │   ├── Models/           ← Modelos de datos (User, Institution, Child…)
│   │   └── Policies/         ← Reglas de autorización por modelo
│   ├── config/               ← Configuraciones (cors, permission, etc.)
│   ├── database/
│   │   ├── migrations/       ← Definición de las tablas de la base de datos
│   │   └── seeders/          ← Datos iniciales (roles, permisos, admin)
│   ├── docs/                 ← Esta documentación
│   ├── routes/
│   │   └── api.php           ← Definición de los endpoints de la API
│   └── .env                  ← Variables de entorno (NO subir a git)
├── frontend/                 ← Frontend React + Vite (panel de pruebas)
│   ├── src/
│   │   ├── api/              ← Cliente HTTP (axios configurado)
│   │   ├── components/       ← Componentes reutilizables (Layout, tarjetas)
│   │   ├── context/          ← Estado global (AuthContext)
│   │   └── pages/            ← Páginas de la aplicación
│   ├── index.html
│   ├── vite.config.js
│   └── package.json
└── "Sistema de Apoyo a la Crianza.md"  ← Documento de visión del proyecto
```

---

## Cómo levantar el frontend de pruebas

El frontend es una aplicación React + Vite independiente del backend.

### Paso 1 — Instalar dependencias del frontend

Desde la carpeta `frontend/`:

```bash
npm install
```

### Paso 2 — Levantar el servidor de desarrollo

```bash
npm run dev
```

Abre el navegador en `http://localhost:5173`.

> El frontend incluye un proxy que redirige todas las llamadas a `/api/*` hacia el backend en `localhost:80` (Docker). No hace falta configurar nada más en desarrollo.

### Paso 3 — Iniciar sesión

Usar las credenciales del seeder:
- Email: `admin@crianza.local`
- Contraseña: `Crianza2026!Admin#`

> **Importante:** el backend Docker debe estar corriendo antes de abrir el frontend.

---

## Puertos en uso

| Servicio | Puerto local | Descripción |
|---|---|---|
| nginx (punto de entrada) | http://localhost:8080 | Enruta al frontend y al backend |
| API Laravel | http://localhost:80 | Acceso directo al backend (sin nginx) |
| Vite / React | http://localhost:5173 | Solo si corrés Vite en el host (fuera de Docker) |
| PostgreSQL | localhost:5432 | Base de datos |
| Redis | localhost:6379 | Caché / colas |

> Para acceder desde internet usá el Cloudflare Tunnel — ver [cloudflare-tunnel.md](cloudflare-tunnel.md).

---

## Endpoints disponibles actualmente

Una vez levantado el proyecto, los endpoints funcionales son:

**Sesión**

| Endpoint | Descripción |
|---|---|
| `POST   /api/v1/login` | Iniciar sesión |
| `POST   /api/v1/logout` | Cerrar sesión |
| `GET    /api/v1/me` | Ver mi perfil completo |

**Instituciones** (solo admin puede crear/modificar/desactivar)

| Endpoint | Descripción |
|---|---|
| `GET    /api/v1/institutions` | Listar instituciones |
| `POST   /api/v1/institutions` | Crear institución |
| `GET    /api/v1/institutions/{id}` | Ver detalle |
| `PATCH  /api/v1/institutions/{id}` | Modificar |
| `DELETE /api/v1/institutions/{id}` | Desactivar |

**Usuarios**

| Endpoint | Descripción |
|---|---|
| `GET    /api/v1/users` | Listar usuarios |
| `POST   /api/v1/users` | Crear usuario |
| `GET    /api/v1/users/{id}` | Ver perfil |
| `PATCH  /api/v1/users/{id}` | Modificar |
| `DELETE /api/v1/users/{id}` | Desactivar |

**Niños** (filtrado automático por tipo de institución)

| Endpoint | ¿Quién puede usarlo? |
|---|---|
| `GET    /api/v1/children` | Admin ve todos; cada institución ve los suyos |
| `POST   /api/v1/children` | Admin, institución y representante |
| `GET    /api/v1/children/{id}` | Ídem — el DNI solo lo ve el admin |
| `PATCH  /api/v1/children/{id}` | Admin e institución (si el niño está en la institución) |
| `DELETE /api/v1/children/{id}` | Solo admin |

**Registro educativo** (solo instituciones de tipo `educacion` y admin)

| Endpoint | Descripción |
|---|---|
| `GET    /api/v1/children/{id}/education-record` | Ver el registro educativo |
| `POST   /api/v1/children/{id}/education-record` | Crear registro educativo |
| `PATCH  /api/v1/children/{id}/education-record` | Modificar |
| `DELETE /api/v1/children/{id}/education-record` | Desactivar (solo admin) |

**Registro de salud** (solo instituciones de tipo `salud` y admin)

| Endpoint | Descripción |
|---|---|
| `GET    /api/v1/children/{id}/health-record` | Ver el registro de salud |
| `POST   /api/v1/children/{id}/health-record` | Crear registro de salud |
| `PATCH  /api/v1/children/{id}/health-record` | Modificar |
| `DELETE /api/v1/children/{id}/health-record` | Desactivar (solo admin) |

Ver referencia completa con ejemplos en [api-endpoints.md](api-endpoints.md).
