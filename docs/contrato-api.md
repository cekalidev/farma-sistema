# Contrato de la API — farma-sistema

Referencia de integración para el frontend (React). Todo lo descrito aquí sale del código del
backend (rutas, Form Requests, Resources, Policies, excepciones de dominio y configuración). Si el
código y este documento discrepan, es un error del documento: avísese para corregirlo.

- **Versión de la API documentada:** `v0.13.0` (hasta RN-11, alertas, y listado de dispensaciones).
- **Fecha:** 2026-11-05.
- Las referencias `S-nn` son los supuestos de negocio aprobados; `RN-xx` son las reglas de negocio.

---

## 1. Generalidades

### 1.1 URL base

Todas las rutas cuelgan de `/api/v1`. En el frontend, la URL base debe ser configurable por
variable de entorno (p. ej. `VITE_API_BASE_URL=http://localhost:8000/api/v1`). En este documento
las rutas se escriben relativas a esa base (`POST /auth/login` = `POST {base}/auth/login`).

### 1.2 Autenticación

- Tokens **Bearer** de Sanctum. No hay sesión ni cookies: una cookie nunca autentica.
- Se obtiene con `POST /auth/login` y se envía en cada petición: `Authorization: Bearer <token>`.
- **Expiración:** 720 minutos (12 h, un turno; S-21), configurable con `SANCTUM_EXPIRATION`. El
  login devuelve `expira_en_minutos` (entero, o `null` si el servidor se configura sin expiración).
  No hay *refresh token*: al expirar, cualquier petición responde `401 NO_AUTENTICADO` y hay que
  volver a iniciar sesión.
- `POST /auth/logout` revoca **solo** el token con el que se hace la petición (otros dispositivos
  siguen activos).
- Un invitado nunca se redirige: sin token, token inválido o expirado → `401 NO_AUTENTICADO` en JSON.

### 1.3 Cabeceras

| Cabecera | Sentido | Uso |
|---|---|---|
| `Authorization: Bearer <token>` | petición | Obligatoria en todo salvo `POST /auth/login`. |
| `Accept: application/json` | petición | Recomendada. El servidor responde JSON siempre, incluso en errores. |
| `Content-Type: application/json` | petición | En todo POST/PUT con cuerpo. |
| `Idempotency-Key` | petición | **Obligatoria** solo en `POST /dispensaciones` (ver §4.4). |
| `X-Correlation-Id` / `X-Request-Id` | respuesta | Presentes en **toda** respuesta (también errores). Las genera el servidor (UUID, S-80). Muéstrese en la pantalla de error («Código de seguimiento») para soporte. |
| `X-Correlation-Id` / `X-Request-Id` | petición | Opcionales. Si el cliente envía uno válido (`[A-Za-z0-9._-]{8,64}`), solo queda en el log del servidor como dato informativo; **no** se devuelve ni sustituye al del servidor. |
| `Idempotent-Replayed: true` | respuesta | Solo en `POST /dispensaciones` cuando la respuesta es la reproducción de una anterior (§4.4). |
| `Retry-After` | respuesta | En `429` (segundos hasta poder reintentar) y en `409 CONFLICTO_CONCURRENCIA` (`1`). |
| `X-RateLimit-Limit` / `X-RateLimit-Remaining` | respuesta | Las añade el limitador de tasa de Laravel. |
| `Allow` | respuesta | En `405`, con los métodos admitidos. |

### 1.4 Envoltura de las respuestas

- Un recurso: `{"data": { … }}`. **Excepción:** `POST /auth/login` responde el objeto sin `data`.
- Lista **paginada**: `{"data": [ … ], "links": { … }, "meta": { … }}` (formato estándar de Laravel):

```json
{
  "data": [],
  "links": { "first": "…?page=1", "last": "…?page=3", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "links": [], "path": "…/api/v1/inventario",
            "per_page": 25, "to": 25, "total": 61 }
}
```

- Lista **no paginada** (`POST /pacientes/buscar`, `GET /pacientes/{id}/prescripciones`): `{"data": [ … ]}`.
- `204` sin cuerpo: `POST /auth/logout`.

### 1.5 Paginación

Parámetros de *query* en **todos** los listados paginados (`bodegas`, `productos`, `inventario`,
`kardex`, `dispensaciones`, `traslados`, `auditoria`, `alertas/*`; S-25, S-28, S-88, S-89):

| Parámetro | Tipo | Límites | Por defecto |
|---|---|---|---|
| `por_pagina` | entero | 1–100 | 25 |
| `page` | entero | 1–1.000.000 (S-28) | 1 |

Fuera de rango o no numérico → `422 VALIDACION`. Una página válida pero vacía → `200` con `data: []`.
Los enlaces de `links` conservan los filtros de la *query*.

### 1.6 Formatos

- **Ids:** enteros positivos (en JSON, números). En la URL, un id que no sea `[1-9][0-9]{0,17}`
  responde `404 NO_ENCONTRADO`. En cuerpo y *query*, los ids aceptan número o texto numérico
  (`5` o `"5"`); las **cantidades** exigen entero JSON estricto (`5`; se rechazan `"5"`, `5.0`, `true`; S-07).
- **Fechas de negocio** (`fecha_vencimiento`, `fecha_emision`, filtros `desde`/`hasta`):
  `Y-m-d`, rango admitido 1900-01-01 a 2099-12-31 (S-29). «Hoy» se calcula en la zona de negocio
  **America/Bogota** (S-01, S-05).
- **Marcas de tiempo** (`created_at`, `aprobado_en`, …): ISO 8601 con desfase, ya en la hora de
  **America/Bogota** (`2026-11-05T09:32:10-05:00`; Colombia no tiene horario de verano). Si se
  comparan o se ordenan, háganlo como instantes (`Date`), no como texto.
- **Estados y tipos:** cadenas en MAYÚSCULAS (`CONFIRMADA`, `EN_TRANSITO`, `AJUSTE`…). Roles en
  minúsculas (`regente_farmacia`).
- **Textos libres con motivo** (`motivo` de ajuste, rechazo, anulación y discrepancia): 10 a 500
  caracteres (400 en discrepancias) tras recortar espacios de los extremos; sin saltos de línea ni
  caracteres de control o invisibles (S-33).

### 1.7 Límites de tasa

| Limitador | Ámbito | Límite por defecto | Variable |
|---|---|---|---|
| `login` | correo + IP | 5 / min | `FARMA_LOGIN_INTENTOS_POR_MINUTO` |
| `login` | correo (cualquier IP) | 10 / min | `FARMA_LOGIN_INTENTOS_POR_CORREO_POR_MINUTO` |
| `login` | IP (cualquier correo) | 20 / min | `FARMA_LOGIN_INTENTOS_POR_IP_POR_MINUTO` |
| `api` | usuario autenticado | 60 / min | `FARMA_API_PETICIONES_POR_MINUTO` |

Los tres de login se aplican a la vez. Superar cualquiera → `429 DEMASIADAS_SOLICITUDES` con
`Retry-After`. UX: deshabilitar el botón y mostrar una cuenta atrás con `Retry-After`; no reintentar
automáticamente.

### 1.8 Orden general de comprobación

Para cualquier ruta autenticada: **401** (token) → **429** (tasa) → **404** (recurso de la URL) →
**403** (rol / Policy) → **400** (cabecera `Idempotency-Key`, solo dispensación) → **422** (forma del
cuerpo, `VALIDACION`; S-27) → errores de negocio (422 / 409 / 403 según el caso). Cada endpoint
detalla su orden en §4.

### 1.9 Sondas de despliegue, CORS y cabeceras de seguridad (Parte D)

Sondas en la **raíz** (no cuelgan de `/api/v1`), sin autenticación, sin sesión y sin límite de tasa.
No usan la envoltura de error del §2: son para el orquestador (Render, compose), no para el frontend.

| Ruta | Uso | Respuesta |
|---|---|---|
| `GET /health` | *Liveness*: el proceso responde. No consulta la BD. | `200 {"status":"ok"}` |
| `GET /ready` | *Readiness*: `SELECT 1` en la BD y ninguna migración pendiente. | `200 {"status":"ready","checks":{"database":"ok","migrations":"ok"}}` · `503 {"status":"unavailable","checks":{"database":"fail"\|"ok","migrations":"unknown"\|"pending"}}` |
| `GET /up` | Sonda estándar de Laravel (se conserva). | `200` |

