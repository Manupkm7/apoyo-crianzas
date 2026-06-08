# Roles y Permisos

## ¿Qué es un rol?

Un rol es una "categoría de usuario" que define qué puede hacer esa persona dentro del sistema. En lugar de configurar los permisos uno por uno para cada usuario, se les asigna un rol con un conjunto de permisos predefinidos.

## ¿Qué es un permiso?

Un permiso es una acción específica que un usuario puede o no realizar. Por ejemplo: "gestionar usuarios", "gestionar instituciones". Si un usuario no tiene el permiso, el sistema le niega la acción automáticamente, sin importar cómo intente hacerlo.

---

## Jerarquía de roles

```
Administrador (acceso total)
    └── Coordinador (visibilidad global, sin gestión)
            └── Institución (responsable único de su institución)
                    └── Representante (personal operativo)
```

---

## Descripción detallada de cada rol

### Administrador (`admin`)

Es el rol con mayor poder en el sistema. Generalmente lo tiene el equipo técnico municipal.

**Puede:**
- Crear, editar y desactivar instituciones
- Crear y gestionar cualquier usuario del sistema
- Ver datos de **todas** las instituciones
- Todo lo que pueden hacer los demás roles

---

### Coordinador (`coordinador`)

Tiene visibilidad global sobre el sistema, pero no puede administrar usuarios ni instituciones.

**Puede:**
- Ver todas las instituciones
- Ver reportes y estadísticas del sistema

**No puede:**
- Crear o gestionar usuarios
- Crear o modificar instituciones

---

### Institución (`institucion`)

Es el **responsable principal** de una institución. Solo puede haber **uno por institución** — el sistema no permite crear un segundo. Si se intenta, la base de datos lo rechaza automáticamente.

**Puede:**
- Ver su propia institución
- **Gestionar a sus representantes** (crear, editar, desactivar)

**No puede:**
- Ver datos de otras instituciones
- Gestionar otras instituciones o usuarios fuera de su ámbito

---

### Representante (`representante`)

Es el personal operativo de la institución. Puede ser uno o varios por institución.

**Puede:**
- Ver su propia institución

**No puede:**
- Gestionar usuarios
- Ver datos de otras instituciones

> A medida que se desarrollen los módulos de familias, salud, educación y social, se agregarán los permisos correspondientes a cada rol.

---

## Permisos implementados actualmente

| Permiso | Descripción | Quién lo tiene |
|---|---|---|
| `usuarios.gestionar` | Crear/editar/desactivar cualquier usuario del sistema | Admin |
| `instituciones.gestionar` | Crear y administrar instituciones | Admin |
| `representantes.gestionar` | Gestionar los representantes de la propia institución | Institución |
| `reportes.ver` | Ver reportes estadísticos y listado global de instituciones | Admin, Coordinador |

---

## ¿Qué permisos tiene cada rol?

| Permiso | Admin | Coordinador | Institución | Representante |
|---|:---:|:---:|:---:|:---:|
| `usuarios.gestionar` | ✅ | ❌ | ❌ | ❌ |
| `instituciones.gestionar` | ✅ | ❌ | ❌ | ❌ |
| `representantes.gestionar` | ✅ | ❌ | ✅ | ❌ |
| `reportes.ver` | ✅ | ✅ | ❌ | ❌ |
