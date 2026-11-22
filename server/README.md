<!-- Versión del archivo: 1.0.11 (2026-11-22) -->
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

Cada vez que se ejecuta `loading.html`, llama a `api/device.php` con `event=boot`. El servidor guarda una fila en `sp_boots`. **Solo `loading.html` escribe en el historial**: `config.html`, `connection.html` y `fingerprint.html` no envían `event`, así que sus consultas no cuentan como arranques.

**Última conexión**: la refresca **cualquier página que habla con el servidor** (`loading`, `config`, `connection` y `fingerprint` por `api/device.php`; `mirror` por `api/media.php`, al pedir la lista o descargar), además de la señal de la app nativa (`api/ping.php`). En `config.php`, `boot_history_days` (365) fija cuántos días se guardan y `boots_per_device_per_hour` (30) limita las filas si la app se reinicia en bucle. Al eliminar un equipo se borra también su historial.

## Usuarios

El administrador crea **usuarios** (empresas) en el panel: botón *Usuarios* → *Nuevo usuario*, con **empresa, email y teléfono**. El sistema genera una **contraseña temporal** que se muestra una sola vez para entregársela. En la base solo se guarda su hash.

- **El usuario** entra en `https://smartpizarra.cloud/cuenta/` con su email y esa contraseña. La primera vez está obligado a cambiarla (pide la actual, mínimo 8 caracteres). Después ve sus datos y **sus equipos** (alias, ID, estado y última conexión) y puede cambiar su contraseña cuando quiera. **No puede autorizar ni revocar**.
- **Asignar un equipo:** selector *Usuario* en la tarjeta del equipo (se guarda al elegirlo). Los equipos nuevos llegan *Sin asignar*.
- **Filtrar:** selector *Todos los usuarios / Sin asignar / …* junto al buscador, o *Ver equipos* en la vista de usuarios. El buscador también encuentra por empresa o email.
- **Uno a uno:** botones *Autorizar* / *Revocar* en la tarjeta de cada equipo (con *Ver equipos* salen solo los de ese usuario).
- **Todos a la vez:** botones *Autorizar todos sus equipos* y *Revocar todos sus equipos* en la ficha del usuario (y arriba de su lista de equipos). Cambian todos sus equipos, también los pendientes; cada uno lo detecta en su próxima consulta. Después se puede cambiar cualquiera individualmente.
- **Restablecer contraseña:** genera otra temporal. La anterior deja de funcionar y la sesión abierta del usuario se cierra.
- **Eliminar usuario:** sus equipos quedan *Sin asignar* **con su estado actual**. Si quieres cortarles el servicio, primero usa *Revocar todos sus equipos*.

Las APIs (`api/device.php`, `api/ping.php`) devuelven el estado que fijó el administrador, sin campos nuevos. No hace falta cambiar la app ni las páginas `.html`.

Conocer el ID no basta para consultar ni suplantar un equipo: hace falta el secreto. Si alguien registra un ID con otro secreto, la TV muestra un aviso de conflicto. Se resuelve eliminando ese registro en el panel.

## Contenido para las pantallas (mirror.html)

**Permiso del administrador:** un equipo solo muestra contenido si está **autorizado** y además tiene el **contenido habilitado**. En el panel cada equipo muestra la insignia *Contenido habilitado / no habilitado* y el botón *Habilitar contenido* / *Deshabilitar contenido*. Los equipos nuevos llegan sin el permiso. Sin él, `api/media.php` devuelve la lista vacía con `"mediaEnabled":false`, la descarga responde `403 media_disabled` y la TV muestra el aviso *Contenido no habilitado*. Lo ya descargado se conserva, así que al habilitarlo vuelve sin descargarse otra vez. El usuario ve en `/cuenta/` qué equipos tienen el permiso, pero no puede cambiarlo.

**Vigencia por usuario:** cada usuario tiene una fecha hasta la que sus pantallas muestran el contenido. Se gestiona en *Usuarios*, en el recuadro de vigencia de cada usuario (también aparece en la ficha de sus equipos):

- **+ Sumar N días:** si la vigencia está vencida o sin asignar, cuenta desde ahora. Si está vigente, alarga la fecha de fin.
- **− Restar N días:** acorta la fecha de fin y puede dejarla vencida.
- **Quitar vigencia:** deja al usuario sin vigencia.

El cambio llega a **todos los equipos del usuario** en su próxima consulta (≈ 1 min). Sin vigencia, `api/media.php` devuelve la lista vacía con `"contentActive":false`, la descarga responde `403 content_expired` y la TV muestra *Vigencia del contenido vencida*. Con vigencia, la API envía `"secondsLeft"` y la TV **deja de mostrar el contenido al vencer aunque no tenga internet**. Lo descargado se conserva para cuando se renueve. El usuario ve su vigencia en `/cuenta/` (Inicio y Mi cuenta), pero no puede cambiarla. Los usuarios empiezan **sin vigencia**.

**Acceso del administrador:** el botón *Contenido* del panel (`?view=media`) lista todo lo subido por los usuarios. Se puede filtrar con el selector de usuario o con *Ver contenido (N)* en la vista *Usuarios*. De cada archivo se ve el dueño, el tipo, el tamaño, los segundos, en qué equipos se muestra y la fecha de subida. Tiene tres botones:

- **Ver:** vista previa.
- **Descargar:** con su nombre original.
- **Eliminar:** borra la fila y el fichero, y las pantallas lo quitan en su próxima consulta.

