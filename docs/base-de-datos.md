# Estructura de la Base de Datos

## ¿Cómo se organiza la información?

La base de datos está organizada en tablas. Cada tabla guarda un tipo específico de información, similar a distintas hojas en una carpeta de legajo. Las tablas se relacionan entre sí a través de identificadores únicos.

---

## Tablas actuales del sistema

### `institutions` — Instituciones

Guarda el listado de todas las instituciones municipales habilitadas en el sistema.

| Campo | ¿Qué guarda? |
|---|---|
| id | Identificador único (código aleatorio, no un número secuencial) |
| name | Nombre de la institución |
| type | Tipo: salud / educacion / desarrollo_social / justicia / otro |
| address | Dirección física |
| phone | Teléfono de contacto |
| is_active | Si la institución está habilitada en el sistema |
| created_by / updated_by | Quién la creó o modificó por última vez |
| deleted_at | Fecha de baja (si está desactivada, no se borra del sistema) |

---

### `users` — Usuarios

Guarda los usuarios del sistema (administradores, coordinadores, responsables de institución y representantes).

| Campo | ¿Qué guarda? |
|---|---|
| id | Identificador único del usuario |
| institution_id | A qué institución pertenece |
| name | Nombre completo |
| email | Correo electrónico (se usa para iniciar sesión) |
| password | Contraseña cifrada (nunca se guarda en texto plano) |
| is_active | Si el usuario puede usar el sistema |
| is_institution_head | Si es el responsable principal de la institución (solo puede haber uno por institución) |
| last_login_at | Fecha y hora del último acceso |
| failed_login_attempts | Cantidad de intentos fallidos de contraseña |
| locked_until | Hasta cuándo está bloqueada la cuenta por intentos fallidos |

---

## Tablas de sistema (gestionadas automáticamente)

| Tabla | Propósito |
|---|---|
| `password_reset_tokens` | Tokens temporales para recuperar contraseñas olvidadas |
| `sessions` | Sesiones activas de usuarios |
| `personal_access_tokens` | Tokens de API generados al iniciar sesión (Sanctum) |
| `roles` / `permissions` / `model_has_roles` | Sistema de roles y permisos (gestionado por Spatie Permission) |
| `activity_log` | Historial de auditoría: quién cambió qué y cuándo |

---

## Tablas pendientes (a definir con el cliente)

Las siguientes tablas se agregarán una vez que se definan los modelos de datos del dominio:

- **Familias** — núcleo organizador de la información
- **Personas** — integrantes de cada familia
- **Controles de salud / Vacunas** — para instituciones de salud
- **Registros escolares** — para instituciones educativas
- **Programas sociales** — para instituciones de desarrollo social y justicia
- **Observaciones** — notas compartidas entre instituciones
- **Recursos** — biblioteca de documentos y materiales de apoyo

---

## Notas importantes sobre la base de datos

- **Ningún dato se elimina permanentemente.** Todos los registros tienen un campo `deleted_at`. Cuando se "elimina" algo, solo se marca con la fecha de borrado. Los datos siguen en la base de datos y pueden recuperarse.
- **Los IDs son aleatorios.** No son números 1, 2, 3... sino cadenas como `a1b2c3d4-e5f6-...`. Esto impide que alguien adivine IDs de otros registros.
