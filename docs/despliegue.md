# Despliegue — farma-sistema

## Arquitectura

```
Navegador ──https──▶ Render (TLS) ──http:$PORT──▶ contenedor API: Nginx (no root) → PHP-FPM 8.5 + OPcache
                                                                         └──▶ Render Postgres 16 (DB_URL)
```

- **Imagen** (`Dockerfile` multi-stage): Composer `--no-dev --optimize-autoloader` + `php:8.5-fpm-alpine` y
  Nginx; código de root, solo `storage/` y `bootstrap/cache/` de `www-data`. `tini` es PID 1 y
  `docker/api/entrypoint.sh` arranca FPM y Nginx: si uno muere, para el otro y el contenedor sale con error.
- **Arranque:** `migrate --force` → semilla sintética solo si `SEED_DEMO=true` y `users` está vacía
  (atómica y repetible) → `config:cache` y `route:cache`. Sin `APP_KEY` genera una efímera y lo avisa.
- **Sondas:** `/health` (liveness, sin BD) y `/ready` (`SELECT 1` + migraciones; 503 si no).
- **Logs:** stderr en JSON (`LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`), con el redactor de
  datos sensibles y `extra.correlation_id`.
- **TLS:** lo termina Render; la app confía en el proxy inmediato (`TRUSTED_PROXIES=*`), ve `https` y
  envía HSTS. Cabeceras `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`; CORS solo `api/*`.

## Local con un comando

Con el frontend clonado al lado (`../farma-sistema-web`): `docker compose up --build`
(o `podman-compose up --build`). Web en `:8080` (su proxy `/api/` llega a la API), API en
`:${API_PORT:-8000}`, `scheduler` con `schedule:work` y PostgreSQL 16 con volumen. Usuario de demo:
`regente@farma.test` / `Farma2026!`. Limpieza: `docker compose down -v`.

> **Aviso:** `SEED_DEMO=true` crea usuarios con una contraseña **pública** (la de arriba). Úsalo solo en
> demos; la contraseña de BD del compose es «solo demo local».

## CI/CD (GitHub Actions)

- `ci.yml` (ramas y PR): Pint `--test`, Larastan 9 y Pest sobre PostgreSQL 16 con cobertura pcov ≥ 80 %.
- `cd.yml`: push a `main` → CI → imagen `ghcr.io/<owner>/<repo>` (`sha-<commit>`, `main`) → staging
  (simulado). Tag `vX.Y.Z` **creado sobre la punta de `main`** → además etiqueta `X.Y.Z` y
  `deploy-production`, que espera la **aprobación manual** del environment `production` y llama al Deploy
  Hook. El hook despliega el último commit de `main` y Render construye el `Dockerfile` (no usa la imagen
  de GHCR, que queda como artefacto versionado).
- El frontend tiene su propia CI (ESLint, tests e imagen) en el repositorio `farma-sistema-web`.

## Render (una vez)

1. Crear PostgreSQL 16 (free, Virginia) y copiar la *Internal Database URL*.
2. New → Blueprint con este repo (`render.yaml`). Pegar `APP_KEY` (`php artisan key:generate --show`),
   `DB_URL` y `CORS_ALLOWED_ORIGINS` (origen del frontend o vacío).
3. Copiar el Deploy Hook y guardarlo como secreto `RENDER_DEPLOY_HOOK_URL` del environment `production`
   en GitHub, con *Required reviewers* activado (crear también `staging`).

## Rollback

- **Render:** *Rollback* a un deploy anterior desde el panel, o *Manual Deploy* de un commit anterior.
- **Imagen:** volver a desplegar un tag inmutable de GHCR (`sha-<commit>` o `X.Y.Z`) en cualquier runtime.
- Las migraciones se escriben compatibles hacia atrás (*expand/contract*: añadir antes de usar, borrar
  en una versión posterior), así que la versión anterior funciona con el esquema nuevo.
  `php artisan migrate:rollback` es el último recurso, tras un respaldo.

## Respaldo y restauración de la BD

- Render free **no tiene backups**. Programar un `pg_dump -Fc "$DB_URL" > farma-$(date +%F).dump`
  (cron externo, p. ej. un workflow programado) y restaurar con `pg_restore --clean --no-owner -d "$DB_URL" farma-AAAA-MM-DD.dump`.
- Compose: `docker compose exec db pg_dump -U farma -Fc farma > farma.dump` y
  `docker compose exec -T db pg_restore -U farma --clean -d farma < farma.dump`.

## Limitaciones del plan free

- Se duerme tras ~15 min sin tráfico (la primera petición tarda ~1 min).
- La BD free caduca a los 30 días: al recrearla, actualizar `DB_URL`; el arranque migra y re-siembra.
- Sin cron ni workers: el `scheduler` solo corre en compose (en Render haría falta un Cron Job de pago).