El administrador no ordena ni reasigna archivos: eso lo hace su dueño.

Cada usuario sube **imágenes** (JPG, PNG, WEBP, GIF) y **videos** (MP4 H.264 o WEBM) en `https://smartpizarra.cloud/cuenta/`, sección *Contenido de mis pantallas*:

- **Mostrar en:** *Todos mis equipos* (incluye los que se le asignen después) o *Solo en:* los equipos marcados.
- **Orden:** botones *▲ Antes* / *▼ Después*. Las **imágenes** duran los segundos indicados (10 por defecto) y los **videos** lo que duren, siempre **sin sonido**.
- **Eliminar:** las pantallas lo quitan en su próxima consulta.

`mirror.html`, en la TV:

- consulta `api/media.php` cada **60 s**. Si nada cambió, la respuesta ocupa unos bytes (`"unchanged":true`);
- descarga **una sola vez** cada archivo nuevo y lo guarda en el propio equipo (Cache Storage del WebView);
- reproduce siempre desde ahí y borra lo retirado.

Sin internet sigue mostrando lo guardado. Si el equipo no está autorizado (pendiente o revocado), muestra un aviso con su ID en lugar del contenido. Lo descargado se conserva y vuelve a verse al autorizarlo, sin descargarlo otra vez.

Los archivos se guardan en `private/media/` (fuera de `public_html`). Solo los entregan `api/media.php`, a un equipo autorizado con su secreto, y `/cuenta/`, a su dueño con sesión. Al eliminar un usuario se borran sus archivos. Los límites (`media_max_mb`, `media_user_quota_mb`, …) están en `config.sample.php`. Para subir videos grandes, sube también `upload_max_filesize` y `post_max_size` en *hPanel → Avanzado → Configuración de PHP*.

**API (`POST api/media.php`, form-urlencoded):** `deviceId`, `secret` y `action=list` (opcional `rev`) o `action=file&id=N`. La lista devuelve `{"ok":true,"authorized":true,"revision":"…","items":[{"id","kind","mime","size","sha256","seconds"}]}`.

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
    lib.php                   Código común (base de datos, equipos, usuarios)
    web.php                   Utilidades comunes de admin/ y cuenta/ (sesión, CSRF, estilos)
    config.sample.php         Plantilla -> copiar como config.php
    .htaccess                 Bloquea el acceso web si acabara en public_html
  public_html/
    api/device.php            API para las TV box (páginas .html: registro y autorización)
    api/ping.php              Señal "online" de la app nativa Kodular
    api/media.php             Lista y descarga de imágenes/videos para mirror.html
    admin/index.php           Panel de administración
    cuenta/index.php          Página de los usuarios (equipos, contenido de mirror.html, contraseña)
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
     private/       <- lib.php, web.php, config.php, .htaccess
     public_html/
       api/         <- device.php, ping.php, media.php
       admin/       <- index.php
       cuenta/      <- index.php
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

Para los usuarios (si la tabla `sp_users` no existe todavía):

1. En la pestaña _SQL_ de phpMyAdmin ejecuta una vez `migrations/2026-11-08-users.sql`, después de las dos anteriores. Todos los equipos quedan *Sin asignar*. Si sale _Duplicate column name 'user_id'_, ya estaba hecha. Si llegaste a ejecutar la antigua migración de grupos, ejecuta también las 3 líneas comentadas del final del archivo.
2. Sube `private/lib.php`, `private/web.php` (nuevo), `api/device.php`, `api/ping.php`, `admin/index.php` y la carpeta nueva `cuenta/` con su `index.php`.

Para el contenido de `mirror.html` (si la tabla `sp_media` no existe todavía):

1. En la pestaña _SQL_ de phpMyAdmin ejecuta una vez `migrations/2026-11-08-media.sql`, después de la de usuarios. Se puede repetir sin riesgo.
2. Sube `private/lib.php`, `private/web.php`, `api/media.php` (nuevo), `admin/index.php` y `cuenta/index.php`. La carpeta `private/media/` se crea sola al subir el primer archivo. Si no tiene permiso, créala a mano junto a `lib.php`.
3. Opcional: añade a `config.php` las claves `media_*` de `config.sample.php` y ajusta `upload_max_filesize` / `post_max_size` de PHP.
4. Ejecuta después `migrations/2026-11-08-content-until.sql` (vigencia por usuario; todos empiezan sin vigencia: súmales días en *Usuarios*).
5. Ejecuta también `migrations/2026-11-08-media-enabled.sql` (permiso de contenido por equipo; todos empiezan sin él) y sube `admin/index.php`.
6. Sube `mirror.html` y el `loading.html` actual a los assets de Kodular. `loading.html` crea `smartpizarra.mirror.scale`, la API y las credenciales del equipo.

Para los **anuncios del administrador** (pestaña *Anuncios* del panel):

1. En la pestaña _SQL_ de phpMyAdmin ejecuta una vez `migrations/2026-11-11-ads.sql` (crea `sp_ads`, `sp_ad_users`, `sp_ad_devices`). Se puede repetir sin riesgo.
2. Sube `private/lib.php`, `api/media.php` y `admin/index.php`.
3. Sube el `mirror.html` nuevo a los assets de Kodular (el anterior ignora los anuncios).

