# Smart Pizarra · registro y autorización de dispositivos

Servidor para `https://smartpizarra.cloud/` (Hostinger, PHP 8.1 o superior + MySQL).

## Cómo funciona

1. `loading.html` crea en la TV box un **ID** (`SP-XXXX-XXXX-XXXX`, el que se ve en pantalla y en el QR) y un **secreto** (nunca se muestra).
2. `fingerprint.html` (y `loading.html` al arrancar) llaman a `api/device.php` con ID + secreto:
   - Un equipo nuevo queda registrado como **pendiente**.
   - Mientras está pendiente, `fingerprint.html` pregunta cada 10 s.
3. Entras en `https://smartpizarra.cloud/admin/`, **escaneas el QR** de la TV con el móvil (botón _Escanear QR_) o buscas el ID, y pulsas **Autorizar**.
4. En unos segundos la TV muestra _Dispositivo autorizado ✓_, guarda `smartpizarra.authorized = "1"` y envía a la app `{"origin":"fingerPrint","action":"authorized",...}`.
5. Si pulsas **Revocar**, la TV pone la bandera en `"0"`. Lo detecta en el siguiente arranque (`loading.html`) o, con `fingerprint.html` abierta, en un máximo de 30 min.

En el panel cada equipo muestra:

- su **alias** (lo escribes tú en el campo _Alias_ y pulsas _Guardar_; también sirve para buscar);
- **Registrado el** fecha y hora en que entró en la base (en la hora local de tu móvil/PC);
- **Última conexión**: `● En línea · hace 20 s`, `● Desconectado · hace 3 h` o `Sin conexión registrada`. La refrescan cada arranque de `loading.html` y la señal de la app nativa (`api/ping.php`). Se recalcula sola cada 30 s;
- **Arranques**: cuántas veces se ejecutó `loading.html` en ese equipo y la fecha del último. El botón **Historial** abre la lista de arranques (fecha y hora, IP y versión; los 300 más recientes).

Cada vez que se ejecuta `loading.html`, llama a `api/device.php` con `event=boot`. El servidor guarda una fila en `sp_boots` y actualiza la última conexión. `fingerprint.html` no envía `event`, así que sus consultas no cuentan como arranques. En `config.php`, `boot_history_days` (365) fija cuántos días se guardan y `boots_per_device_per_hour` (30) limita las filas si la app se reinicia en bucle. Al eliminar un equipo se borra también su historial.

## Grupos

Todo equipo pertenece a **un grupo**. Los equipos nuevos entran en el grupo por defecto, **General** (id 1), que se puede renombrar y deshabilitar pero no eliminar.

- **Un solo equipo:** botón *Revocar* en su tarjeta. Vuelve con *Autorizar*.
- **Un grupo entero:** botón *Grupos* → *Deshabilitar grupo*. Todos sus equipos autorizados dejan de estarlo en su próxima consulta. Su estado no cambia: al pulsar *Habilitar grupo* vuelven a funcionar sin tocarlos uno a uno. En la lista salen como *Autorizado · bloqueado por grupo*.
- **Mover un equipo:** selector de grupo en su tarjeta (se guarda al elegirlo).
- **Filtrar:** selector *Todos los grupos* junto al buscador, o *Ver equipos* en la vista de grupos.
- **Eliminar un grupo:** sus equipos pasan a *General* con el estado que tenían. Si el grupo estaba deshabilitado, esos equipos vuelven a quedar autorizados.

Un equipo autorizado de un grupo deshabilitado recibe de `api/device.php` y `api/ping.php` `"status":"revoked","authorized":false,"reason":"group_disabled"`. Las páginas `.html` lo tratan como revocado sin cambios en su código. Ambas APIs devuelven además `"group"` (nombre) y `"groupEnabled"`.

Conocer el ID no basta para consultar ni suplantar un equipo: hace falta el secreto. Si alguien registra un ID con otro secreto, la TV muestra un aviso de conflicto. Se resuelve eliminando ese registro en el panel.

## Señal "online" desde la app nativa de Kodular

Solo la envía la app nativa (no las páginas `.html`). La app toma `deviceId` y `deviceSecret` del JSON que `loading.html` le manda por `WebViewString` y los guarda (TinyDB o variables globales).

**Petición:** `POST https://smartpizarra.cloud/api/ping.php`, con cuerpo `deviceId=SP-XXXX-XXXX-XXXX&secret=<deviceSecret>&version=3.0.14` (también acepta JSON con esos campos).

**Bloques en Kodular:**

1. Componentes: `Web_Ping` (Url `https://smartpizarra.cloud/api/ping.php`) y `Clock_Ping` (TimerInterval `60000`, TimerAlwaysFires activado).
2. `Clock_Ping.Timer`, si `deviceId` y `deviceSecret` no están vacíos:
   - `Web_Ping.RequestHeaders` ← lista con el par `Content-Type` / `application/x-www-form-urlencoded`;
   - `Web_Ping.PostText` con `Web_Ping.BuildRequestData` de la lista de pares `deviceId`, `secret`, `version`.
