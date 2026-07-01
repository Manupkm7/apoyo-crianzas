# API Endpoints — Referencia

Este documento lista todos los endpoints disponibles en la API del backend.
La URL base es `http://[dominio]/api/v1/`.

**Autenticación:** Todos los endpoints (excepto `/login`) requieren el header:
```
Authorization: Bearer {token}
```
El token se obtiene al iniciar sesión y dura 8 horas.

---

## Autenticación

### Iniciar sesión
**POST** `/api/v1/login`

Autentica al usuario y devuelve un token de acceso.

**Body (JSON):**
```json
{
  "email": "usuario@ejemplo.com",
  "password": "contraseña"
}
```

**Respuesta exitosa (200):**
```json
{
  "token": "1|abc123...",
  "token_type": "Bearer",
  "user": {
    "id": "uuid",
    "name": "Nombre del usuario",
    "email": "usuario@ejemplo.com",
    "roles": ["institucion"],
    "permissions": ["familias.ver", "salud.ver", ...],
    "institution": { "id": "uuid", "name": "CAPS Norte", "type": "salud" }
  }
}
```

**Errores posibles:**
- `422` — Credenciales incorrectas
- `422` — Cuenta desactivada
- `422` — Cuenta bloqueada por intentos fallidos
- `429` — Demasiados intentos (más de 10 por minuto desde la misma IP)

---

### Cerrar sesión
**POST** `/api/v1/logout` *(requiere autenticación)*

Invalida el token actual. El usuario deberá iniciar sesión de nuevo.

**Respuesta exitosa (200):**
```json
{ "message": "Sesión cerrada correctamente." }
```

---

### Ver mi perfil
**GET** `/api/v1/me` *(requiere autenticación)*

Devuelve el perfil completo del usuario autenticado, incluyendo todos sus permisos.

---

## Instituciones

### Listar instituciones
**GET** `/api/v1/institutions` *(requiere autenticación)*

| Rol | Qué ve |
|---|---|
| Admin / Coordinador | Todas las instituciones |
| Responsable / Representante | Solo su propia institución |

**Respuesta (200):** Lista paginada de 20 instituciones por página.

---

### Crear institución
**POST** `/api/v1/institutions` *(solo Admin)*

**Body (JSON):**
```json
{
  "name": "Centro de Salud Barrio Norte",
  "type": "salud",
  "address": "Av. Principal 1234",
  "phone": "0388-4000000",
  "is_active": true
}
```
Valores válidos para `type`: `salud`, `educacion`, `desarrollo_social`, `justicia`, `otro`

**Respuesta exitosa (201):** Los datos de la institución creada.

**Errores posibles:**
- `403` — Sin permiso (no es admin)
- `422` — Datos inválidos (nombre ya existe, tipo no válido, etc.)

---

### Ver institución
**GET** `/api/v1/institutions/{id}`

Devuelve el detalle de una institución, incluyendo la cantidad de usuarios activos.

---

### Modificar institución
**PATCH** `/api/v1/institutions/{id}` *(solo Admin)*

Se pueden enviar solo los campos a modificar (no es necesario enviarlos todos).

**Body (JSON):**
```json
{
  "name": "Nuevo nombre",
  "is_active": false
}
```

---

### Desactivar institución
**DELETE** `/api/v1/institutions/{id}` *(solo Admin)*

Marca la institución como inactiva. Los datos históricos vinculados se conservan.

**Respuesta (200):**
```json
{ "message": "Institución desactivada correctamente. Los registros históricos se conservan." }
```

---

## Usuarios

### Listar usuarios
**GET** `/api/v1/users` *(requiere autenticación con permiso de gestión)*

| Rol | Qué ve |
|---|---|
| Admin | Todos los usuarios del sistema |
| Responsable de institución | Solo los representantes de su institución |

---

### Crear usuario
**POST** `/api/v1/users` *(Admin o Responsable de institución)*

**Body (JSON):**
```json
{
  "name": "María García",
  "email": "mgarcia@salud.gov.ar",
  "password": "ContraseñaSegura123!",
  "role": "representante",
  "institution_id": "uuid-de-la-institución",
  "is_active": true
}
```

**Roles válidos según quién crea:**
- Admin puede crear: `admin`, `coordinador`, `institucion`, `representante`
- Responsable solo puede crear: `representante` (en su propia institución)

**Regla especial para rol `institucion`:**
Cada institución solo puede tener un responsable. Si ya tiene uno activo, el sistema responde:
```json
{
  "message": "Esta institución ya tiene un usuario responsable activo. Para asignar uno nuevo, primero desactive al responsable actual."
}
```

**Requisitos de contraseña:**
- Mínimo 12 caracteres
- Al menos una mayúscula y una minúscula
- Al menos un número
- Al menos un símbolo (!@#$%...)

---

### Ver usuario
**GET** `/api/v1/users/{id}`

Devuelve el perfil completo del usuario, incluyendo institución, rol y permisos.

---

### Modificar usuario
**PATCH** `/api/v1/users/{id}`

Se pueden enviar solo los campos a modificar.

**Campos que puede modificar cada tipo de usuario:**

| Campo | Admin | Responsable (sobre sus representantes) | Cualquier usuario (sobre sí mismo) |
|---|:---:|:---:|:---:|
| name | ✅ | ✅ | ✅ |
| email | ✅ | ✅ | ✅ |
| password | ✅ | ✅ | ✅ |
| is_active | ✅ | ✅ | ❌ |
| role | ✅ | ❌ | ❌ |
| institution_id | ✅ | ❌ | ❌ |

**Nota sobre cambio de rol a `institucion`:** Igual que en la creación, si la institución ya tiene un responsable activo, el sistema lo rechaza con un mensaje claro.

---

### Desactivar usuario
**DELETE** `/api/v1/users/{id}`

Marca el usuario como eliminado. El usuario no podrá iniciar sesión, pero sus registros históricos se conservan.

- **Nadie puede desactivarse a sí mismo.**
- Si el usuario era responsable de institución, se libera ese cargo automáticamente.

---

## Códigos de respuesta HTTP

| Código | Significado |
|---|---|
| `200` | Operación exitosa |
| `201` | Recurso creado exitosamente |
| `401` | No autenticado (token inválido o expirado) |
| `403` | Sin permiso para realizar esta acción |
| `404` | Recurso no encontrado |
| `422` | Datos inválidos (ver detalle en `errors`) |
| `429` | Demasiadas solicitudes (rate limit) |
| `500` | Error interno del servidor |

---

## Paginación

Los listados devuelven resultados paginados. La respuesta incluye:
```json
{
  "data": [...],
  "links": {
    "first": "...?page=1",
    "last": "...?page=5",
    "prev": null,
    "next": "...?page=2"
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 87
  }
}
```

Para navegar entre páginas, agregar `?page=2` a la URL.