**CORS** (solo `api/*`): orígenes permitidos en `CORS_ALLOWED_ORIGINS` (lista separada por comas;
vacía por defecto → ningún origen cruzado). Sin credenciales (`Access-Control-Allow-Credentials`
ausente): el token va en `Authorization`, nunca en cookies. Cabeceras de petición permitidas:
`Accept`, `Authorization`, `Content-Type`, `Idempotency-Key`, `X-Correlation-Id`, `X-Request-Id`.
Expuestas al navegador: `X-Correlation-Id`, `X-Request-Id`, `Idempotent-Replayed`, `Retry-After`,
`X-RateLimit-Limit`, `X-RateLimit-Remaining`. Preflight `OPTIONS` → `204`, caché 600 s. Si el
frontend se sirve con su proxy `/api/` en el mismo origen (compose), no necesita CORS.

**Cabeceras de seguridad** en toda respuesta (también errores y sondas): `X-Content-Type-Options:
nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` y, solo si la petición llegó por
https (directo o `X-Forwarded-Proto: https` del proxy de confianza), `Strict-Transport-Security:
max-age=31536000; includeSubDomains`.

---

## 2. Formato de error y códigos

Todo error tiene la misma forma:

```json
{ "error": { "codigo": "STOCK_INSUFICIENTE", "mensaje": "No hay stock suficiente para la operación.", "detalles": {} } }
```

- `codigo`: estable, en MAYÚSCULAS. **El frontend decide por `codigo`, nunca por `mensaje`.**
- `mensaje`: texto en español del servidor; puede cambiar.
- `detalles`: siempre objeto (vacío `{}` si no hay). Nunca trae datos personales de pacientes, SQL ni trazas.

### 2.1 Validación (`VALIDACION`)

```json
{ "error": { "codigo": "VALIDACION", "mensaje": "Los datos enviados no son válidos.",
  "detalles": { "campos": { "items.0.cantidad": ["The items.0.cantidad field must be at least 1."],
                            "nota": ["Campo no permitido."] } } } }
```

`detalles.campos` es un mapa `ruta.del.campo` → lista de mensajes (notación con puntos e índices
desde 0). Úsese para marcar el campo del formulario. Los mensajes por defecto de Laravel salen en
**inglés** (locale `en`); solo los personalizados están en español. Recomendación: mostrar un
texto propio por campo y usar el del servidor solo como respaldo.

Muchos endpoints rechazan **claves desconocidas** en el cuerpo (`"Campo no permitido."`, S-51):
envíese solo lo documentado.

### 2.2 Tabla de códigos

| Código | HTTP | Cuándo ocurre | `detalles` | Mensaje sugerido al usuario |
|---|---|---|---|---|
| `VALIDACION` | 422 | Forma del cuerpo/query inválida; también reglas que se reportan por campo: bodega inactiva (S-22, S-46), lote de otro producto, origen = destino, recepción incompleta. | `campos` | «Revise los campos marcados.» |
| `DATO_INVALIDO` | 422 | Red de seguridad: un valor enviado no se pudo convertir en la BD (no debería verse si se respeta el contrato). | — | «Uno de los valores enviados no es válido.» |
| `CREDENCIALES_INVALIDAS` | 401 | Login con correo inexistente o contraseña errónea (mismo error para ambos). | — | «Correo o contraseña incorrectos.» |
| `NO_AUTENTICADO` | 401 | Sin token, token inválido, revocado o expirado. | — | Redirigir al login: «Su sesión expiró. Inicie sesión de nuevo.» |
| `NO_AUTORIZADO` | 403 | El rol no tiene la habilidad, o la acción depende del usuario (p. ej. editar un traslado ajeno). | — | «No tiene permiso para realizar esta acción.» |
| `AUTORIZACION_MISMO_USUARIO` | 403 | Quien creó la dispensación intenta autorizarla o rechazarla (RN-05, S-06). | `dispensacion_id` | «Esta dispensación debe autorizarla otro regente.» |
| `SEGREGACION_FUNCIONES` | 403 | El solicitante del traslado intenta aprobarlo o resolver sus discrepancias (RN-08, S-71, S-75). | `traslado_id`, `accion` (`aprobar` \| `resolver_discrepancia`) | «Quien solicitó el traslado no puede aprobarlo ni resolver sus discrepancias.» |
| `NO_ENCONTRADO` | 404 | Ruta inexistente, id de URL mal formado o recurso inexistente. | — | «El registro no existe o fue eliminado.» |
| `METODO_NO_PERMITIDO` | 405 | Método HTTP no admitido en la ruta (p. ej. `PUT /kardex/1`). Cabecera `Allow`. | — | Error de programación; mensaje genérico. |
| `IDEMPOTENCY_KEY_REQUERIDA` | 400 | `POST /dispensaciones` sin `Idempotency-Key`, vacía, repetida, > 255 caracteres o con caracteres fuera de ASCII imprimible (S-49). | — | Error de programación; mensaje genérico. |
| `IDEMPOTENCY_KEY_REUTILIZADA` | 422 | La misma clave ya se usó (por el mismo usuario) con **otro cuerpo** (S-10, S-52). | — | «Esta solicitud ya se envió con otros datos. Vuelva a intentarlo.» (generar clave nueva) |
| `PRESCRIPCION_NO_CORRESPONDE` | 422 | La prescripción no es del paciente, o algún ítem no es de la prescripción (S-45). | `prescripcion_id` + `paciente_id`, **o** `prescripcion_id` + `prescripcion_item_ids` | «La prescripción no corresponde al paciente seleccionado.» |
| `PRESCRIPCION_NO_VIGENTE` | 422 | Prescripción `ANULADA` o vencida (vence hoy → vigente; S-05). | `prescripcion_id`, `estado`, `fecha_vencimiento` | «La prescripción está anulada o vencida.» |
| `CANTIDAD_EXCEDE_PRESCRITO` | 422 | Un ítem pide más de lo pendiente (prescrito − ya dispensado o pendiente de autorización; S-55). | `prescripcion_item_id`, `pendiente`, `solicitado` | «Solo quedan {pendiente} unidades por dispensar de este medicamento.» |
| `LOTE_VENCIDO` | 422 | Se nombra un lote vencido (vence hoy o antes) en una entrada o un traslado (S-01, S-02). | `lote_id` (null si el lote aún no existe), `lote_codigo`, `producto_id`, `fecha_vencimiento` | «El lote {lote_codigo} está vencido ({fecha_vencimiento}).» |
| `LOTE_NO_COINCIDE` | 422 | Entrada con código de lote existente pero otra fecha de vencimiento (S-23, S-26). | `lote_id`, `lote_codigo`, `fecha_registrada`, `fecha_enviada` | «El lote {lote_codigo} ya existe con vencimiento {fecha_registrada}.» |
| `CANTIDAD_EXCEDE_MAXIMO` | 422 | El saldo de la existencia superaría 2.147.483.647 (entrada, ajuste, recepción o resolución; S-31). | `saldo_actual`, `cantidad`, `maximo` | «La cantidad supera el máximo admitido.» |
| `CANTIDAD_RECIBIDA_EXCEDE` | 422 | En la recepción, un ítem declara más de lo despachado. | `traslado_item_id`, `despachada`, `recibida` | «No puede recibir más de lo despachado ({despachada}).» |
| `STOCK_INSUFICIENTE` | 409 | No hay stock vigente suficiente (dispensación, previsualización, autorización), el ajuste dejaría saldo negativo, o el origen no tiene stock del lote al despachar. | Dispensación: `producto_id`, `bodega_id`, `prescripcion_item_id`, `solicitado`, `disponible`, `vencido_no_dispensable` (S-42). Ajuste: `existencia_id`, `disponible`, `solicitado`. Despacho: `traslado_item_id`, `lote_id`, `existencia_id` (puede ser null), `disponible`, `solicitado`. | «Stock insuficiente: hay {disponible} disponibles (y {vencido_no_dispensable} vencidas no dispensables).» |
| `TRANSICION_INVALIDA` | 409 | La acción no es válida en el estado actual (dispensación no pendiente, transición de traslado no permitida, discrepancia ya resuelta). | `dispensacion_id` \| `traslado_id` \| `discrepancia_id`, `estado` (actual), `accion` | «El registro cambió de estado ({estado}). Recargue la pantalla.» (y recargar) |
| `CONFLICTO_CONCURRENCIA` | 409 | Choque con otra operación simultánea detectado por la BD; no se aplicó nada (S-48). Cabecera `Retry-After: 1`. | `reintentable: true` | Reintentar automáticamente una vez tras `Retry-After`; si persiste: «Otra operación se cruzó con la suya. Intente de nuevo.» |
| `CODIGO_DUPLICADO` | 409 | Alta de bodega/producto que choca con un valor único creado a la vez por otra petición (la validación normal da `VALIDACION`). | `campo` (`codigo` \| `nombre`) | «Ya existe un registro con ese {campo}.» |
| `KARDEX_INMUTABLE` | 409 | Intento de modificar un movimiento de kardex. Ninguna ruta lo provoca (S-39). | `operacion`, `movimiento_id` (o vacío) | Mensaje genérico. |
| `BITACORA_INMUTABLE` | 409 | Intento de modificar la bitácora. Ninguna ruta lo provoca (S-82). | `operacion`, `registro_id` (o vacío) | Mensaje genérico. |
| `DEMASIADAS_SOLICITUDES` | 429 | Límite de tasa superado (§1.7). Cabecera `Retry-After`. | — | «Demasiados intentos. Espere {Retry-After} s.» |
| `ERROR_HTTP` | variable | Cualquier otra excepción HTTP no clasificada (genérico). | — | Mensaje genérico. |
| `ERROR_INTERNO` | 500 | Error no previsto. | — | «Ocurrió un error. Código de seguimiento: {X-Correlation-Id}.» |