Un anuncio es una imagen o un video que `mirror.html` intercala con los archivos del usuario. **Rotación en orden** (desde el 2026-11-12): los anuncios salen **de uno en uno en el orden de la lista del panel** (flechas ↑↓), y antes de cada uno pasan sus «cada N» archivos del usuario; por ejemplo, A cada 2 y B cada 3 da `U1 U2 A U3 U4 U5 B U6 U1 A…`. Al reordenar, las pantallas empiezan la vuelta con el orden nuevo en su próxima consulta (≈ 1 min). Los anuncios pausados, finalizados o que no van a ese equipo no ocupan turno. Para esto hay que subir el `mirror.html` nuevo a los assets de Kodular. Si el equipo no tiene contenido propio, vigencia o permiso de contenido, muestra solo los anuncios. Destino: todos los equipos, los equipos de ciertos usuarios (también los futuros) o equipos concretos. Fechas de inicio y fin opcionales, con la hora del navegador del administrador. Se puede pausar. Lo ven solo los equipos autorizados. El límite de tamaño es el de `media_max_mb` y el de PHP.

Para las **fechas del contenido de los usuarios** (permanente o desde/hasta en `/cuenta/`):

1. Ejecuta una vez `migrations/2026-11-11-media-dates.sql`. Si sale _Duplicate column name 'starts_at'_, ya estaba hecha.
2. Sube `private/lib.php`, `private/web.php`, `cuenta/index.php` y `admin/index.php`.

Para las **ligas** (pestaña *Ligas* del panel y pestaña *Equipos* de `/cuenta/`):

1. Ejecuta una vez `migrations/2026-11-11-leagues.sql` (crea `sp_league_types`, `sp_leagues`, `sp_user_leagues`). Se puede repetir sin riesgo.
2. Ejecuta una vez `migrations/2026-11-11-device-leagues.sql` (columna `sp_devices.league_id`). Si sale _Duplicate column name 'league_id'_, ya estaba hecha. Si llegaste a ejecutar la versión anterior (varias ligas por equipo), ejecuta también las 2 líneas comentadas del final.
3. Sube `private/lib.php`, `api/device.php`, `api/media.php`, `admin/index.php` y `cuenta/index.php`.
4. Sube a los assets de Kodular `loading.html`, `config.html`, `connection.html`, `fingerprint.html` y `mirror.html`.

Cómo funciona:

- Un **tipo de liga** tiene un código (`mlb`) y un nombre (`MLB`). Una **liga** tiene nombre, tipo y contenido: la lista de códigos (`LIDOM, MLB, KO`).
- El administrador asigna ligas a cada usuario en su ficha (puede tener varias).
- El **usuario**, en `/cuenta/` → *Equipos*, pone a cada uno de sus equipos **"Sin liga" o UNA** de esas ligas. La TV ya no tiene ligas fijas, `config.html` no tiene selector y `updateSettings` rechaza `league`.
- El **administrador** también puede elegir la liga de **cualquier equipo** desde su tarjeta en el panel (selector *Liga*, se guarda al cambiarlo), **siempre que el usuario dueño del equipo ya tenga una ruta y ligas asignadas**. Solo puede elegir entre las ligas de **ese usuario** (o "Sin liga"); la tarjeta muestra también la URL final que recibe el equipo. Si el usuario no tiene ruta o ligas, la tarjeta solo muestra la liga y el motivo.
- Desde el 2026-11-12 la TV **ya no recibe ni guarda la liga aparte**: sus códigos van **dentro de la URL de la ruta** del usuario (`leagues=...`, ver *Gestión de Rutas*). Al recibir la ruta, la TV borra las claves viejas `smartpizarra.league`, `smartpizarra.leagues` y `smartpizarra.leagueOptions`, y los JSON a la app ya no llevan `league`/`leagues` (usa `routeUrl`).
- Si el administrador le quita esa liga al usuario, o cambia el equipo de usuario, el equipo queda sin liga.

Para las **tiras y banners** (pestaña *Tiras* del panel y pestaña *Tiras y banners* de `/cuenta/`):

1. Ejecuta una vez `migrations/2026-11-11-overlays.sql` (crea `sp_overlays`, `sp_overlay_users`, `sp_overlay_devices`). Se puede repetir sin riesgo.
2. Sube `private/lib.php`, `private/web.php`, `api/media.php`, `admin/index.php` y `cuenta/index.php`.
3. Sube el `mirror.html` nuevo a los assets de Kodular (el anterior ignora las franjas).
4. Opcional, en `config.php`: `'overlay_max_mb' => 5` (tamaño máximo de la imagen de un banner) y `'overlays_per_user' => 20` (máximo por usuario). Si faltan, se usan esos valores.

Para el **ajuste de la imagen del banner** (proporcional o estirada a todo el ancho):

1. Ejecuta una vez `migrations/2026-11-11-overlays-fit.sql` (columna `sp_overlays.image_fit`). Si sale _Duplicate column name 'image_fit'_, ya estaba hecha.
2. Sube `private/lib.php`, `private/web.php` y el `mirror.html` nuevo a los assets de Kodular.

Cómo funciona:

