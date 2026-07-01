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

### `children` — Niños

Registro base de cada menor ingresado al sistema. Es la tabla central que conecta todos los módulos: las instituciones educativas agregan registros educativos y las de salud agregan registros de salud, todos apuntando al mismo niño.

| Campo | ¿Qué guarda? |
|---|---|
| id | Identificador único del niño |
| first_name | Nombre |
| last_name | Apellido |
| birth_date | Fecha de nacimiento (para calcular la edad y disparar alertas) |
| dni | DNI **cifrado** — solo legible por la aplicación con la clave del servidor |
| dni_hash | Huella digital del DNI (SHA-256) para detectar niños duplicados sin exponer el DNI real |
| notes | Notas generales sobre el niño |
| created_by / updated_by | Quién lo registró o modificó por última vez |
| deleted_at | Fecha de baja lógica |

> **¿Por qué el DNI está cifrado?** Si alguien accediera directamente a la base de datos, vería un texto incomprensible en lugar del DNI real. Solo la aplicación con su clave secreta puede leer ese valor.

> **¿Por qué hay un dni_hash además del dni?** El DNI cifrado no permite buscar "¿ya existe este niño?", porque el cifrado transforma el mismo número en un texto diferente cada vez. El hash es una representación fija del DNI (siempre el mismo para el mismo número) que permite hacer esa comparación sin exponer el valor real.

---

### `education_records` — Registros educativos

Información escolar de un niño, cargada por una institución de tipo `educacion`. Cada niño puede tener un único registro por institución educativa.

| Campo | ¿Qué guarda? |
|---|---|
| id | Identificador único del registro |
| child_id | A qué niño corresponde |
| institution_id | Qué institución educativa cargó este registro |
| school_name | Nombre de la escuela a la que asiste |
| grade_or_year | Grado o sala que cursa (ej: "1er grado", "Sala de 4") |
| absences_count | Cantidad de inasistencias en el ciclo lectivo actual |
| is_enrolled | Si el niño está actualmente escolarizado. `No` = señal de alerta para el SAT |
| observations | Notas adicionales |
| created_by / updated_by | Quién cargó o modificó el registro |
| deleted_at | Fecha de baja lógica |

---

### `health_records` — Registros de salud

Información de salud de un niño, cargada por una institución de tipo `salud`. Cada niño puede tener un único registro por institución de salud.

| Campo | ¿Qué guarda? |
|---|---|
| id | Identificador único del registro |
| child_id | A qué niño corresponde |
| institution_id | Qué institución de salud cargó este registro |
| health_center_name | Centro de salud al que asiste (salita, hospital, CAPS, etc.) |
| healthy_checkup_current | Si tiene el control de niño sano al día. `No` = señal de alerta para el SAT |
| vaccines_current | Si las vacunas están al día. `No` = señal de alerta para el SAT |
| last_checkup_date | Fecha del último control (para detectar ausencia prolongada) |
| observations | Notas adicionales |
| created_by / updated_by | Quién cargó o modificó el registro |
| deleted_at | Fecha de baja lógica |

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

Las siguientes tablas se agregarán cuando se definan los modelos de datos del dominio:

- **Familias** — núcleo que agrupa a los miembros de un grupo familiar
- **Programas sociales** — para instituciones de desarrollo social
- **Observaciones compartidas** — notas entre instituciones sobre un mismo caso
- **Recursos** — biblioteca de documentos y materiales de apoyo

---

## Notas importantes sobre la base de datos

- **Ningún dato se elimina permanentemente.** Todos los registros tienen un campo `deleted_at`. Cuando se "elimina" algo, solo se marca con la fecha de borrado. Los datos siguen en la base de datos y pueden recuperarse.
- **Los IDs son aleatorios.** No son números 1, 2, 3... sino cadenas como `a1b2c3d4-e5f6-...`. Esto impide que alguien adivine IDs de otros registros.
- **Un niño, múltiples registros.** El mismo niño puede estar registrado en una institución de salud Y en una de educación. Ambas instituciones agregan su propia información, pero el niño tiene un único perfil base.