---

## 3. Roles y permisos

Roles: `auxiliar_farmacia`, `regente_farmacia`, `medico`, `auditor`, `admin`. `GET /auth/me` y
el login devuelven el `rol` y la lista de `habilidades`; úsense para mostrar u ocultar acciones.

### 3.1 Habilidades por rol (`Rol::habilidades()`)

| Habilidad | aux | regente | médico | auditor | admin |
|---|:-:|:-:|:-:|:-:|:-:|
| `dispensacion.crear` | ✔ | ✔ | | | |
| `dispensacion.autorizar` | | ✔ | | | |
| `inventario.consultar` | ✔ | ✔ | | ✔ | |
| `inventario.registrar` | | ✔ | | | |
| `traslado.operar` | ✔ | ✔ | | | |
| `traslado.aprobar` | | ✔ | | | |
| `traslado.anular` | ✔ | ✔ | | | |
| `traslado.consultar` | ✔ | ✔ | | ✔ | |
| `prescripcion.crear` | | | ✔ | | |
| `paciente.consultar` | ✔ | ✔ | ✔ | ✔ | |
| `auditoria.consultar` | | | | ✔ | ✔ |
| `bodega.consultar` | ✔ | ✔ | | ✔ | ✔ |
| `producto.consultar` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `catalogo.gestionar` | | | | | ✔ |

### 3.2 Matriz endpoint × rol

✔ = permitido; ✔\* = permitido con condición; — = `403`.

| Endpoint | aux | regente | médico | auditor | admin |
|---|:-:|:-:|:-:|:-:|:-:|
| `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `GET /bodegas`, `GET /bodegas/{id}` | ✔ | ✔ | — | ✔ | ✔ |
| `POST /bodegas` | — | — | — | — | ✔ |
| `GET /productos`, `GET /productos/{id}` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /productos` | — | — | — | — | ✔ |
| `GET /inventario` | ✔ | ✔ | — | ✔ | — |
| `POST /inventario/entradas`, `POST /inventario/ajustes` | — | ✔ | — | — | — |
| `GET /kardex`, `GET /kardex/{id}` | ✔ | ✔ | — | ✔ | — |
| `GET /alertas/vencimientos`, `GET /alertas/stock-minimo` | ✔ | ✔ | — | ✔ | — |
| `POST /dispensaciones/previsualizar`, `POST /dispensaciones` | ✔ | ✔ | — | — | — |
| `GET /dispensaciones` | ✔ | ✔ | — | ✔ | — |
| `GET /dispensaciones/{id}` | ✔ | ✔ | — | ✔ (paciente enmascarado) | — |
| `POST /dispensaciones/{id}/autorizar`, `…/rechazar` | — | ✔\* (no el creador) | — | — | — |
| `GET /traslados`, `GET /traslados/{id}` | ✔ | ✔ | — | ✔ | — |
| `POST /traslados` | ✔ | ✔ | — | — | — |
| `PUT /traslados/{id}/items`, `POST /traslados/{id}/solicitar` | ✔\* (creador) | ✔\* (creador) | — | — | — |
| `POST /traslados/{id}/aprobar` | — | ✔\* (no el solicitante) | — | — | — |
| `POST /traslados/{id}/despachar`, `…/recibir` | ✔ | ✔ | — | — | — |
| `POST /traslados/{id}/anular` | ✔\* (creador) | ✔ (cualquiera) | — | — | — |
| `POST /discrepancias/{id}/resolver` | — | ✔\* (no el solicitante del traslado) | — | — | — |
| `POST /pacientes/buscar`, `GET /pacientes/{id}`, `GET /pacientes/{id}/prescripciones` | ✔ | ✔ | ✔ | ✔ (enmascarado) | — |
| `GET /auditoria` | — | — | — | ✔ | ✔ |

Notas:

- Las condiciones ✔\* las aplica la Policy: el creador que autoriza su dispensación recibe
  `403 AUTORIZACION_MISMO_USUARIO`; el solicitante que aprueba o resuelve recibe
  `403 SEGREGACION_FUNCIONES`; el resto de condiciones no cumplidas da `403 NO_AUTORIZADO`.
- **Enmascarado del auditor (RN-10, S-76, S-79):** en todo objeto `paciente` el auditor recibe
  `enmascarado: true` y: `numero_documento` `******` + 4 últimos (≤ 4 caracteres → `******`);
  `nombres`/`apellidos` inicial + `***` por palabra (`J*** P***`); `fecha_nacimiento` solo el año
  como texto (`"1985"`); `telefono` `*******` + 3 últimos; `correo` `j***@***`; `direccion` `null`.
  `id` y `tipo_documento` nunca se enmascaran. En prescripciones, `observaciones` e `indicaciones`
  salen `null`. El frontend debe mostrar los valores tal cual y un distintivo «Datos enmascarados»
  cuando `enmascarado` sea `true`; no intentar «desenmascarar» ni validar formato.
- **Bitácora (RN-10):** cada consulta permitida de pacientes y de `GET /dispensaciones/{id}` deja
  rastro, y cada `403` en esas rutas queda como `DENEGADO`. No hagan consultas «de precarga» de
  pacientes que el usuario no pidió. `GET /dispensaciones` (listado) no expone pacientes y **no**
  deja rastro (ni `PERMITIDO` ni `DENEGADO`, S-89).

---

## 4. Endpoints por módulo

Convenciones de cada ficha: **Quién** (roles), **Cuerpo** o **Query**, **Respuesta**, **Errores** en
orden de precedencia (se omiten `401`, `429` y `404` de id de URL, comunes a todas las rutas
autenticadas con id) y **UX**.

### 4.1 Auth

#### `POST /auth/login`

Sin token. Limitador `login`.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `email` | string (correo) | sí | ≤ 255; sin distinción de mayúsculas |
| `password` | string | sí | ≤ 255 |
| `dispositivo` | string | no | ≤ 100; nombre del token (por defecto `api`) |

**200** (sin envoltura `data`):

```json
{ "token": "12|AbCdEf…", "token_type": "Bearer", "expira_en_minutos": 720, "rol": "regente_farmacia",
  "usuario": { "id": 2, "nombre": "Regente Uno", "email": "regente@farma.test", "rol": "regente_farmacia",
               "habilidades": ["dispensacion.crear", "dispensacion.autorizar", "inventario.consultar"] } }
```

**Errores:** `429 DEMASIADAS_SOLICITUDES` → `422 VALIDACION` → `401 CREDENCIALES_INVALIDAS`.

**UX:** guardar el token en memoria (o `sessionStorage`), nunca en la URL. Programar el cierre de
sesión con `expira_en_minutos`. Enviar `dispositivo` descriptivo (p. ej. `web-chrome`).