- Un **banner** puede ser **proporcional** (no se deforma; si la imagen no es lo bastante ancha queda centrada) o **estirado a todo el ancho** (llena el ancho de la pantalla aunque se deforme en horizontal). Se elige al subirlo o al editarlo, en los dos paneles.
- Una **tira** es una franja con un mensaje (colores de fondo y de letra), **fija** o **desplazándose** (velocidad lenta, normal o rápida). Un **banner** es una franja con una imagen (JPG, PNG, WEBP o GIF; mejor ancha, p. ej. 1920×160).
- Va **arriba o abajo** de la pantalla de `mirror.html`, con tamaño pequeña, mediana o grande. El contenido se ajusta al espacio que queda (la franja no lo tapa). Solo se ve mientras hay contenido o anuncios en pantalla, no sobre los avisos.
- Se **crea sin equipos** ("Sin asignar": no se ve en ninguna pantalla) y después se **asigna** con «Asignar a equipos» en cada una, eligiendo a la vez su **tiempo de visibilidad**: permanente o con fechas (desde/hasta). Editarla (texto, colores, posición…) no cambia su asignación ni sus fechas.
- El **usuario** la asigna a todos sus equipos (también los futuros) o a algunos. Llega a sus equipos que muestran su contenido (autorizados, con el contenido habilitado y vigencia activa).
- El **administrador** asigna las suyas a todos los equipos, a los de ciertos usuarios o a equipos concretos. Llegan a cualquier equipo autorizado. También ve las de los usuarios y puede pausarlas o eliminarlas (no asignarlas ni editarlas).
- En cada pantalla se ve **una arriba y una abajo**: primero la del administrador, luego la más concreta (equipo > usuario > todos) y, si empatan, la editada más recientemente.
- La TV la recibe en la consulta de `mirror.html` (~60 s). El banner se descarga una sola vez. Si tiene fecha de fin, la TV la quita a su hora aunque no tenga internet.
- Una tira que se desplaza gasta CPU en el TV box (unos 40 % de un núcleo, medido); una fija, casi nada.

Para la **Gestión de Rutas** (botón *Rutas* del panel):

1. Ejecuta una vez `migrations/2026-11-12-routes.sql` (crea `sp_routes` y la columna `sp_users.route_id`). Si sale _Duplicate column name 'route_id'_, ya estaba hecha.
2. Sube `private/lib.php`, `admin/index.php`, `api/device.php` y `api/media.php`.
3. Sube a los assets de Kodular `loading.html`, `config.html`, `connection.html`, `fingerprint.html` y `mirror.html`.

Cómo funciona:

- Una **ruta** es una URL que se construye **por partes**, sin escribirla completa: URL base (p. ej. `https://sport.grupoazar.do/PizarrasV2`) + `agent` (texto), `pageIndex` (número), `timer` (número), `showParams` (texto), `otherQ` (Y/N) y `pageSize` (número). El formulario muestra la URL final mientras se escribe.
- El parámetro **`leagues`** no se escribe: se rellena en **cada equipo** con los códigos de su liga (la que eligió el usuario en `/cuenta/` → *Equipos* o el administrador en la tarjeta del equipo), p. ej. `leagues=LIDOM,MLB,LMP`. Sin liga queda `leagues=` vacío.
- Cada ruta se **asigna a uno o varios usuarios** («Asignar a usuarios»). Cada usuario tiene **una sola** ruta: si se le asigna otra, cambia. **Todos sus equipos** la heredan, también los que se le asignen después; no hace falta ir equipo por equipo.
- Los equipos autorizados reciben la URL final (`deviceRoute`) en cada consulta (`api/device.php` y `api/media.php`) y la guardan en `localStorage` (`smartpizarra.routeUrl`). **Sin ruta** (el servidor responde `null`, o la clave falta, está vacía o no es válida) la TV usa `http://localhost/none.html`: la pantalla **`none.html` de los assets de Kodular**, que dice que el equipo no puede mostrar el contenido y que se comunique con su supervisor, y que **consulta el servidor cada 30 s** y envía un JSON a la app tras cada consulta (`routeUrl`, `hasRoute`, `routeChanged`…): cuando se le asigne una ruta, la app recibe la URL nueva. `loading.html` la pone por defecto al arrancar, así la app siempre recibe una URL que abrir. Todas las páginas la envían a la app en su JSON como `"routeUrl"`. Si se edita la ruta, se quita al usuario o se borra, los equipos lo reciben en su siguiente consulta (~1 min con `mirror.html`).

Para la **dirección MAC de cada equipo**:

1. Ejecuta una vez `migrations/2026-11-12-device-mac.sql` (columnas `sp_devices.mac`, `mac_at`, `mac_from` e índice único). Si sale _Duplicate column name 'mac'_, ya estaba hecha.
2. Sube `private/lib.php`, `api/device.php` y `admin/index.php`.
3. Sube a los assets de Kodular `loading.html`.

Cómo funciona:

- La app (Kodular + extensión) lee la MAC y se la pasa a `loading.html` con `setMac("e8:bb:3e:c8:fb:5a")`; se guarda en `smartpizarra.mac` (sin dato: `FF:FF:FF:FF:FF:FF`).
- `loading.html` la envía a `api/device.php` en la consulta de arranque (`event=boot`). Si `setMac` recibe una MAC nueva después del arranque, la envía enseguida con `event=mac` (no cuenta como arranque en el historial). `FF:FF:FF:FF:FF:FF` y las MAC falsas de Android (`02:00:00:00:00:00`) no se envían ni se guardan.
- **Una MAC solo está en un equipo.** Si llega desde otro equipo (la misma TV restablecida de fábrica, que genera un ID nuevo), se le **quita al anterior y pasa al nuevo**. La tarjeta del nuevo muestra «antes en SP-…» con enlace al equipo anterior. El usuario, la ruta y la liga **no** se pasan solos: el nuevo equipo queda pendiente como cualquier alta.
- Solo un equipo con su secreto correcto puede asignarse una MAC.
- El **buscador del panel** también busca por MAC, sin importar `:`, `-`, `.` ni mayúsculas; vale un trozo (`fb5a`).

