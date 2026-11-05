# farma-sistema

API REST de FARTMAR IPS para dispensación de medicamentos, inventario por lote y traslados entre bodegas.
Este repositorio es solo el backend; el frontend React está en [`farma-sistema-web`](https://github.com/cekalidev/farma-sistema-web).

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 16 · Sanctum (Bearer) · Pest · Pint · Larastan nivel 9 · cobertura pcov 98 % (mínimo exigido 80 %).

**Librerías relevantes:** solo Sanctum (tokens Bearer revocables, sin cookies: el frontend vive en otro dominio), Pest (tests legibles por criterio `RN-xx-ACn`), Larastan nivel 9 (tipos estrictos sin baseline) y Pint (estilo único). Sin paquete de permisos ni de auditoría: roles y bitácora son reglas de negocio propias.

## Arranque en un comando

Con los dos repositorios clonados uno al lado del otro (`./farma-sistema` y `./farma-sistema-web`):

```bash
cd farma-sistema
docker compose up --build        # o: podman-compose up --build
```

| Servicio | URL |
|---|---|
| Web (React) | http://localhost:8080 |
| API | http://localhost:8000/api/v1 |
| Sondas | http://localhost:8000/health (liveness) · http://localhost:8000/ready (BD + migraciones) |

La semilla crea datos **sintéticos** y un usuario por rol, con la contraseña de demo `Farma2026!`:
`auxiliar@farma.test`, `regente@farma.test`, `regente2@farma.test` (segundo regente para RN-05 y RN-08),
`medico@farma.test`, `auditor@farma.test` y `admin@farma.test`. Limpieza: `docker compose down -v`.

**Sin Docker** (PHP 8.5, Composer y PostgreSQL 16 locales):

```bash
composer install
cp .env.example .env && php artisan key:generate   # ajustar DB_* en .env
php artisan migrate:fresh --seed
php artisan serve                                  # http://127.0.0.1:8000
```

## Calidad

| Comando | Qué hace |
|---|---|
| `php artisan test` | Suite completa sobre PostgreSQL (BD `farma_test`, fijada en `phpunit.xml`) |
| `vendor/bin/pest --filter='RN-02'` | Tests de una regla (cada test lleva el id del criterio, p. ej. `RN-03-AC3`) |
| `vendor/bin/pint --test` | Estilo |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Larastan nivel 9, sin baseline ni ignores |
| `composer test:coverage` | Suite con cobertura; falla por debajo del 80 % |

La CI (`.github/workflows/ci.yml`) ejecuta los cuatro sobre PostgreSQL 16 en cada push y PR.

## Arquitectura

```
Ruta /api/v1 → Controller (delgado) → Form Request (validación) → Policy (rol)
             → Servicio de dominio (DB::transaction + lockForUpdate) → API Resource (JSON)
```

- Servicios por dominio en `app/Services/{Inventario,Dispensacion,Traslados,Pacientes,Auditoria}`.
- Errores de negocio = excepciones de `app/Exceptions/Dominio` con código estable, que el handler
  convierte en `{"error": {"codigo", "mensaje", "detalles"}}` con su status (409 `STOCK_INSUFICIENTE`…).
- Roles: enum `App\Enums\Rol` con una matriz rol → habilidades central; las Policies la consultan.
- La BD respalda las reglas críticas: CHECK, índices únicos y triggers de inmutabilidad.
- Cada respuesta lleva `X-Correlation-Id`; los logs son JSON y redactan datos sensibles.

## Reglas de negocio

| RN | Dónde | Cómo se garantiza |
|---|---|---|
| RN-01 Existencia por bodega+producto+lote | `Inventario/EntradaInventarioService` | Único `(bodega, producto, lote)`; un lote vencido (vence hoy = vencido) no entra, sale ni se traslada. |
| RN-02 FEFO | `Dispensacion/AsignadorFefo` | Lotes vigentes por vencimiento ascendente (empate por id), puede repartir entre varios lotes. |
| RN-03 Stock nunca negativo | `Dispensacion/SalidaStock` | `lockForUpdate` con orden global de bloqueo (S-56) + CHECK `cantidad >= 0`; interbloqueo → 409 reintentable. |
| RN-04 Prescripción vigente y tope | `Dispensacion/CalculadorPendiente` | Vigencia inclusiva; tope acumulado calculado con los ítems de la prescripción bloqueados. |
| RN-05 Control especial | `Dispensacion/ControlEspecial`, `AutorizacionDispensacion` | Queda pendiente; la autoriza otro regente (403 si es el mismo) y el stock se descuenta al autorizar. |
| RN-06 Kardex completo e inmutable | `Inventario/RegistradorKardex` | Todo cambio de stock pasa por él; triggers rechazan `UPDATE`, `DELETE` y `TRUNCATE`; correcciones = ajuste con motivo. |
| RN-07 Máquina de estados del traslado | `Traslados/*` + enum `TrasladoEstado` | Transiciones explícitas (409 si no aplica), stock en tránsito, recepción parcial con discrepancias. |
| RN-08 Segregación de funciones | `TrasladoPolicy` + servicio | El solicitante no aprueba ni resuelve discrepancias; CHECK `aprobado_por_id <> solicitado_por_id`. |
| RN-09 Dispensación idempotente | `Dispensacion/ClaveIdempotencia`, `RespuestaIdempotente` | `Idempotency-Key` obligatoria, único por usuario, hash del cuerpo; el reintento reproduce la respuesta guardada. |
| RN-10 Privacidad del paciente | `Pacientes/ConsultaPacienteService`, `Support/Enmascarador`, `Auditoria/Bitacora` | Enmascarado para auditor; cada acceso deja fila en una bitácora inmutable (trigger), sin PII en logs. |
| RN-11 Alertas | `Inventario/ConsultaAlertasService` | Vencidos y por vencer (umbral `FARMA_DIAS_ALERTA_VENCIMIENTO`, 90 días) y stock bajo el mínimo por bodega. |

- **Tests por criterio:** un archivo por regla en `tests/Feature/RNxx*Test.php`; los nombres citan `RN-xx-ACn`.
- **Concurrencia real:** `tests/Feature/SinTransaccion/` lanza procesos paralelos (`pcntl_fork`, una conexión
  PostgreSQL cada uno, barrera en dos fases) contra la última unidad, el tope de la prescripción, el despacho,
  la autorización y la idempotencia. Se validaron con mutación manual: al quitar el bloqueo o el desempate,
  los tests fallan.

## Decisiones y trade-offs

- **PostgreSQL también en tests** en lugar de SQLite: más lento, pero prueba de verdad los CHECK, triggers y bloqueos.
- **Bloqueo pesimista** (`SELECT … FOR UPDATE`) en lugar de optimista: menos concurrencia, pero sin reintentos en el caso normal y con orden de bloqueo fijo contra interbloqueos.
- **409 `CONFLICTO_CONCURRENCIA` reintentable** (`Retry-After`) si PostgreSQL detecta interbloqueo o serialización: nunca un 500 ni un cambio a medias.
- **Respuesta idempotente guardada** (status + cuerpo) en lugar de recalcularla: ocupa espacio, pero el reintento es exacto aunque el stock cambie.
- **Control especial en dos pasos** (crear → autorizar) en lugar de credenciales de dos usuarios en una petición; el stock no se reserva.
- **Sin paquete de permisos:** enum `Rol` + matriz central, cubierta por un test de guarda que recorre endpoint × rol.
- **Reglas duplicadas en la BD** (CHECK, únicos, triggers): más migraciones, pero la integridad no depende solo del código.
- **Render construye desde el `Dockerfile`**; la imagen de GHCR queda como artefacto versionado para rollback.

## Supuestos principales

Lista completa (S-01 … S-89) en [`docs/supuestos.md`](docs/supuestos.md).

- **S-01** Un lote que vence hoy cuenta como vencido (zona America/Bogota).
- **S-02** Si el stock vigente no alcanza → 409 `STOCK_INSUFICIENTE`, informando el stock vencido no dispensable.
- **S-03** Empate FEFO: a igual vencimiento, primero el lote registrado antes.
- **S-04** La dispensación es todo o nada; no se entrega parcialmente.
- **S-06** Control especial en dos pasos; un regente que dispensa también necesita a otro regente.
- **S-09** Un traslado despachado no se anula; lo no recibido se gestiona como discrepancia.
- **S-10** Reutilizar un `Idempotency-Key` con otro cuerpo → 422 `IDEMPOTENCY_KEY_REUTILIZADA`.
- **S-12** Los traslados nombran el lote por ítem (no FEFO) y el destino recibe el mismo lote.
- **S-13** `admin` gestiona usuarios y catálogos, pero no ve inventario ni datos clínicos.
- **S-48** Interbloqueo o fallo de serialización → 409 reintentable, sin cambios.

## Fuera de alcance y pendientes

- **Parte C (asistente de IA):** fuera del entregable por decisión de alcance; se priorizó la robustez de las reglas de negocio (concurrencia, idempotencia, inmutabilidad).
- **Creación de prescripciones:** no hay endpoint; las prescripciones llegan por la semilla.
- **Línea de tiempo de traslados:** muestra ids de usuario, no nombres.
- **OpenAPI:** no se generó; lo sustituyen el contrato `docs/contrato-api.md` y la colección Postman con tests.
- **Backups automáticos:** el plan gratuito de Render no los ofrece; el procedimiento manual está en `docs/despliegue.md`.

## Documentación

- [`docs/contrato-api.md`](docs/contrato-api.md): rutas, cuerpos, respuestas, códigos de error y matriz de permisos.
- [`docs/postman/`](docs/postman/): colección con tests y environment local.
- [`docs/despliegue.md`](docs/despliegue.md): Docker, CI/CD, Render, rollback y respaldos.
- [`docs/supuestos.md`](docs/supuestos.md): supuestos S-01 … S-89.
- Demo: _(URL de Render, pendiente)_

## Versionado

SemVer con tags anotados: `v0.1.0` base (auth, roles, modelo), un tag menor por cada regla cerrada
(`v0.2.0` … `v0.12.0`), `v0.13.0` listado de dispensaciones, y `v1.0.0` como cierre de la prueba. Commits convencionales con la RN en el asunto.

## Uso de herramientas de IA

Ver [`AI_USAGE.md`](AI_USAGE.md).