#### `POST /auth/logout`

Todos los roles. Sin cuerpo. **204** sin contenido. Revoca solo el token actual. UX: borrar el token
local aunque la respuesta sea `401`.

#### `GET /auth/me`

Todos los roles. **200** `{"data": UsuarioResource}` (misma forma que `usuario` del login). Úsese
al recargar la aplicación para recuperar rol y habilidades.

### 4.2 Catálogo

#### `GET /bodegas` · `GET /bodegas/{id}`

**Quién:** aux, regente, auditor, admin. Listado paginado (§1.5: `por_pagina` 1–100, 25 por defecto;
`page` ≤ 1.000.000; S-88), orden por `nombre` y luego `id`; incluye inactivas.
**Errores (listado):** `403` → `422 VALIDACION` (`por_pagina` o `page` fuera de rango).

```json
{ "data": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central", "activa": true } }
```

#### `POST /bodegas`

**Quién:** admin.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `codigo` | string | sí | ≤ 20, único |
| `nombre` | string | sí | ≤ 120, único |
| `activa` | boolean | no | por defecto `true` |

**201** `{"data": BodegaResource}`. **Errores:** `403` → `422 VALIDACION` (incluye duplicado) → `409 CODIGO_DUPLICADO` (carrera).

#### `GET /productos` · `GET /productos/{id}`

**Quién:** todos los roles. Listado paginado (§1.5: `por_pagina` 1–100, 25 por defecto;
`page` ≤ 1.000.000; S-88), orden por `nombre` y luego `id`; incluye inactivos.
**Errores (listado):** `403` → `422 VALIDACION` (`por_pagina` o `page` fuera de rango).

```json
{ "data": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg", "presentacion": "Tableta",
            "unidad_medida": "tableta", "control_especial": false, "activo": true } }
```

#### `POST /productos`

**Quién:** admin.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `codigo` | string | sí | ≤ 30, único |
| `nombre` | string | sí | ≤ 150 |
| `presentacion` | string \| null | no | ≤ 120 |
| `unidad_medida` | string | sí | ≤ 30 |
| `control_especial` | boolean | sí | |
| `activo` | boolean | no | por defecto `true` |

**201** `{"data": ProductoResource}`. **Errores:** `403` → `422 VALIDACION` → `409 CODIGO_DUPLICADO`.

### 4.3 Inventario y kardex

#### `GET /inventario`

**Quién:** aux, regente, auditor. Paginado, orden por id de existencia. Incluye existencias con
cantidad 0 (S-25). Un filtro con id inexistente → `200` con lista vacía.

Query: `bodega_id`, `producto_id`, `lote_id` (enteros), `por_pagina`, `page`.

Fila (`ExistenciaResource`):

```json
{ "id": 15, "bodega_id": 1, "producto_id": 7, "lote_id": 30,
  "bodega": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central" },
  "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg" },
  "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" },
  "fecha_vencimiento": "2027-03-31", "cantidad": 120, "vencido": false }
```

**UX:** `vencido: true` = vence hoy o antes (S-01): resaltar y explicar que no es dispensable.

#### `POST /inventario/entradas`

**Quién:** regente (S-17). Registra una compra (kardex `ENTRADA`).

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `bodega_id` | id | sí | bodega existente y **activa** (S-22) |
| `producto_id` | id | sí | producto existente y **activo** |
| `cantidad` | entero estricto | sí | 1–1.000.000 (S-24) |
| `lote_id` | id | uno de los dos | lote existente **del producto**; excluye `lote` |
| `lote` | objeto `{codigo, fecha_vencimiento}` | uno de los dos | solo esas claves |
| `lote.codigo` | string | con `lote` | ≤ 50; sin espacios en los extremos ni caracteres invisibles (S-30) |
| `lote.fecha_vencimiento` | fecha `Y-m-d` | con `lote` | 1900-01-01 a 2099-12-31 |

Con `lote` por código: si el lote ya existe para el producto se reutiliza (debe coincidir la fecha);
si no, se crea.

**201** `{"data": …}`: la fila de inventario resultante más el bloque `entrada`:

```json
{ "data": { "id": 15, "bodega_id": 1, "producto_id": 7, "lote_id": 30, "bodega": { "…": "…" },
  "producto": { "…": "…" }, "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" },
  "fecha_vencimiento": "2027-03-31", "cantidad": 170, "vencido": false,
  "entrada": { "cantidad": 50, "saldo_anterior": 120, "saldo_posterior": 170, "lote_creado": false, "movimiento_id": 901 } } }
```

**Errores:** `403` → `422 VALIDACION` → `422 LOTE_NO_COINCIDE` → `422 LOTE_VENCIDO` → `422 CANTIDAD_EXCEDE_MAXIMO`.

**UX:** ofrecer «lote existente» (selector filtrado por producto) o «lote nuevo» (código + fecha);
no enviar ambos. Bloquear el botón mientras la petición está en curso (no es idempotente).

#### `POST /inventario/ajustes`

**Quién:** regente. Corrección manual (kardex `AJUSTE`); se permite sobre lotes vencidos y
bodegas/productos inactivos (S-34). Queda en la bitácora.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `existencia_id` | id | sí | existente |
| `cantidad` | entero estricto con signo | sí | −1.000.000 a 1.000.000, distinto de 0 (S-32) |
| `motivo` | string | sí | 10–500, una línea (S-33) |

**201** `{"data": MovimientoKardexResource}` (ver `GET /kardex/{id}`).

**Errores:** `403` → `422 VALIDACION` → `409 STOCK_INSUFICIENTE` (saldo negativo) → `422 CANTIDAD_EXCEDE_MAXIMO`.

**UX:** pedir confirmación mostrando saldo actual y saldo resultante. No es idempotente: evitar doble clic.

#### `GET /kardex`

**Quién:** aux, regente, auditor (S-37). Paginado, orden cronológico ascendente (`created_at`, `id`).

Query: `producto_id`, `lote_id`, `bodega_id`, `existencia_id`, `desde`, `hasta` (`Y-m-d`, inclusivos
por día de Bogotá, `hasta ≥ desde`; S-36), `por_pagina`, `page`.

#### `GET /kardex/{id}`

```json
{ "data": { "id": 901, "tipo": "ENTRADA", "existencia_id": 15, "bodega_id": 1, "producto_id": 7, "lote_id": 30,
  "bodega": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central" },
  "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg" },
  "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" },
  "cantidad": 50, "saldo_anterior": 120, "saldo_posterior": 170, "motivo": null,
  "usuario_id": 2, "usuario": { "id": 2, "nombre": "Regente Uno" },
  "documento": null, "created_at": "2026-11-05T09:32:10-05:00" } }
```

- `tipo`: `ENTRADA`, `SALIDA_DISPENSACION`, `SALIDA_TRASLADO`, `ENTRADA_TRASLADO`, `AJUSTE`.
- `cantidad` lleva signo (salidas negativas).
- `documento`: `null` (entradas y ajustes) o `{"tipo": "dispensacion" | "traslado", "id": n}` (S-35).
  UX: enlazar al detalle del documento.
- El kardex es inmutable: no existen PUT/PATCH/DELETE (`405 METODO_NO_PERMITIDO`).

### 4.4 Dispensación

Flujo: **previsualizar** (opcional) → **crear** (`Idempotency-Key`) → si algún producto es de control
especial queda `PENDIENTE_AUTORIZACION` → otro regente **autoriza** o **rechaza**.

#### Cuerpo común (`POST /dispensaciones/previsualizar` y `POST /dispensaciones`)

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `paciente_id` | id | sí | existente |
| `prescripcion_id` | id | sí | existente |
| `bodega_id` | id | sí | existente y **activa** (S-46: inactiva → `VALIDACION` en `bodega_id`) |
| `items` | lista | sí | 1–50 ítems (S-41) |
| `items[].prescripcion_item_id` | id | sí | existente; sin repetir |
| `items[].cantidad` | entero estricto | sí | 1–1.000.000 |

Solo se admiten esas claves en la raíz y en cada ítem (otra clave → `VALIDACION`).
El sistema elige los lotes por **FEFO** (vence primero, sale primero; empate por id de lote, S-03);
el cliente **no** envía lotes. La dispensación es todo o nada (S-04).