3. Opcional, en `Web_Ping.GotText`: si `responseCode` = 200, decodifica el JSON. Si `authorized` = false, el equipo fue revocado. Con 404 (`not_registered`) el equipo fue eliminado en el panel: al abrir `loading.html` se vuelve a registrar.

**Respuestas:** `200 {"ok":true,"status":"authorized","authorized":true,...}`; `400` datos inválidos; `403` el secreto no coincide; `404` el ID no está en la base.

Conviene enviar la señal también al arrancar la app, no solo con el Clock. En `config.php`, `online_timeout_seconds` (180 por defecto) marca cuándo el panel pasa a _Desconectado_: unas 2-3 veces el intervalo del Clock.

## Archivos

```
server/
  schema.sql                  Tablas (se importan una vez)
  migrations/                 Cambios para una base ya creada (ver "Actualizar")
  private/                    FUERA de public_html
    lib.php                   Código común
    config.sample.php         Plantilla -> copiar como config.php
    .htaccess                 Bloquea el acceso web si acabara en public_html
  public_html/
    api/device.php            API para las TV box (páginas .html: registro y autorización)
    api/ping.php              Señal "online" de la app nativa Kodular
    admin/index.php           Panel de administración
```

## Instalación en Hostinger (una vez)

1. **Base de datos.** En hPanel ve a _Bases de datos → Administración de MySQL_, crea una base y un usuario, y anota el nombre de la base, el usuario y la contraseña.
2. **Tablas.** Abre _phpMyAdmin_, elige esa base, ve a la pestaña _Importar_ y sube `schema.sql`.
3. **Contraseña del panel.** En tu PC (con XAMPP) genera el hash:
   ```
   C:\xampp\php\php.exe -r "echo password_hash('TU-CONTRASEÑA', PASSWORD_DEFAULT);"
   ```
4. **Configuración.** Copia `private/config.sample.php` como `private/config.php` y rellena:
   - `db`: los datos del paso 1;
   - `admin_user`;
   - `admin_password_hash`: el resultado del paso 3.

   `config.php` no va a git.

5. **Subir archivos.** Usa el _Administrador de archivos_ de Hostinger, con esta estructura:
   ```
   domains/smartpizarra.cloud/
     private/       <- lib.php, config.php, .htaccess
     public_html/
       api/         <- device.php, ping.php
       admin/       <- index.php
   ```
   La carpeta `private/` va **al lado** de `public_html`, no dentro.
6. **PHP.** Comprueba en hPanel (_Avanzado → Configuración de PHP_) que la versión es 8.1 o superior.
7. **Comprobar.** Abre `https://smartpizarra.cloud/admin/` y entra. Después abre `fingerprint.html` en la TV: el equipo debe aparecer en _Pendientes_.

## Actualizar un servidor ya instalado

Si la tabla `sp_devices` ya existía antes del 06/11/2026:

1. En phpMyAdmin, abre tu base, ve a la pestaña _SQL_ y ejecuta una vez `migrations/2026-11-06-online.sql`. Añade la columna `online_at`. Si sale _Duplicate column name_, ya estaba hecha.
2. Sube `lib.php`, `api/ping.php` y `admin/index.php`.
3. Opcional: añade a tu `config.php` la línea `'online_timeout_seconds' => 180,` (si falta, se usa 180).

Para el historial de arranques (si la tabla `sp_boots` no existe todavía):

1. En la pestaña _SQL_ de phpMyAdmin ejecuta una vez `migrations/2026-11-06-boots.sql`. Se puede repetir sin riesgo.
2. Sube `lib.php`, `api/device.php` y `admin/index.php`, y el `loading.html` nuevo a los assets de Kodular (recompila la app).
3. Opcional: añade a tu `config.php` `'boot_history_days' => 365,` y `'boots_per_device_per_hour' => 30,` (si faltan, se usan esos valores).

Sube primero la tabla y después `lib.php`: el panel la consulta y sin ella da error.

Para los grupos (si la tabla `sp_groups` no existe todavía):

1. En la pestaña _SQL_ de phpMyAdmin ejecuta una vez `migrations/2026-11-07-groups.sql`, después de las dos anteriores. Todos los equipos quedan en el grupo *General*. Si sale *Duplicate column name 'group_id'*, ya estaba hecha.
2. Sube `lib.php`, `api/device.php`, `api/ping.php` y `admin/index.php`. No hace falta cambiar nada en la app ni en las páginas `.html`.

Si falta algún paso, el panel lo indica en pantalla ("Problema de instalación: …").

## Notas

- La TV llama a la API desde `http://localhost` (el WebViewer de Kodular). Ese origen ya está en `allowed_origins`.
- La dirección de la API está en `loading.html` (setting `apiUrl`). Si cambias el dominio, cambia ese valor por defecto: se aplica a todos los equipos.
- El escáner usa la cámara del móvil y necesita HTTPS (Hostinger lo da). En Android/Chrome usa el lector del navegador. En iPhone carga la librería jsQR 1.4.0 desde jsDelivr, con hash de integridad.
- La bandera vive en el `localStorage` del equipo. Alguien con acceso técnico al TV box (adb) podría cambiarla. Si algún contenido debe protegerse de verdad, el servidor que lo entrega debe comprobar el estado del equipo, no la bandera local.
