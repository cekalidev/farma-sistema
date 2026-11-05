# Uso de IA

Declaración exigida por la sección 8 del enunciado: herramientas, tareas, ejemplos aceptados y corregidos o rechazados.

## Herramienta y forma de trabajo

- **Claude Code** (modelo Claude Opus) como coordinador, con subagentes para: implementación, diseño de tests desde la spec, verificación de criterios y revisión de código. Como máximo dos revisores por paso.
- Flujo por regla de negocio: plan → implementación + tests → verificación (Pint, Larastan nivel 9, suite completa, cobertura ≥ 80 %) → revisión → corrección → un commit por RN con tag (ver `git log`).
- La configuración de agentes y reglas se mantuvo en local, fuera del repo.
- La persona aprobó cada supuesto de negocio (S-01..S-89, en `docs/supuestos.md`) y ejecutó a mano todos los commits y push, sin firma de IA.

## Qué se delegó y qué no

| Delegado a la IA | Decidido por la persona |
|---|---|
| Código y tests | Alcance: Parte C y OpenAPI fuera (la API se documenta con Postman) |
| Contrato de la API y colección Postman | Todos los supuestos de negocio |
| Docker, CI y diagramas | Rate limit del login |
| | Segundo regente en control especial (RN-05) |
| | Restricción de discrepancias en traslados (RN-08) |

## Cómo se verificó lo generado

- Tests contra PostgreSQL real (no SQLite).
- Tests de concurrencia con procesos paralelos.
- Pruebas de mutación en la concurrencia y en el desempate del listado.
- Revisión adversarial del código de cada RN.
- newman sobre la colección Postman.
- Prueba de humo del frontend contra la API real.

## Ejemplos

| Tipo | Caso | Por qué |
|---|---|---|
| Aceptado | Matriz central de permisos por rol con un test de guarda que falla si una ruta nueva no declara permisos | Evita rutas sin autorización por olvido |
| Corregido | La IA propuso Larastan nivel 5; se fijó nivel 6 y luego 9, incluyendo config, database y routes | Más garantías de tipos |
| Corregido | El `.gitignore` por defecto habría subido `.env.testing` con secretos; lo detectó la persona | Se ignora `.env.*` salvo `.env.example`; la config de tests pasó a `phpunit.xml` |
| Corregido | Rate limit solo por correo+IP permitía ataques desde varias IP | Se añadieron límites separados por correo y por IP |
| Rechazado | Herramienta de revisión multiagente | Demasiados agentes y envío de código a un modelo externo; se creó un revisor propio con tope de dos |
| Corregido | Un subagente ejecutó artisan con `--env=testing` y borró la BD de desarrollo | Se resembró y se añadió una regla que lo prohíbe |
| Corregido | Test débil: el desempate por id pasaba sin el `orderBy` | Se reforzó y se comprobó con mutación |
| Corregido | El contrato documentaba fechas con `+00:00`; la API devuelve `-05:00` | Se corrigió el contrato y se avisó al frontend |
| Corregido | `APP_KEY` fija en el compose y menciones a archivos de IA en `.dockerignore` | Detectado en la verificación de despliegue; se eliminaron |
| Corregido | Ruta de Composer equivocada en las instrucciones iniciales | Se corrigió |

Bugs reales que encontraron los tests generados: `cantidad: true` aceptado como 1, caracteres de control en códigos de lote, `TRUNCATE` posible sobre el kardex y un 500 por tipos mixtos en origen = destino.

## Privacidad

- A la herramienta no se le pasaron datos reales de pacientes ni de la IPS; todo era sintético.

## Límites

- Los workflows de GitHub se validaron solo en local (actionlint) antes del primer push.
- El diseño visual de los diagramas no se revisó a ojo.