#### `POST /dispensaciones/previsualizar`

**Quién:** aux, regente. No exige `Idempotency-Key`. No bloquea ni escribe (S-43).

**200**:

```json
{ "data": { "bodega_id": 1, "orientativa": true,
  "items": [ { "prescripcion_item_id": 44, "producto_id": 7,
               "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg", "control_especial": false },
               "cantidad": 30 } ],
  "detalles": [ { "prescripcion_item_id": 44, "existencia_id": 15, "producto_id": 7, "lote_id": 30,
                  "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" }, "cantidad": 30 } ] } }
```

**Errores:** `403` → `422 VALIDACION` → `422 PRESCRIPCION_NO_CORRESPONDE` → `422 PRESCRIPCION_NO_VIGENTE`
→ `422 CANTIDAD_EXCEDE_PRESCRITO` → `409 STOCK_INSUFICIENTE` (S-54).

**UX:** mostrar los lotes asignados como **orientativos** (`orientativa: true`): el stock puede
cambiar antes de confirmar. Si algún producto tiene `control_especial: true`, avisar de que la
dispensación quedará pendiente de autorización de otro regente.

#### `POST /dispensaciones`

**Quién:** aux, regente. **Cabecera obligatoria `Idempotency-Key`** (RN-09): un único valor de 1 a
255 caracteres ASCII imprimibles, no solo espacios; distingue mayúsculas (S-49).

**201** `{"data": DispensacionResource}`:

```json
{ "data": { "id": 501, "estado": "CONFIRMADA", "paciente_id": 9, "prescripcion_id": 21, "bodega_id": 1,
  "dispensado_por_id": 3, "autorizado_por_id": null, "autorizado_en": null,
  "rechazado_por_id": null, "rechazado_en": null, "motivo_rechazo": null,
  "items": [ { "id": 801, "prescripcion_item_id": 44, "producto_id": 7,
               "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg", "control_especial": false },
               "cantidad": 30 } ],
  "detalles": [ { "id": 1201, "dispensacion_item_id": 801, "prescripcion_item_id": 44, "existencia_id": 15,
                  "producto_id": 7, "lote_id": 30,
                  "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" },
                  "cantidad": 30, "movimiento": { "id": 902, "tipo": "SALIDA_DISPENSACION", "cantidad": -30, "…": "…" } } ],
  "created_at": "2026-11-05T10:01:00-05:00" } }
```

- `estado`: `CONFIRMADA` (stock descontado) o `PENDIENTE_AUTORIZACION` (control especial: aún **sin**
  stock descontado, `detalles: []`; S-06). Se comprueba que el stock alcanza en ese momento, pero no se reserva (S-61).
- `detalles` se ordenan por ítem (orden de la petición) y FEFO dentro de cada ítem (S-47).
- En esta respuesta **no** viene el objeto `paciente` (sí en `GET /dispensaciones/{id}`).

**Errores:** `403` → `400 IDEMPOTENCY_KEY_REQUERIDA` → `422 VALIDACION` → (si la clave ya existe:
reproducción o `422 IDEMPOTENCY_KEY_REUTILIZADA`, sin revalidar nada más) → `422 VALIDACION`
(`bodega_id`: bodega inactiva) → `422 PRESCRIPCION_NO_CORRESPONDE` →
`422 PRESCRIPCION_NO_VIGENTE` → `422 CANTIDAD_EXCEDE_PRESCRITO` → `409 STOCK_INSUFICIENTE` (S-50, S-54).
También `409 CONFLICTO_CONCURRENCIA`.

**UX (importante):**

- Generar **un UUID v4 por intento de dispensación** (al abrir/confirmar el formulario) y
  **reutilizar el mismo** en cualquier reintento de red (timeout, `CONFLICTO_CONCURRENCIA`, `5xx`).
- Si el usuario **cambia** el cuerpo (cantidades, ítems, bodega), generar una **clave nueva**; reutilizar
  la anterior con otro cuerpo da `422 IDEMPOTENCY_KEY_REUTILIZADA`. Ojo: cambiar el **orden** de los
  ítems también cambia el cuerpo (S-51).
- Tras un error `4xx`/`409` la clave **no** queda consumida (S-11): reenviar el mismo cuerpo con la
  misma clave vuelve a evaluarse desde cero (útil si el stock se repuso). Regla práctica para el
  frontend: clave nueva siempre que cambie el cuerpo; la misma, solo para reintentos idénticos.
- Si la respuesta trae `Idempotent-Replayed: true`, es la respuesta original reproducida (status y
  cuerpo idénticos, aunque la dispensación haya cambiado después; S-52): mostrar «Esta dispensación ya
  se había registrado» y no duplicar nada en pantalla.
- Deshabilitar el botón «Dispensar» mientras la petición está en curso (doble clic).
- Con `estado = PENDIENTE_AUTORIZACION`: mensaje «Pendiente de autorización por otro regente» y no
  mostrar lotes entregados.
- `STOCK_INSUFICIENTE`: mostrar `disponible` y `vencido_no_dispensable` del ítem `prescripcion_item_id`.

#### `GET /dispensaciones`

**Quién:** aux, regente, auditor (los mismos que el detalle; médico y admin → `403`). Paginado (§1.5),
de la **más antigua a la más reciente** (`created_at` ASC, desempate por `id`; S-89).

| Query | Tipo | Límites |
|---|---|---|
| `estado` | texto | `CONFIRMADA`, `PENDIENTE_AUTORIZACION` o `RECHAZADA` (exacto, mayúsculas) |
| `bodega_id` | id | entero ≥ 1; inexistente → `200` con `data: []` |
| `por_pagina` | entero | 1–100 (25 por defecto) |
| `page` | entero | 1–1.000.000 |

Filtros combinables; sin filtros, todas. **200**:

```json
{ "data": [ { "id": 512, "estado": "PENDIENTE_AUTORIZACION", "paciente_id": 9, "prescripcion_id": 21,
    "bodega_id": 1, "dispensado_por_id": 3, "dispensado_por": { "id": 3, "nombre": "Auxiliar Uno" },
    "autorizado_por_id": null, "autorizado_en": null, "rechazado_por_id": null, "rechazado_en": null,
    "motivo_rechazo": null,
    "items": [ { "id": 820, "prescripcion_item_id": 45, "producto_id": 8,
                 "producto": { "id": 8, "codigo": "MED-008", "nombre": "Tramadol 50 mg", "control_especial": true },
                 "cantidad": 10 } ],
    "created_at": "2026-11-05T10:01:00-05:00" } ],
  "links": { "first": "…?estado=PENDIENTE_AUTORIZACION&page=1", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1, "…": "…" } }
```

- Cada fila es `DispensacionResource` **más** `dispensado_por` (`id`, `nombre`) y **sin** `paciente`
  (solo `paciente_id`) ni `detalles` (tramos FEFO y movimientos): para eso, `GET /dispensaciones/{id}`.
- No deja rastro en la bitácora (no expone datos del paciente); el `403` tampoco.

**Errores:** `401 NO_AUTENTICADO` → `429 DEMASIADAS_SOLICITUDES` → `403 NO_AUTORIZADO` (antes que
cualquier validación: médico o admin con una *query* inválida reciben `403`) → `422 VALIDACION`
(`estado`, `bodega_id`, `por_pagina` o `page` mal formados o vacíos: `?bodega_id=` sin valor es `422`;
para no filtrar, omitan el parámetro).

**UX:** panel «Pendientes de autorización» del regente con
`GET /dispensaciones?estado=PENDIENTE_AUTORIZACION` (opcionalmente `&bodega_id=`); la primera fila
es la que más lleva esperando. Al abrir una fila, pedir `GET /dispensaciones/{id}` (trae el
paciente, enmascarado para el auditor, y deja rastro en la bitácora); no lo precarguen para todas
las filas. Mostrar «Autorizar»/«Rechazar» solo si el usuario tiene la habilidad
`dispensacion.autorizar` (de `GET /auth/me`), `estado = PENDIENTE_AUTORIZACION` y
`dispensado_por.id ≠ usuario.id` (si no, el servidor responde `403 NO_AUTORIZADO` o
`403 AUTORIZACION_MISMO_USUARIO`). Tras autorizar o rechazar, recargar la página
del listado.

#### `GET /dispensaciones/{id}`