Para los **perfiles de equipo** (botón *Perfiles* del panel):

1. Ejecuta una vez `migrations/2026-11-12-device-profiles.sql` (tabla `sp_device_profiles` y columna `sp_devices.first_authorized_at`), **después** de la de la MAC. Si sale _Duplicate column name 'first_authorized_at'_, ya estaba hecha.
2. Sube `private/lib.php` y `admin/index.php`.

Cómo funciona:

- Un **perfil** es la ficha de la caja física: **marca, modelo, MAC que sale en el case y fecha de instalación**. Todos los campos son obligatorios. La MAC del case no se puede repetir entre perfiles y se escribe con `:`, con `-` o sin separadores. La fecha no puede ser futura.
- Se crea en *Perfiles* (con o sin equipo) o desde la tarjeta del equipo (*Crear perfil*, que abre el formulario con ese equipo ya elegido). Cada perfil está en **un solo equipo** y cada equipo tiene **un solo perfil**: al asignar uno, el que tenía el equipo queda libre, y si el perfil estaba en otro equipo pasa al nuevo.
- La tarjeta de un equipo sin perfil muestra un selector con los perfiles libres y **preselecciona el que tenga como MAC del case la MAC que envía la TV**. La MAC del case puede no coincidir con la que envía la TV (cable o wifi), por eso se guardan aparte.
- **Autorizar un equipo por primera vez exige perfil.** Sin perfil, el botón sale como *Autorizar (falta perfil)* desactivado y el servidor rechaza la autorización. *Autorizar todos sus equipos* se salta los que no tienen perfil y dice cuántos. Un equipo que **ya estuvo autorizado** alguna vez (también los que ya existían al aplicar la migración) se puede volver a autorizar sin perfil.
- **TV restablecida** (ID nuevo): busca su perfil por la MAC del case en *Perfiles* y asígnalo al equipo nuevo; con eso ya se puede autorizar.
- El **buscador de equipos** encuentra un equipo también por la **MAC del case** (completa o un trozo, sin importar `:` o `-`), la **marca** o el **modelo** de su perfil. *Perfiles* tiene su propio buscador.
- Borrar un equipo deja su perfil libre.
- El **usuario**, en `/cuenta/` → *Equipos*, ve la **MAC del case** de cada uno de sus equipos (*sin registrar* si aún no tiene perfil) y puede **buscar sus equipos** por esa MAC (completa o un trozo, sin importar `:` o `-`), por su nombre o por su ID. Solo encuentra los suyos. Para esto basta con subir `public_html/cuenta/index.php`.

Para el **alias y las notas del administrador**:

1. Ejecuta una vez `migrations/2026-11-12-device-admin-notes.sql` (columnas `sp_devices.admin_alias` y `admin_notes`). Si sale _Duplicate column name 'admin_alias'_, ya estaba hecha.
2. Sube `private/lib.php` y `admin/index.php`.

Cómo funciona:

- Cada equipo tiene, aparte del **alias** de siempre (que también ve y edita el usuario en `/cuenta/`), un **alias del administrador** y **notas** que **solo ve el administrador**. Se editan en la tarjeta del equipo → *Alias y notas del administrador*.
- El alias del administrador sale en la cabecera de la tarjeta con borde punteado, y las notas debajo de los datos del equipo.
- El **buscador del panel** encuentra el equipo por ese alias. El de `/cuenta/` **no**: el usuario no puede verlo ni encontrar un equipo por él, y la TV tampoco lo recibe.
- Las notas admiten varias líneas (máximo 2000 caracteres). Si dejas los dos campos vacíos, se borran.

**Buscador de dispositivos al asignar** y **"Dispositivos" en `/cuenta/`** (sube `private/web.php`, `admin/index.php` y `cuenta/index.php`; sin migración):

- En `/cuenta/` todos los textos dicen **dispositivo(s)** en lugar de equipo(s): pestaña *Dispositivos*, *Mis dispositivos*, *Todos mis dispositivos*, *Asignar a dispositivos*… La dirección sigue siendo `?s=equipos`, así que los enlaces guardados siguen funcionando.
- Las listas de casillas para elegir dispositivos tienen una **caja de búsqueda** que filtra mientras se escribe, con el título «Buscar dispositivo». En `/cuenta/` aparece siempre que el usuario tenga dispositivos (al subir y editar contenido, y en «Asignar a dispositivos» de cada tira y banner); en el panel del administrador, cuando hay más de un equipo.
  - Usuario (*Contenido* y *Tiras y banners*): por nombre, ID o MAC del case.
  - Administrador (*Anuncios* y *Tiras*): por alias, alias del administrador, ID, empresa, MAC que envía la TV o MAC del case.
  - La MAC se encuentra completa o por un trozo, sin importar `:` o `-`. Las casillas marcadas que quedan ocultas por el filtro **siguen marcadas**. Pulsar Enter en la caja no envía el formulario.

Para el **buzón de mensajes** (administrador ↔ usuario):

