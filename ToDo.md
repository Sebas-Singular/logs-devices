# ToDo — Añadir nuevos campos a los logs de dispositivos

Este documento responde a la pregunta:

> Si quiero añadir un campo nuevo al formato de log (por ejemplo, nivel de batería
> en `tags`, un nuevo tipo de evento, o una nueva métrica en `metrics`),
> ¿qué tengo que tocar?

La respuesta depende de **dónde** va el campo nuevo y **qué** necesitas hacer con él.

---

## Regla general de la arquitectura actual

Los datos de cada evento se almacenan en dos columnas JSON en `log_events`:

- **`measurements`** — métricas numéricas del dispositivo (GPS, IMU, velocidad, conteos)
- **`context`** — metadatos del evento (source, upload, tags, config, nodeId, etc.)

Cualquier campo nuevo que llegue dentro de un evento en `events[]` y que no
tenga una columna propia en el esquema, **ya se guarda automáticamente**
en uno de estos dos JSON sin cambiar una sola línea de código.

La pregunta es si con eso es suficiente o si necesitas algo más.

---

## Escenario 1 — Campo nuevo dentro de `tags` o `config` de un evento

**Ejemplo:** añadir `battery_pct` dentro de `tags`:

```json
"events": [{
  "category": "telemetry",
  "type": "telemetry_snapshot",
  "tags": {
    "battery_pct": 87,
    "temperature": 23.4
  }
}]
```

**¿Qué cambia en el código?** → **Nada.**

`StructuredEventNormalizer` guarda `event['tags']` completo en `context.tags`.
Cualquier clave dentro de `tags` se persiste automáticamente.

**¿Qué cambia en la BD?** → Nada. El JSON de `context` contendrá el nuevo campo.

**¿Cómo consultarlo?**

```sql
SELECT JSON_EXTRACT(context, '$.tags.battery_pct') AS battery
FROM log_events
WHERE event_category = 'telemetry'
LIMIT 20;
```

**Coste total:** cero.

---

## Escenario 2 — Campo nuevo dentro de `metrics` de un evento

**Ejemplo:** añadir `rssi` a las métricas:

```json
"events": [{
  "category": "telemetry",
  "type": "telemetry_snapshot",
  "metrics": {
    "rssi": -72,
    "snr": 8.5
  }
}]
```

**¿Qué cambia en el código?** → **Nada.**

`StructuredEventNormalizer` guarda `event['metrics']` completo en `measurements`.

**¿Cómo consultarlo?**

```sql
SELECT JSON_EXTRACT(measurements, '$.rssi') AS rssi
FROM log_events
WHERE event_category = 'telemetry'
LIMIT 20;
```

**Coste total:** cero.

---

## Escenario 3 — Campo nuevo como clave de primer nivel en el evento

**Ejemplo:** añadir `battery_voltage` directamente en el evento (no dentro de `metrics`):

```json
"events": [{
  "category": "telemetry",
  "type": "telemetry_snapshot",
  "battery_voltage": 3.7,
  "metrics": { ... }
}]
```

**¿Qué cambia en el código?** → **Nada automáticamente**, pero `battery_voltage`
caerá en `context.extra` (los campos no reconocidos explícitamente por el normalizer).

`StructuredEventNormalizer::normalizeOne()` tiene la lista de `knownKeys`. Cualquier
clave no listada ahí va a `context.extra` vía `extraFields()`.

**Si quieres que vaya a `measurements` en lugar de `context.extra`**, tienes que
añadir una línea en `StructuredEventNormalizer::normalizeOne()`:

```php
// Después de donde se añaden gps e imu:
if (isset($event['battery_voltage'])) {
    $measurements['battery_voltage'] = $event['battery_voltage'];
}
```

Y añadir `'battery_voltage'` a la lista `$knownKeys` de `extraFields()`.

**Coste:** modificar `StructuredEventNormalizer.php` (2 líneas) + añadir test.

---

## Escenario 4 — Nuevo tipo de evento dentro de `events[]`

**Ejemplo:** nuevo tipo `battery_alert` en categoría `power`:

```json
"events": [{
  "category": "power",
  "type": "battery_alert",
  "level": "warn",
  "message": "Battery below 20%",
  "metrics": { "battery_pct": 18 }
}]
```

**¿Qué cambia en el código?** → **Nada.**

