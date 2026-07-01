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
│   │   ├── Models/           ← Modelos de datos (User, Institution)
│   │   └── Policies/         ← Reglas de autorización por modelo
│   ├── config/               ← Configuraciones (cors, permission, etc.)
│   ├── database/
│   │   ├── migrations/       ← Definición de las tablas de la base de datos
│   │   └── seeders/          ← Datos iniciales (roles, permisos, admin)
│   ├── docs/                 ← Esta documentación
│   ├── routes/
│   │   └── api.php           ← Definición de los endpoints de la API
│   └── .env                  ← Variables de entorno (NO subir a git)
└── "Sistema de Apoyo a la Crianza.md"  ← Documento de visión del proyecto
```

---

## Puertos en uso

| Servicio | Puerto local |
|---|---|
| API Laravel | http://localhost (80) |
| PostgreSQL | localhost:5432 |
| Redis | localhost:6379 |

---

## Endpoints disponibles actualmente

Una vez levantado el proyecto, los endpoints funcionales son:

| Endpoint | Descripción |
|---|---|
| `POST   /api/v1/login` | Iniciar sesión |
| `POST   /api/v1/logout` | Cerrar sesión |
| `GET    /api/v1/me` | Ver mi perfil completo |
| `GET    /api/v1/institutions` | Listar instituciones |
| `POST   /api/v1/institutions` | Crear institución (solo admin) |
| `GET    /api/v1/institutions/{id}` | Ver detalle de una institución |
| `PATCH  /api/v1/institutions/{id}` | Modificar institución (solo admin) |
| `DELETE /api/v1/institutions/{id}` | Desactivar institución (solo admin) |
| `GET    /api/v1/users` | Listar usuarios |
| `POST   /api/v1/users` | Crear usuario (admin o responsable) |
| `GET    /api/v1/users/{id}` | Ver perfil de un usuario |
| `PATCH  /api/v1/users/{id}` | Modificar usuario |
| `DELETE /api/v1/users/{id}` | Desactivar usuario |

Ver referencia completa con ejemplos en [api-endpoints.md](api-endpoints.md).
