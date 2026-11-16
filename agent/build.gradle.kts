// Versión del archivo: 1.0.0 (2026-11-16)
// Plugins del proyecto. Sin dependencias externas en la app: solo la plataforma Android
// (HttpURLConnection, org.json, SQLite, PackageInstaller) para que el APK sea pequeño
// y gaste poca RAM en las TV box de 1 GB.
plugins {
    id("com.android.application") version "8.5.2" apply false
    id("org.jetbrains.kotlin.android") version "1.9.24" apply false
}