`StructuredEventNormalizer` no tiene una lista blanca de tipos. Cualquier
categoría y tipo llegan tal cual a `event_category` y `event_type` en BD.
Las métricas van a `measurements`. El visor carga categorías y tipos dinámicamente
desde BD (`ViewerQueries::eventCategoryOptions()` y `eventTypeOptions()`).

El nuevo evento aparecerá automáticamente en los filtros del visor en cuanto
llegue el primer payload que lo contenga.

**Coste total:** cero en código. El visor se actualiza solo.

---

## Escenario 5 — Nuevo campo en el payload de nivel superior (fuera de `events[]`)

**Ejemplo:** añadir `hardwareRevision` al objeto `source`:

```json
"source": {
  "project": "walkerpisa",
  "deviceId": 120,
  "hardwareRevision": "v2.1"
}
```

**¿Qué cambia en el código?** → **Nada** si solo necesitas guardarlo.

El objeto `source` completo se guarda en `context.source` de cada evento
y en `payload_summary.source` en `log_ingests`. El nuevo campo estará ahí.

**Si quieres exponerlo como dato normalizado en el payload** (por ejemplo,
para filtrarlo desde el visor o para usarlo en `DeviceResolver`), entonces:

- `PayloadNormalizer::normalize()` — añadir lectura del nuevo campo
- `DeviceResolver` — si afecta a la resolución del dispositivo
- `ViewerQueries` — si quieres filtrar por él en el visor

**Coste:** modificar `PayloadNormalizer.php` + test + opcionalmente visor.

---

## Escenario 6 — Campo que necesita columna propia en BD (filtrado frecuente)

Si el campo nuevo se consulta tan frecuentemente que tenerlo en JSON es ineficiente
(por ejemplo, `battery_pct` en miles de eventos y quieres ordenar por él), entonces
necesitas una columna dedicada.

**Pasos:**

1. **Migración SQL** — añadir columna en `log_events`:

```sql
ALTER TABLE log_events ADD COLUMN battery_pct TINYINT UNSIGNED NULL;
CREATE INDEX idx_log_events_battery ON log_events (battery_pct);
```

Ejecutar en phpMyAdmin. No rompe el sistema existente (la columna es nullable).

2. **`StructuredEventNormalizer::normalizeOne()`** — añadir extracción del campo
   y devolverlo en el array de la fila.

3. **`LogEventWriter`** — añadir la columna nueva a la lista `$columns` del INSERT.

4. **`ViewerQueries`** — añadir soporte de filtro o ordenación por la columna.

5. **`StoredIngestProcessor`** — el reprocesado rellenará la columna para ingests anteriores
   si se hace force=true, pero solo si el campo venía en el NDJSON original.

6. **Tests** — añadir caso en `StructuredEventNormalizerTest` y en `LogEventWriterTest`.

7. **Schema SQL** — actualizar `docker/mariadb/init/001_schema.sql` para que el entorno
   local y CI apliquen el esquema correcto desde cero.

**Coste:** es el escenario más costoso. Solo justificado si el campo es consultado
masivamente o necesita índice propio.

---

## Resumen de decisión

¿Dónde va el campo nuevo?
│
├─ Dentro de tags o config del evento → Nada. Ya se guarda en context.tags/config.
│
├─ Dentro de metrics del evento → Nada. Ya se guarda en measurements.
│
├─ Clave nueva de primer nivel en evento → ¿Importa dónde cae?
│ ├─ No (context.extra vale) → Nada.
│ └─ Sí (quiero en measurements) → 2 líneas en StructuredEventNormalizer.
│
├─ Nuevo tipo/categoría de evento → Nada. El visor se actualiza solo.
│
├─ Campo nuevo en el payload (fuera events[])
│ ├─ Solo para guardar y consultar SQL → Nada. Está en context.source/upload.
│ └─ Para normalizar / filtrar / resolver dispositivo → PayloadNormalizer + tests.
│
└─ Campo que necesita columna propia en BD → Migración SQL + 4-5 ficheros + tests.

---

## Checklist genérico antes de mergear un cambio de normalización

[ ] php -l en todos los ficheros tocados
[ ] vendor/bin/phpunit → sigue siendo 69+ tests, OK
[ ] Verificar que el nuevo campo llega correctamente en un payload de test local
[ ] Verificar con un ingest real post-deploy (phpMyAdmin)
[ ] Actualizar este documento si el escenario era nuevo