**Quién:** aux, regente, auditor (S-77). Igual que la respuesta de creación más el objeto `paciente`
(`PacienteResource`, enmascarado para el auditor). Deja rastro en la bitácora.

#### `POST /dispensaciones/{id}/autorizar`

**Quién:** regente distinto del creador. Sin cuerpo. Descuenta stock por FEFO con la fecha de hoy
(un lote que venció mientras esperaba no se usa); los movimientos van a nombre del regente que
autoriza (S-58). Queda en la bitácora.

**200** `{"data": DispensacionResource}` con `estado: "CONFIRMADA"`, `autorizado_por_id`, `autorizado_en` y `detalles`.

**Errores (S-60):** `403 NO_AUTORIZADO` → `403 AUTORIZACION_MISMO_USUARIO` → `409 TRANSICION_INVALIDA`
(no está pendiente) → `422 VALIDACION` (`bodega_id`: bodega desactivada) → `422 PRESCRIPCION_NO_VIGENTE`
→ `409 STOCK_INSUFICIENTE`. En los `422`/`409` de negocio sigue `PENDIENTE_AUTORIZACION` (S-59).

#### `POST /dispensaciones/{id}/rechazar`

**Quién:** regente distinto del creador.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `motivo` | string | sí | 10–500, una línea |

**200** `{"data": DispensacionResource}` con `estado: "RECHAZADA"`, `rechazado_por_id`, `rechazado_en`,
`motivo_rechazo`. Sin movimientos de stock; sus cantidades dejan de contar para el tope de la prescripción.

**Errores:** `403 NO_AUTORIZADO` → `403 AUTORIZACION_MISMO_USUARIO` → `422 VALIDACION` → `409 TRANSICION_INVALIDA`.

**UX:** mostrar «Autorizar» y «Rechazar» solo si el usuario tiene `dispensacion.autorizar`, el estado
es `PENDIENTE_AUTORIZACION` y `dispensado_por_id ≠ usuario.id`. Las pendientes se listan con
`GET /dispensaciones?estado=PENDIENTE_AUTORIZACION`.

### 4.5 Pacientes

#### `POST /pacientes/buscar`

**Quién:** aux, regente, médico, auditor. Búsqueda exacta por documento. Va por POST para que el
documento **nunca** viaje en la URL (RN-10-AC7): un `numero_documento` en la *query* se ignora.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `numero_documento` | string | sí | ≤ 20; solo letras, dígitos y guiones |
| `tipo_documento` | string | no | `CC`, `TI`, `CE`, `PA`, `RC` |

Otras claves → `VALIDACION`.

**200** `{"data": [PacienteResource, …]}` (no paginado; puede haber varios sin `tipo_documento`; vacío
si no hay coincidencias, S-78):

```json
{ "data": [ { "id": 9, "tipo_documento": "CC", "numero_documento": "1234567890", "nombres": "Ana María",
              "apellidos": "Pérez Gómez", "fecha_nacimiento": "1985-04-12", "telefono": "3001234567",
              "correo": "ana.perez@correo.test", "direccion": "Calle 1 # 2-3", "enmascarado": false } ] }
```

Auditor: `{"id": 9, "tipo_documento": "CC", "numero_documento": "******7890", "nombres": "A*** M***",
"apellidos": "P*** G***", "fecha_nacimiento": "1985", "telefono": "*******567", "correo": "a***@***",
"direccion": null, "enmascarado": true}`.

**Errores:** `403` → `422 VALIDACION`.

#### `GET /pacientes/{id}`

**200** `{"data": PacienteResource}`. **Errores:** `403`.

#### `GET /pacientes/{id}/prescripciones`

**200** `{"data": [PrescripcionResource, …]}` (no paginado; más recientes primero):

```json
{ "data": [ { "id": 21, "paciente_id": 9, "medico_id": 4, "estado": "ACTIVA",
  "fecha_emision": "2026-11-01", "fecha_vencimiento": "2026-12-01", "observaciones": "Control en 30 días",
  "paciente": { "id": 9, "…": "…" },
  "items": [ { "id": 44, "producto_id": 7,
               "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg", "control_especial": false },
               "cantidad_prescrita": 60, "cantidad_pendiente": 30, "indicaciones": "1 tableta cada 8 horas" } ] } ] }
```

- `estado`: `ACTIVA` o `ANULADA`. Vigente si `ACTIVA` y hoy ≤ `fecha_vencimiento` (S-05).
- `cantidad_pendiente` = prescrito − dispensado (`CONFIRMADA` y `PENDIENTE_AUTORIZACION`). Úsese como
  máximo del campo cantidad en el formulario de dispensación.

**UX:** deshabilitar la dispensación de prescripciones no vigentes o con pendiente 0.

### 4.6 Traslados y discrepancias

El traslado indica **lote explícito** por ítem (no FEFO); el destino recibe el mismo lote (S-12).
Crear no comprueba stock: se comprueba al despachar, sin reserva (S-62).

`TrasladoResource`:

```json
{ "data": { "id": 70, "estado": "EN_TRANSITO", "bodega_origen_id": 1, "bodega_destino_id": 2,
  "bodega_origen": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central" },
  "bodega_destino": { "id": 2, "codigo": "BOD-02", "nombre": "Urgencias" },
  "observaciones": "Reposición semanal", "creado_por_id": 3,
  "solicitado_por_id": 3, "solicitado_en": "2026-11-05T08:00:00-05:00",
  "aprobado_por_id": 2, "aprobado_en": "2026-11-05T08:20:00-05:00",
  "despachado_por_id": 3, "despachado_en": "2026-11-05T09:00:00-05:00",
  "recibido_por_id": null, "recibido_en": null, "anulado_por_id": null, "anulado_en": null, "motivo_anulacion": null,
  "items": [ { "id": 140, "producto_id": 7, "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg" },
               "lote_id": 30, "lote": { "id": 30, "codigo": "L-2027-01", "fecha_vencimiento": "2027-03-31" },
               "cantidad": 40, "cantidad_recibida": null, "discrepancia": null } ],
  "discrepancias": [],
  "created_at": "2026-11-05T07:50:00-05:00", "updated_at": "2026-11-05T09:00:00-05:00" } }
```

`discrepancias` repite, en una lista plana, las discrepancias de los ítems (`DiscrepanciaResource`, ver
`POST /discrepancias/{id}/resolver`).

#### Ítems (crear y editar)

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `items` | lista | sí | 1–50 |
| `items[].producto_id` | id | sí | existente |
| `items[].lote_id` | id | sí | existente, **del producto**, sin repetir en el traslado, no vencido |
| `items[].cantidad` | entero estricto | sí | 1–1.000.000 |

Solo esas claves por ítem (S-64).

#### `GET /traslados`

**Quién:** aux, regente, auditor. Paginado, del más reciente al más antiguo (S-69).
Query: `estado` (valor de §5.2), `bodega_origen_id`, `bodega_destino_id`, `bodega_id` (origen **o**
destino), `por_pagina`, `page`.

#### `GET /traslados/{id}`

**200** `{"data": TrasladoResource}`.

#### `POST /traslados`

**Quién:** aux, regente.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `bodega_origen_id` | id | sí | existente y activa |
| `bodega_destino_id` | id | sí | existente, activa y distinta del origen |
| `observaciones` | string \| null | no | ≤ 1000; admite saltos de línea y tabuladores (S-68) |
| `items` | lista | sí | ver tabla de ítems |

**201** `{"data": TrasladoResource}` en `BORRADOR`.

**Errores (S-66):** `403` → `422 VALIDACION` (forma; bodegas inactivas; lote de otro producto) → `422 LOTE_VENCIDO`.

#### `PUT /traslados/{id}/items`

**Quién:** el creador (aux o regente; S-63). Cuerpo `{"items": [ … ]}` (solo `items`); **reemplaza**
la lista completa. **200** `{"data": TrasladoResource}`.

**Errores:** `403 NO_AUTORIZADO` → `422 VALIDACION` (forma) → `409 TRANSICION_INVALIDA` (no está en
`BORRADOR`) → `422 VALIDACION` (lote de otro producto) → `422 LOTE_VENCIDO`.

#### `POST /traslados/{id}/solicitar`