1. Ejecuta una vez `migrations/2026-11-12-messages.sql` (tabla `sp_messages`; repetirla no hace nada).
2. Sube `private/lib.php`, `private/web.php`, `admin/index.php` y `cuenta/index.php`.

Cómo funciona:

- Hay **una conversación por usuario**. El administrador la ve en el botón **Mensajes** del panel y el usuario en la pestaña **Mensajes** de `/cuenta/`.
- **Administrador:**
  - Ve la lista de conversaciones, con la más reciente arriba y los mensajes sin leer marcados.
  - Al abrir una conversación puede responder, ir a la ficha del usuario o borrarla.
  - *+ Nuevo mensaje* escribe a un usuario o a **todos los usuarios**; con «todos», el mensaje queda en la conversación de cada uno.
  - El botón *Mensajes* muestra cuántos mensajes de usuarios hay sin leer.
- **Usuario:**
  - Escribe y lee desde su pestaña *Mensajes*, que muestra un número cuando tiene mensajes nuevos. *Inicio* también lo avisa.
  - Solo puede escribir en **su** conversación, con un máximo de 30 mensajes por hora.
- **Lectura:** al abrir la conversación, los mensajes recibidos se marcan como leídos. Quien envía ve *Enviado* o *Leído* en cada mensaje suyo.
- Los mensajes admiten varias líneas, con un máximo de 2000 caracteres.
- Borrar un usuario borra también su conversación.

**Contenido habilitado en el JSON de todas las páginas** (2026-11-12): `api/device.php` devuelve también `"mediaEnabled": true|false`, el permiso de contenido que da el administrador en la tarjeta del equipo (`api/media.php` ya lo devolvía). Las 6 páginas de Kodular (`loading`, `config`, `connection`, `fingerprint`, `mirror` y `none`) lo guardan en `smartpizarra.mediaEnabled` (`"true"`/`"false"`; `loading.html` lo crea con `"false"` si no existe o no es válido) cada vez que el servidor responde y lo envían **siempre** en su JSON a la app como `"mediaEnabled": true|false` (`false` mientras no se sepa). Sube `api/device.php` y las 6 páginas a los assets de Kodular.

Para el **límite de archivos por usuario**:

1. Ejecuta una vez `migrations/2026-11-12-user-max-files.sql` (columna `sp_users.max_files`, por defecto 5). Si sale _Duplicate column name 'max_files'_, ya estaba hecha.
2. Sube `private/lib.php`, `admin/index.php` y `cuenta/index.php`.

Cómo funciona:

- Cada usuario puede tener como máximo **5 archivos** de contenido (imágenes o videos), incluidos los nuevos. El administrador cambia el límite en la **ficha del usuario → Contenido → Límite de archivos** (de 0 a 1000). La lista de usuarios y la ficha muestran `archivos/límite`.
- El **usuario** ve su límite en `/cuenta/`: en *Contenido* («Archivos: 3 de 5 · Puedes subir 2 más»), en el título *Mis archivos (3 de 5)* y en *Inicio*. Al llegar al límite se desactiva la subida y se le indica que borre alguno o pida más al administrador; el servidor también lo comprueba.
- Bajar el límite **no borra nada**: si el usuario tiene más archivos que su límite, simplemente no puede subir más hasta borrar.
- Este límite se suma al de espacio (`media_quota_mb`) y al de tamaño por archivo, que siguen igual.

Para **pausar el contenido** (como las tiras y los banners):

1. Ejecuta una vez `migrations/2026-11-12-media-active.sql` (columna `sp_media.active`; todo lo ya subido queda activo). Si sale _Duplicate column name 'active'_, ya estaba hecha.
2. Sube `private/lib.php`, `admin/index.php` y `cuenta/index.php`.

Cómo funciona:

- Cada archivo de contenido tiene el botón **Pausar** / **Activar**: el usuario lo ve en `/cuenta/` → *Contenido* (solo para sus archivos) y el administrador en *Contenido* del panel.
- Un archivo **pausado** muestra la insignia *Pausado*, deja de enviarse a las pantallas y `mirror.html` lo quita en su próxima consulta (≈ 1 min). **No se borra**: conserva su orden, dispositivos y fechas, y al activarlo vuelve a mostrarse. La pantalla lo descarga de nuevo al reactivarlo.
- Un archivo pausado **sigue contando** para el límite de archivos del usuario.

**Nombre del dispositivo en las pantallas** (2026-11-13; sube `api/device.php`, `api/media.php` y las 6 páginas a Kodular; sin migración): `api/device.php` y `api/media.php` devuelven `"deviceName"`, el nombre del equipo en el panel (el que pone el usuario en `/cuenta/` → *Dispositivos* o el administrador como alias; `""` si no tiene). **Nunca** el alias del administrador ni sus notas. Las 6 páginas lo guardan en `smartpizarra.deviceName` cada vez que el servidor responde; `loading`, `config`, `connection` y `fingerprint` lo muestran junto a la versión, la web y el email («Nombre: Local Centro» / «Name: …»). Sin nombre, la línea no aparece.

## Imágenes reducidas y miniaturas — 2026-11-17

