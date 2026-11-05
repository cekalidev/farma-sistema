# Colección de Postman

Colección de la API REST `v1` de farma-sistema, con un environment local listo para usar. El
contrato completo está en [`../contrato-api.md`](../contrato-api.md).

| Archivo | Contenido |
|---|---|
| `farma-sistema.postman_collection.json` | 122 peticiones en 12 carpetas, con tests (`pm.test`) |
| `farma-sistema-local.postman_environment.json` | `base_url`, `root_url`, correos de los usuarios semilla, contraseña de desarrollo y variables `token_<rol>` |

## Importar

En Postman: **Import** → arrastrar los dos archivos → seleccionar el environment
**farma-sistema local** (arriba a la derecha).

## Arrancar el backend

```bash
php artisan migrate:fresh --seed   # BD con datos sintéticos (usuarios, catálogo, lotes, pacientes)
php artisan serve                  # http://127.0.0.1:8000
```

Usuarios semilla (contraseña de desarrollo `Farma2026!`): `auxiliar@`, `regente@`, `regente2@`,
`medico@`, `auditor@` y `admin@farma.test`.

## Orden sugerido

Ejecute la colección completa con el **Collection Runner** (o carpeta por carpeta, en orden):

0. **Salud** (opcional): `GET /health` y `GET /ready` en la raíz, sin auth (usan `root_url`).
1. **0. Auth** primero: inicia sesión con cada rol y guarda `token_auxiliar`, `token_regente`,
   `token_regente2`, `token_medico`, `token_auditor` y `token_admin`.
2. **1. Catálogo** y **2. Inventario** guardan los ids de bodegas, productos, lotes y existencias
   que usan las carpetas siguientes.
3. **3. Entradas** → **4. Ajustes** → **5. Kardex** → **6. Dispensación** → **7. Traslados** →
   **8. Pacientes** → **9. Alertas** → **10. Auditoría**.

Cada carpeta encadena los ids que crea (`dispensacion_id`, `traslado_id`, `discrepancia_id`…) y
termina con una subcarpeta **Errores** con los códigos representativos del módulo
(`STOCK_INSUFICIENTE`, `AUTORIZACION_MISMO_USUARIO`, `SEGREGACION_FUNCIONES`,
`TRANSICION_INVALIDA`, `403`, `422`, `401`…). La descripción de cada petición indica la regla
de negocio (RN-xx) que ilustra.

Por línea de comandos:

```bash
npx -y newman@6 run docs/postman/farma-sistema.postman_collection.json \
  -e docs/postman/farma-sistema-local.postman_environment.json
```

## Repetir la ejecución

La colección se puede volver a ejecutar sobre la misma BD: usa claves de idempotencia y códigos
nuevos en cada pasada. Cada pasada consume una unidad de morfina de la prescripción semilla (6
prescritas), así que admite hasta 6 pasadas antes de volver a sembrar.

Hay dos límites de tasa: 60 peticiones por minuto por usuario y 20 logins por minuto por IP. Entre
dos ejecuciones seguidas, espere un minuto o vuelva a sembrar con `php artisan migrate:fresh --seed`
(con el caché en base de datos, el de por defecto, eso también reinicia los contadores).

## Otros entornos

Con Docker o con el despliegue en Render se cambian `base_url` y `root_url` (por ejemplo
`https://<servicio>.onrender.com/api/v1` y `https://<servicio>.onrender.com`). El resto de variables no cambia.