**Quién:** el creador (S-08). Sin cuerpo. **200**, estado `SOLICITADO`; `solicitado_por_id` = creador.
**Errores:** `403` → `409 TRANSICION_INVALIDA`.

#### `POST /traslados/{id}/aprobar`

**Quién:** regente que **no** sea el solicitante (RN-08). Sin cuerpo. **200**, estado `APROBADO`. Queda en la bitácora.
**Errores (S-72, S-74):** `403 NO_AUTORIZADO` → `403 SEGREGACION_FUNCIONES` → `409 TRANSICION_INVALIDA`.
(Aprobar un `BORRADOR` da `409`: aún no hay solicitante.)

#### `POST /traslados/{id}/despachar`

**Quién:** aux, regente (el solicitante también puede). Sin cuerpo. Descuenta del origen
(`SALIDA_TRASLADO`) todos los ítems. **200**, estado `EN_TRANSITO`.
**Errores (S-66):** `403` → `409 TRANSICION_INVALIDA` → `422 LOTE_VENCIDO` (venció tras aprobarse) →
`409 STOCK_INSUFICIENTE` (primer ítem sin stock).

#### `POST /traslados/{id}/recibir`

**Quién:** aux, regente (sin segregación, S-16).

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `items` | lista | sí | **todos** los ítems del traslado, ninguno ajeno, sin repetir |
| `items[].traslado_item_id` | id | sí | |
| `items[].cantidad_recibida` | entero estricto | sí | 0–1.000.000, ≤ cantidad despachada |

**200**: estado `RECIBIDO` si llegó todo, `RECIBIDO_PARCIAL` si faltó algo. Por cada ítem con faltante
se crea una discrepancia `PENDIENTE`. Recibir 0 de un ítem no crea movimiento (S-70). Se acepta un
lote que venció en tránsito (S-12).

**Errores (S-66):** `403` → `422 VALIDACION` (forma, ítems incompletos o ajenos) → `409 TRANSICION_INVALIDA`
→ `422 CANTIDAD_RECIBIDA_EXCEDE` → `422 CANTIDAD_EXCEDE_MAXIMO`.

**UX:** precargar el formulario con todos los ítems y `cantidad_recibida = cantidad`; si el usuario
baja alguna, advertir que se generará una discrepancia.

#### `POST /traslados/{id}/anular`

**Quién:** el creador (aux o regente) o cualquier regente.

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `motivo` | string | sí | 10–500, una línea |

**200**, estado `ANULADO`. Solo desde `BORRADOR`, `SOLICITADO` o `APROBADO`; nunca devuelve stock (S-09). Queda en la bitácora.
**Errores:** `403` → `422 VALIDACION` → `409 TRANSICION_INVALIDA`.

#### `POST /discrepancias/{id}/resolver`

**Quién:** regente que **no** sea el solicitante del traslado (S-71).

| Campo | Tipo | Oblig. | Límites |
|---|---|---|---|
| `tipo` | string | sí | `PERDIDA`, `REINTEGRO_ORIGEN`, `ENTREGA_DESTINO` |
| `motivo` | string | sí | 10–**400**, una línea |

- `PERDIDA`: no mueve stock.
- `REINTEGRO_ORIGEN` / `ENTREGA_DESTINO`: `AJUSTE` positivo por la cantidad de la discrepancia en la
  bodega origen o destino, con motivo de kardex «Discrepancia N del traslado M (TIPO): motivo» (S-67).
- El estado del traslado no cambia (sigue `RECIBIDO_PARCIAL`). Queda en la bitácora.

**200** `{"data": DiscrepanciaResource}`:

```json
{ "data": { "id": 12, "traslado_item_id": 140, "traslado_id": 70, "producto_id": 7, "lote_id": 30,
  "cantidad": 5, "estado": "RESUELTA", "tipo_resolucion": "REINTEGRO_ORIGEN",
  "motivo": "Unidades devueltas por el mensajero", "resuelto_por_id": 2,
  "resuelto_en": "2026-11-05T11:00:00-05:00", "movimiento_kardex_id": 950,
  "created_at": "2026-11-05T10:30:00-05:00" } }
```

**Errores (S-73):** `403 NO_AUTORIZADO` → `403 SEGREGACION_FUNCIONES` → `422 VALIDACION` →
`409 TRANSICION_INVALIDA` (ya resuelta) → `422 CANTIDAD_EXCEDE_MAXIMO`.

### 4.7 Auditoría

#### `GET /auditoria`

**Quién:** auditor, admin. Bitácora de solo lectura; paginada, más reciente primero.

Query: `usuario_id`, `paciente_id`, `resultado` (`PERMITIDO` \| `DENEGADO`), `accion` (ver abajo),
`desde`, `hasta` (`Y-m-d`, inclusivos por día de Bogotá), `por_pagina`, `page`.

`accion`: `PACIENTE_CONSULTAR`, `PACIENTE_BUSCAR`, `PACIENTE_PRESCRIPCIONES`, `DISPENSACION_CONSULTAR`,
`DISPENSACION_AUTORIZAR`, `DISPENSACION_RECHAZAR`, `INVENTARIO_AJUSTAR`, `TRASLADO_APROBAR`,
`TRASLADO_ANULAR`, `DISCREPANCIA_RESOLVER`.

```json
{ "id": 330, "usuario_id": 5, "usuario": { "id": 5, "nombre": "Auditor Uno", "rol": "auditor" },
  "paciente_id": 9, "accion": "PACIENTE_CONSULTAR", "resultado": "PERMITIDO",
  "entidad": null, "entidad_id": null, "ruta": "api/v1/pacientes/{paciente}", "metodo": "GET",
  "ip": "10.0.0.8", "correlation_id": "6f1c…", "created_at": "2026-11-05T10:10:00-05:00" }
```

`entidad`: `dispensacion`, `movimiento_kardex`, `traslado`, `discrepancia` o `null`.

### 4.8 Alertas (RN-11)

**Quién:** aux, regente, auditor (`inventario.consultar`); médico y admin → `403`. Solo lectura.

- «Hoy» es la fecha de negocio de **America/Bogota** (S-01) y se devuelve en `meta.hoy` (S-84).
- Filtros: `bodega_id`, `producto_id` (enteros; un id inexistente → `200` con `data: []`) y, solo en
  vencimientos, `tipo`. Paginación §1.5 (S-25, S-28, S-88). Filtro mal formado → `422 VALIDACION`.
- Las alertas incluyen bodegas y productos **inactivos**: el stock físico sigue existiendo (S-86).

#### `GET /alertas/vencimientos`

Existencias con cantidad > 0 cuyo lote está vencido o vence en ≤ N días (N = `farma.dias_alerta_vencimiento`,
variable `FARMA_DIAS_ALERTA_VENCIMIENTO`, 90 por defecto; 0 = solo vencidos; un valor negativo se trata
como 0; S-87). Orden: `fecha_vencimiento` ascendente, luego id de lote e id de existencia (S-85).
Una fila por (bodega, lote).

Query: `bodega_id`, `producto_id`, `tipo` (`VENCIDO` \| `POR_VENCER`), `por_pagina`, `page`.

```json
{ "data": [ { "existencia_id": 15,
    "bodega": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central" },
    "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg" },
    "lote": { "id": 31, "codigo": "L-2026-11", "fecha_vencimiento": "2026-11-20" },
    "fecha_vencimiento": "2026-11-20", "cantidad": 12, "tipo": "POR_VENCER", "dias_restantes": 15 } ],
  "links": { "…": "…" },
  "meta": { "current_page": 1, "…": "…", "hoy": "2026-11-05", "dias_alerta_vencimiento": 90 } }
```

- `dias_restantes` = `fecha_vencimiento` − `meta.hoy`, en días (S-83).
- `tipo = VENCIDO` si vence hoy o antes: `dias_restantes = 0` vence hoy, negativo = días que lleva
  vencido (S-01, S-83). `POR_VENCER` si `1 ≤ dias_restantes ≤ dias_alerta_vencimiento`.
- `tipo=VENCIDO` en la *query* devuelve solo vencidos; `tipo=POR_VENCER`, solo los de 1..N días.
- `meta.hoy` y `meta.dias_alerta_vencimiento` (umbral efectivo, nunca negativo) informan el criterio
  aplicado (S-84): úsense para el texto «al {hoy}, próximos {N} días».

