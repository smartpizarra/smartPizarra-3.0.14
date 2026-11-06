# Smart Pizarra · registro y autorización de dispositivos

Servidor para `https://smartpizarra.cloud/` (Hostinger, PHP 8.1 o superior + MySQL).

## Cómo funciona

1. `loading.html` crea en la TV box un **ID** (`SP-XXXX-XXXX-XXXX`, el que se ve en pantalla y en el QR) y un **secreto** (nunca se muestra).
2. `fingerprint.html` (y `loading.html` al arrancar) llaman a `api/device.php` con ID + secreto:
   - Un equipo nuevo queda registrado como **pendiente**.
   - Mientras está pendiente, `fingerprint.html` pregunta cada 10 s.
3. Entras en `https://smartpizarra.cloud/admin/`, **escaneas el QR** de la TV con el móvil (botón *Escanear QR*) o buscas el ID, y pulsas **Autorizar**.
4. En unos segundos la TV muestra *Dispositivo autorizado ✓*, guarda `smartpizarra.authorized = "1"` y envía a la app `{"origin":"fingerPrint","action":"authorized",...}`.
5. Si pulsas **Revocar**, la TV pone la bandera en `"0"`. Lo detecta en el siguiente arranque (`loading.html`) o, con `fingerprint.html` abierta, en un máximo de 30 min.

En el panel cada equipo muestra:
- su **alias** (lo escribes tú en el campo *Alias* y pulsas *Guardar*; también sirve para buscar);
- **Registrado el** fecha y hora en que entró en la base (en la hora local de tu móvil/PC);
- el estado de la **app nativa**: `● En línea · hace 20 s`, `● Desconectado · hace 3 h` o `Sin señal de la app`. Se recalcula solo cada 30 s.

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

Conviene enviar la señal también al arrancar la app, no solo con el Clock. En `config.php`, `online_timeout_seconds` (180 por defecto) marca cuándo el panel pasa a *Desconectado*: unas 2-3 veces el intervalo del Clock.

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

1. **Base de datos.** En hPanel ve a *Bases de datos → Administración de MySQL*, crea una base y un usuario, y anota el nombre de la base, el usuario y la contraseña.
2. **Tablas.** Abre *phpMyAdmin*, elige esa base, ve a la pestaña *Importar* y sube `schema.sql`.
3. **Contraseña del panel.** En tu PC (con XAMPP) genera el hash:
   ```
   C:\xampp\php\php.exe -r "echo password_hash('TU-CONTRASEÑA', PASSWORD_DEFAULT);"
   ```
4. **Configuración.** Copia `private/config.sample.php` como `private/config.php` y rellena:
   - `db`: los datos del paso 1;
   - `admin_user`;
   - `admin_password_hash`: el resultado del paso 3.

   `config.php` no va a git.
5. **Subir archivos.** Usa el *Administrador de archivos* de Hostinger, con esta estructura:
   ```
   domains/smartpizarra.cloud/
     private/       <- lib.php, config.php, .htaccess
     public_html/
       api/         <- device.php, ping.php
       admin/       <- index.php
   ```
   La carpeta `private/` va **al lado** de `public_html`, no dentro.
6. **PHP.** Comprueba en hPanel (*Avanzado → Configuración de PHP*) que la versión es 8.1 o superior.
7. **Comprobar.** Abre `https://smartpizarra.cloud/admin/` y entra. Después abre `fingerprint.html` en la TV: el equipo debe aparecer en *Pendientes*.

## Actualizar un servidor ya instalado

Si la tabla `sp_devices` ya existía antes del 06/11/2026:
1. En phpMyAdmin, abre tu base, ve a la pestaña *SQL* y ejecuta una vez `migrations/2026-11-06-online.sql`. Añade la columna `online_at`. Si sale *Duplicate column name*, ya estaba hecha.
2. Sube `lib.php`, `api/ping.php` y `admin/index.php`.
3. Opcional: añade a tu `config.php` la línea `'online_timeout_seconds' => 180,` (si falta, se usa 180).

## Notas

- La TV llama a la API desde `http://localhost` (el WebViewer de Kodular). Ese origen ya está en `allowed_origins`.
- La dirección de la API está en `loading.html` (setting `apiUrl`). Si cambias el dominio, cambia ese valor por defecto: se aplica a todos los equipos.
- El escáner usa la cámara del móvil y necesita HTTPS (Hostinger lo da). En Android/Chrome usa el lector del navegador. En iPhone carga la librería jsQR 1.4.0 desde jsDelivr, con hash de integridad.
- La bandera vive en el `localStorage` del equipo. Alguien con acceso técnico al TV box (adb) podría cambiarla. Si algún contenido debe protegerse de verdad, el servidor que lo entrega debe comprobar el estado del equipo, no la bandera local.
