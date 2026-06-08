# Medidas de Seguridad Implementadas

Este documento explica las protecciones de seguridad del sistema en lenguaje accesible. Dado que el sistema maneja datos muy sensibles de menores de edad, se implementaron múltiples capas de protección.

---

## ¿Por qué tanta seguridad?

Los datos de este sistema incluyen información personal de niños y familias vulnerables: documentos de identidad, fechas de nacimiento, situación de salud, escolaridad y contexto social. Una filtración o acceso no autorizado tendría consecuencias graves. Por eso se siguió el principio de **defensa en profundidad**: si una capa de seguridad falla, hay otra detrás que la respalda.

---

## Capa 1 — Autenticación (¿Quién eres?)

### Tokens de acceso temporales
Para usar la API, cada usuario debe iniciar sesión y recibe un **token** (una clave larga y aleatoria). Ese token expira automáticamente después de **8 horas**. Si alguien roba el token, solo puede usarlo un tiempo limitado.

### Protección contra contraseñas débiles
Las contraseñas se guardan usando un algoritmo de cifrado reforzado (bcrypt con 14 rondas, más fuerte que el estándar). Esto hace que descifrar una contraseña robada sea extremadamente lento.

### Bloqueo por intentos fallidos
Si alguien intenta adivinar la contraseña de un usuario y falla **5 veces seguidas**, la cuenta queda bloqueada durante **15 minutos**. Esto impide los ataques automatizados de fuerza bruta.

### Protección contra ataques de tiempo
Cuando alguien intenta iniciar sesión, el sistema siempre realiza la verificación completa de contraseña, aunque el usuario no exista. Esto evita que un atacante descubra qué emails están registrados midiendo cuánto tarda la respuesta.

### Sesiones cifradas
Las sesiones de usuario están completamente cifradas. Si alguien intercepta la comunicación, no puede leer el contenido.

---

## Capa 2 — Autorización (¿Qué puedes hacer?)

### Roles y permisos granulares
Cada acción en el sistema (ver, crear, editar, eliminar) requiere un permiso específico. Si un usuario no tiene ese permiso, el sistema lo rechaza antes de llegar a la base de datos.

### Políticas de autorización por modelo
Cada tipo de dato (familia, persona, control médico, etc.) tiene su propio conjunto de reglas de acceso. Antes de mostrar o modificar cualquier registro, el sistema verifica:
1. ¿Tiene el usuario el permiso necesario?
2. ¿El tipo de institución del usuario le permite acceder a este tipo de dato?
3. ¿El registro pertenece a la institución del usuario?

### Restricción por tipo de institución
Un usuario de una institución de salud **nunca** puede ver registros de educación o desarrollo social, y viceversa. Esta restricción ocurre tanto en la aplicación como en la base de datos.

---

## Capa 3 — Protección contra IDOR (Acceso Directo a Objetos)

### ¿Qué es un ataque IDOR?
Es cuando un atacante cambia un número en la URL para acceder a datos que no le corresponden. Por ejemplo, si la URL es `/familias/5`, el atacante prueba `/familias/6`, `/familias/7`, etc.

### Solución: IDs no predecibles (UUIDs)
Todos los registros del sistema usan **UUIDs** como identificadores (ej: `a3b4c5d6-e7f8-...`). Son cadenas largas y completamente aleatorias, imposibles de adivinar o enumerar. No hay "siguiente número" para probar.

### Doble verificación de pertenencia
Incluso si alguien consiguiera un UUID válido de otro registro, las reglas de autorización verifican que ese registro le pertenezca al usuario antes de mostrarlo.

---

## Capa 4 — Seguridad de la Base de Datos (Row-Level Security)

### ¿Qué es Row-Level Security (RLS)?
Es una función de PostgreSQL que actúa como un filtro a nivel de base de datos. Incluso si hubiera un error en la aplicación y alguien pudiera hacer una consulta directa a la base de datos, el motor de base de datos filtra automáticamente los resultados y solo devuelve las filas que el usuario tiene permitido ver.

### ¿Cómo funciona?
Cuando un usuario autenticado hace una solicitud, el sistema le comunica a la base de datos: "Esta consulta viene del usuario X, de la institución Y". La base de datos entonces aplica sus propias reglas para filtrar los resultados, independientemente de lo que pida la aplicación.

---

## Capa 5 — Cifrado de Datos Sensibles

### Datos cifrados en la base de datos
El **número de documento** (DNI/CUIL) y la **fecha de nacimiento** entre otros de cada persona están cifrados con una clave secreta antes de guardarse en la base de datos. Aunque alguien accediera directamente a la base de datos, estos campos aparecerían como texto ilegible.

### Sin registro de datos sensibles en el historial
El sistema registra automáticamente todos los cambios (auditoría), pero está configurado para **nunca incluir** el número de documento ni la fecha de nacimiento en esos registros. Así se evita que datos sensibles queden expuestos en los logs.

---

## Capa 6 — Protecciones de Red y Protocolo

### Cabeceras de seguridad HTTP
Todas las respuestas incluyen instrucciones especiales para el navegador del usuario:
- **No guardar el tipo de archivo incorrecto** (evita ciertos ataques con archivos)
- **No mostrar la página dentro de otras páginas** (evita el "clickjacking")
- **Solo comunicarse por HTTPS** en producción (datos cifrados en tránsito)
- **Política de contenido** que define exactamente de dónde puede cargar recursos el navegador

### Límite de solicitudes (Rate Limiting)
El endpoint de inicio de sesión solo acepta **10 intentos por minuto** por dirección IP. Si alguien supera ese límite, el sistema lo bloquea temporalmente.

### Sin información del servidor
Las respuestas no revelan qué tecnología usa el servidor (se eliminan los encabezados `X-Powered-By` y `Server`). Esto dificulta que un atacante adapte su ataque al sistema específico.

---

## Capa 7 — Auditoría Completa

Cada vez que un usuario crea, modifica o elimina un registro, el sistema guarda automáticamente:
- Quién lo hizo (usuario)
- Qué cambió (campo por campo)
- Cuándo ocurrió
- Desde qué institución

Esta auditoría no puede ser modificada ni eliminada por los usuarios regulares. Solo el administrador puede consultarla.

---

## Eliminación suave (Soft Delete)

Ningún dato de personas, familias o registros médicos/educativos se **elimina físicamente** de la base de datos. Al "eliminar" un registro, simplemente se marca como inactivo (se guarda la fecha de eliminación). Los datos siguen estando disponibles para administradores si fuera necesario recuperarlos o para cumplimiento legal.