#### `GET /alertas/stock-minimo`

Pares (bodega, producto) con stock mínimo definido cuyo stock vigente (lotes no vencidos) es
**estrictamente menor** que el mínimo (S-15). `actual` = suma de existencias de lotes que vencen
después de `meta.hoy` (0 si solo hay stock vencido o ninguno); `faltante = minimo − actual` (> 0).
Un mínimo de 0 nunca alerta (S-86). Orden: `faltante` descendente, luego id de bodega, id de producto
e id del stock mínimo (S-85).

Query: `bodega_id`, `producto_id`, `por_pagina`, `page`.

```json
{ "data": [ { "stock_minimo_id": 3,
    "bodega": { "id": 1, "codigo": "BOD-01", "nombre": "Farmacia Central" },
    "producto": { "id": 7, "codigo": "MED-007", "nombre": "Acetaminofén 500 mg" },
    "minimo": 20, "actual": 19, "faltante": 1 } ],
  "links": { "…": "…" }, "meta": { "current_page": 1, "…": "…", "hoy": "2026-11-05" } }
```

**Errores (ambos):** `401 NO_AUTENTICADO` → `429 DEMASIADAS_SOLICITUDES` → `403 NO_AUTORIZADO` →
`422 VALIDACION` (`detalles.campos`).

---

## 5. Estados

### 5.1 Dispensación

```
           crear (aux/regente)
   ┌──────────────┴───────────────┐
   │ sin control especial         │ con algún producto de control especial
   ▼                              ▼
CONFIRMADA              PENDIENTE_AUTORIZACION
(stock descontado)        │                │
                          │ autorizar      │ rechazar
                          │ (otro regente) │ (otro regente, motivo)
                          ▼                ▼
                     CONFIRMADA        RECHAZADA (sin stock)
```

| Estado | Acción | Rol | Resultado | Acciones a mostrar |
|---|---|---|---|---|
| — | crear | aux, regente | `CONFIRMADA` o `PENDIENTE_AUTORIZACION` | — |
| `PENDIENTE_AUTORIZACION` | autorizar | regente ≠ creador | `CONFIRMADA` | «Autorizar», «Rechazar» (solo a regentes ≠ creador) |
| `PENDIENTE_AUTORIZACION` | rechazar | regente ≠ creador | `RECHAZADA` | ídem |
| `CONFIRMADA`, `RECHAZADA` | — | — | finales | ninguna |

### 5.2 Traslado

```
BORRADOR ──solicitar──▶ SOLICITADO ──aprobar──▶ APROBADO ──despachar──▶ EN_TRANSITO ──recibir──▶ RECIBIDO
   │  ▲                     │                      │                                    └──────▶ RECIBIDO_PARCIAL
   │  └─editar ítems        │                      │                                             (discrepancias)
   └──────anular────────────┴────────anular────────┴──▶ ANULADO
```

| Estado | Acción | Quién | Estado resultante |
|---|---|---|---|
| `BORRADOR` | editar ítems (`PUT …/items`) | creador | `BORRADOR` |
| `BORRADOR` | solicitar | creador | `SOLICITADO` |
| `BORRADOR` | anular | creador o regente | `ANULADO` |
| `SOLICITADO` | aprobar | regente ≠ solicitante | `APROBADO` |
| `SOLICITADO` | anular | creador o regente | `ANULADO` |
| `APROBADO` | despachar | aux, regente | `EN_TRANSITO` |
| `APROBADO` | anular | creador o regente | `ANULADO` |
| `EN_TRANSITO` | recibir | aux, regente | `RECIBIDO` o `RECIBIDO_PARCIAL` |
| `RECIBIDO`, `RECIBIDO_PARCIAL`, `ANULADO` | — | — | finales |

Cualquier otra combinación → `409 TRANSICION_INVALIDA`. Para decidir qué botones mostrar, combínese
el estado con la habilidad y el usuario:

- «Editar» y «Solicitar»: `BORRADOR` y `creado_por_id = usuario.id`.
- «Aprobar»: `SOLICITADO`, habilidad `traslado.aprobar` y `solicitado_por_id ≠ usuario.id`.
- «Despachar»: `APROBADO` y `traslado.operar`.
- «Recibir»: `EN_TRANSITO` y `traslado.operar`.
- «Anular»: `BORRADOR`/`SOLICITADO`/`APROBADO`, `traslado.anular` y (`creado_por_id = usuario.id` o `traslado.aprobar`).
- «Resolver discrepancia»: discrepancia `PENDIENTE`, `traslado.aprobar` y `solicitado_por_id ≠ usuario.id`.

### 5.3 Discrepancia

`PENDIENTE` → (resolver: regente ≠ solicitante, `tipo` + `motivo`) → `RESUELTA` (final).

---

## 6. Pendiente y fuera de alcance

- **Asistente de IA (Parte C):** fuera de este entregable; sin endpoints.
- **CORS:** aún no configurado; se añade con la Parte D (Docker), restringido al dominio del
  frontend. Hasta entonces, en desarrollo úsese un *proxy* del servidor de desarrollo (p. ej. `server.proxy` de Vite).
- **`/health` y `/ready`:** llegan con la Parte D. Hoy existe `GET /up` (fuera de `/api/v1`).
- Sin endpoints (aunque exista la habilidad o el rol): alta de prescripciones (`prescripcion.crear`),
  gestión de usuarios, pacientes y stock mínimo, modificación de bodegas/productos y listado de
  discrepancias fuera del traslado.

---

## 7. Observaciones del contrato (para el equipo de backend)

- Los mensajes de validación por defecto salen en inglés (`APP_LOCALE=en`); los personalizados, en español.
- `KARDEX_INMUTABLE` y `BITACORA_INMUTABLE` no los provoca ninguna ruta (defensa en profundidad).
- `ERROR_HTTP` y `CREDENCIALES_INVALIDAS` existen en el código además de los códigos de la spec.

---

## 8. Historial de cambios del contrato

| Fecha | Versión de la API | Cambio |
|---|---|---|
| 2026-11-05 | `v0.11.0` (+ RN-11 en revisión) | Primera versión del contrato: auth, catálogo, inventario y kardex, dispensación (FEFO, idempotencia, control especial), pacientes (privacidad), traslados y discrepancias, auditoría; alertas RN-11 en revisión. |
| 2026-11-05 | `v0.12.0` (RN-11) | Alertas cerradas (§4.8): `GET /alertas/vencimientos` y `GET /alertas/stock-minimo` sin la marca «en revisión»; `dias_restantes` (S-83), filtros y `meta.hoy`/`meta.dias_alerta_vencimiento` (S-84), orden con desempates (S-85), inactivos y mínimo 0 (S-86), umbral 0 o negativo (S-87); errores con `401 NO_AUTENTICADO`. `GET /bodegas` y `GET /productos` aceptan y validan `por_pagina` (1–100, 25) y `page` (≤ 1.000.000) → `422 VALIDACION` fuera de rango, orden `nombre` + `id` (S-88). |
| 2026-11-05 | `v0.13.0` | Nuevo `GET /dispensaciones` (§4.4, S-89): listado paginado (`por_pagina` 1–100, 25; `page` ≤ 1.000.000) con filtros `estado` y `bodega_id` (vacío → `422`), orden `created_at` ASC + `id`; filas con `dispensado_por {id, nombre}`, sin `paciente` ni `detalles`; aux, regente y auditor (médico y admin `403`, antes que la validación); sin rastro en la bitácora. Matriz de permisos (§3.2), nota de UX del panel de pendientes del regente y retirada de §6 («sin listado de dispensaciones»). Corrección (§1.6 y ejemplos): las marcas de tiempo salen en hora de Bogotá con desfase `-05:00`, no `+00:00`. |
| 2026-11-05 | Parte D (sin tag) | Sondas `GET /health` (liveness, sin BD) y `GET /ready` (BD + migraciones; `200`/`503`) en la raíz, sin auth ni throttle; `/up` se conserva (§1.9). CORS para `api/*` desde `CORS_ALLOWED_ORIGINS`, sin credenciales, con cabeceras expuestas. Cabeceras de seguridad en toda respuesta (nosniff, `X-Frame-Options: DENY`, `Referrer-Policy`, HSTS solo en https) y proxies de confianza (`TRUSTED_PROXIES`, `*` por defecto). |