- **Al subir** una imagen (contenido, anuncio o banner) de más de 1920×1080, el servidor la reduce a 1080×1920 (vertical) o 1920×1080 (horizontal), con el mismo formato y el giro de las fotos del móvil ya aplicado. Los GIF no se tocan. Motivo: había fotos de 5698×10121 (58 Mpx), que en la TV ocupan ~230 MB de memoria cada una.
- **Miniaturas**: las listas del panel y de `/cuenta/` usan una JPG pequeña (≤ 320×180) que se crea la primera vez y se guarda en `private/media/thumbs/`. Antes cargaban la foto original y la página se quedaba "cargando".
- **Descargas sin bloqueo**: al enviar un archivo se libera la sesión, así una descarga lenta no frena las demás páginas.
- **Imágenes ya subidas**: por SSH, `cd ~/domains/smartpizarra.cloud/private && php shrink_images.php` (muestra) y `php shrink_images.php --apply` (las reduce y guarda los originales en `private/backup-originales-AAAAMMDD/`). Las pantallas descargan solas la versión nueva.
- Requiere la extensión **GD** de PHP (Hostinger la trae). Sube `private/lib.php`, `admin/index.php` y `cuenta/index.php`; sin migración.

## Videos que las pantallas pueden mostrar fluidos — 2026-11-13

Medido en el TV box: los MP4 de **60 fps** pierden el 60–75 % de los fotogramas (se ven a saltos); los de 24–30 fps van fluidos. Por eso, al **subir** un video MP4 (contenido del usuario en `/cuenta/` y anuncios del panel) el servidor lee su cabecera y lo **rechaza** con un mensaje que explica cómo exportarlo si:

- tiene **más de 30 fps**;
- usa un **nivel H.264 mayor que 4.1**;
- está en **H.265/HEVC** (el WebView no lo reproduce);
- mide **más de 1920×1080** (en vertical u horizontal).

Recomendado: H.264, 30 fps constantes, nivel 4.0, hasta 1920×1080, unos 4–5 Mbps, sin audio. Con ffmpeg:
`ffmpeg -i original.mp4 -vf fps=30 -c:v libx264 -profile:v high -level 4.0 -crf 23 -maxrate 5M -bufsize 10M -movflags +faststart -an pantalla.mp4`.
Si la cabecera no se puede leer, el video se acepta (no se bloquea un archivo válido por un formato raro). Los WEBM no se comprueban. Los videos que ya estaban subidos no se tocan: los de 60 fps hay que volver a exportarlos y subirlos. Sube `private/lib.php`, `cuenta/index.php` y `admin/index.php` (sin migración).

## Muchos equipos (cientos de pantallas) — 2026-11-13

Para que el servidor trabaje fluido con cientos de pantallas:

1. Ejecuta una vez `migrations/2026-11-13-download-queue.sql` (tablas `sp_download_queue` y `sp_kv`; repetirla no hace nada).
2. Sube `private/lib.php`, `api/media.php` y `admin/index.php`.
3. Sube a los assets de Kodular `mirror.html`, `none.html` y `fingerprint.html`.
4. En tu `private/config.php` del servidor (no está en el proyecto) añade o cambia:
   - `'register_per_ip_per_hour' => 200,` (antes 30: equipos nuevos por IP y hora; súbelo si instalas muchas TV en un mismo local).
   - `'download_slots' => 10,` (pantallas que descargan a la vez; súbelo si hPanel → *Uso de recursos* va holgado, bájalo si aparecen errores 503).
   - `'download_turn_seconds' => 60,` (opcional: duración de un turno hasta que la cola aprende la real).
5. En Kodular, pon el Clock de `ping.php` en 60 s o más.

Cómo funciona:

- **Cola de descargas con turnos.** Cuando hay contenido nuevo, como mucho `download_slots` pantallas lo descargan a la vez; las demás esperan su turno **por orden de llegada**. Cada pantalla pide turno (`api/media.php` `action=turn`); si no le toca, el servidor le dice su puesto y cuánto esperar antes de volver a preguntar (proporcional al puesto: la 400 vuelve mucho más tarde que la 15, así esperar no carga el servidor). Con turno descarga **todo** lo que le falte y lo libera (`action=done`); en cuanto una termina entra la siguiente. Los turnos abandonados (pantalla apagada) caducan solos. La duración real de un turno se **aprende** (media móvil) para que las esperas se ajusten a tu conexión y al tamaño de tus archivos. Una descarga sin turno recibe `503 busy` + `Retry-After` (las versiones viejas de `mirror.html` entran así en la cola).
- **Pantallas desincronizadas.** Cada consulta lleva un margen al azar (±10 %); tras arrancar o al volver internet, `mirror.html` espera 0–20 s al azar si ya tiene algo que mostrar (después de un apagón no llegan todas en el mismo segundo).
- **Espera creciente si el servidor falla.** `mirror.html`: 1 → 2 → 4 → 8 → 10 min (o lo que pida el servidor con `Retry-After`). `none.html`: sin respuesta 5 → 10 → 20 → 40 → 60 s (el aviso de red de Android sigue comprobando al instante), con error 5xx 30 s → 5 min. `fingerprint.html`: pendiente cada 10 s ± 20 %, sin respuesta 30 s → 5 min.
- **Menos escrituras.** La «última conexión» solo se escribe si pasaron 30 s desde la anterior o cambió la versión o la IP.
- **Panel.** La lista de dispositivos va **paginada** (50 por página) y ya no consulta la base por cada tarjeta; arriba se ve el estado de la **cola de descargas** (descargando, esperando, tiempo estimado).

Medido en local con 500 equipos: panel «Todos» de 704 consultas / 312 ms / 3,8 MB a **19 consultas / 85 ms / 415 KB**; la 2.ª ronda de consultas de las pantallas pasa de 500 escrituras a **0**; 500 pantallas con un archivo nuevo, 10 a la vez: nunca más de 10 descargando, todas terminan en ~33 min simulados (descargas de 10–60 s) y cada una pregunta de media 4 veces mientras espera.

Si falta algún paso, el panel lo indica en pantalla ("Problema de instalación: …").

## Agente de las TV (launcher que instala APK a distancia) — 2026-11-16

El agente (`cloud.smartpizarra.agent`, carpeta `agent/` del proyecto, ver `agent/README.md`) es el launcher (HOME) de cada TV box. Pregunta al servidor qué debe tener instalado, instala, actualiza o desinstala APK sin que nadie toque la TV, ejecuta comandos firmados y abre la app principal. Se gestiona en **/admin/agentes.php** (botón *Agentes* del panel).

Instalación en el servidor (una vez):

1. Ejecuta `migrations/2026-11-16-agent.sql` en phpMyAdmin (repetirla no hace nada).
2. Sube `private/agent.php`, `api/agent.php`, `admin/agentes.php` y `admin/index.php`.
3. **Clave para firmar los comandos remotos.** La pública ya va dentro del APK (`agent/keys/command_public.pem`); la privada está en tu PC en `server/private/agent_command_key.pem` (no va a git). Súbela a `private/` del servidor, **nunca** a `public_html`. Si un día creas una nueva (`openssl ecparam -name prime256v1 -genkey -noout -out agent_command_key.pem` y `openssl ec -in agent_command_key.pem -pubout -out command_public.pem`), la pública nueva debe ir en `agent/keys/` y hay que publicar una versión nueva del agente **antes** de cambiar la privada del servidor.
4. En `private/config.php` puedes añadir (si faltan se usan estos valores): `'agent_download_slots' => 10`, `'agent_slots_per_ip' => 2`, `'agent_turn_seconds' => 120`, `'agent_apk_max_mb' => 300`. En hPanel → PHP, `upload_max_filesize` y `post_max_size` deben admitir el APK más grande que vayas a subir.

Uso del panel:

- **Equipos.** Un equipo nuevo aparece como *Pendiente*: autorízalo (mismo criterio que los dispositivos). En su ficha: grupo, nombre, giro de pantalla, inventario de apps, resultados y comandos. **Eliminar** (en la lista y en la ficha) lo quita del panel con su inventario y resultados; si la TV sigue encendida con el agente vuelve a aparecer como *Pendiente* (para bloquearla de verdad usa *Revocar*).
- **Grupos.** Cada grupo define cada cuánto consulta, el texto y los colores de la pantalla de mantenimiento, el porcentaje, la ventana horaria de instalación (hora del equipo), si la pantalla se muestra también al descargar y el **giro** (0/90/180/270, para TV en vertical; un equipo puede tener el suyo). El grupo **no pide paquete**: se crea sin app principal y la tarjeta del grupo muestra cuál tiene.
- **APK.** Sube el archivo; el paquete y la versión se leen del propio APK. **Despliegue** = instalar/actualizar ese APK o desinstalar un paquete en un grupo, con % de equipos (siempre los mismos), pausa y "Reintentar fallidos". Solo se descarga si el equipo tiene una versión inferior. En *Nuevo despliegue* ya viene elegido el último APK de la app principal del grupo (o, si no tiene, el último subido que no sea el agente) y el **Paquete se rellena solo** con el del APK (no se puede editar al instalar). La casilla **App principal del grupo** (marcada si el grupo aún no tiene o si es su APK) hace que ese paquete sea el que el agente abre cuando no hay tareas y mantiene instalado. Desinstalar la app principal deja el grupo sin ella. Para desinstalar, el campo sugiere las apps instaladas en los equipos del grupo (con en cuántos están).
- **El propio agente se actualiza igual**: sube su APK nuevo (versionCode mayor, misma firma) y despliégalo. Android cierra el agente al instalarse y lo vuelve a abrir; el agente vuelve a poner la app principal en pantalla (probado 2026-11-16 en la .137: ~20–40 s).
- **Comandos** (lista blanca, firmados, caducan): reiniciar, resolución, animaciones, borrar datos o forzar cierre de una app, reiniciar la app principal, diagnóstico.
- **Logo** de la pantalla de mantenimiento: pestaña de ajustes.

Escala: el manifiesto lleva ETag (grupo + revisión + comandos), así una consulta sin cambios es un `304` sin lecturas pesadas; publicar una versión para miles de TV es UNA fila. Las descargas usan la misma cola con turnos que el contenido, con un límite extra por IP pública para no saturar la conexión de un local.

## Notas

- La TV llama a la API desde `http://localhost` (el WebViewer de Kodular). Ese origen ya está en `allowed_origins`.
- La dirección de la API está en `loading.html` (setting `apiUrl`). Si cambias el dominio, cambia ese valor por defecto: se aplica a todos los equipos.
- El escáner usa la cámara del móvil y necesita HTTPS (Hostinger lo da). En Android/Chrome usa el lector del navegador. En iPhone carga la librería jsQR 1.4.0 desde jsDelivr, con hash de integridad.
- La bandera vive en el `localStorage` del equipo. Alguien con acceso técnico al TV box (adb) podría cambiarla. Si algún contenido debe protegerse de verdad, el servidor que lo entrega debe comprobar el estado del equipo, no la bandera local.
