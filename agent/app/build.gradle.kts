// Versión del archivo: 1.0.1 (2026-11-17)
// App del agente. minSdk 24 (Android 7), targetSdk 34.
//  - DEFAULT_SERVER: URL de la API (se puede cambiar en el equipo por adb, ver README).
//  - COMMAND_PUBLIC_KEY: clave pública EC P-256 con la que el agente comprueba la firma
//    de los comandos remotos (keys/command_public.pem; la privada solo está en el servidor).
//  - Firma release: signing/keystore.properties (NO va a git). Sin ese archivo el
//    release sale sin firmar.
//  - debug: android:testOnly="true" (src/debug/AndroidManifest.xml) para poder quitarle el
//    modo Device Owner por adb en pruebas, y permite http (servidor local de pruebas).
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val signProps = Properties().apply {
    val f = rootProject.file("signing/keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

// Clave pública de los comandos en una sola línea base64 (sin las líneas -----BEGIN/END-----)
val commandKey: String = rootProject.file("keys/command_public.pem").let { f ->
    if (f.exists()) f.readLines().filter { it.isNotBlank() && !it.startsWith("-----") }.joinToString("") else ""
}

android {
    namespace = "cloud.smartpizarra.agent"
    compileSdk = 34

    defaultConfig {
        applicationId = "cloud.smartpizarra.agent"
        minSdk = 24
        targetSdk = 34
        // Cada versión publicada del agente necesita un versionCode MAYOR (gradle -PagentVersionCode=N
        // lo cambia sin editar este archivo, útil para probar la autoactualización)
        // 2 (1.0.1, 2026-11-17): adb local fija el puerto tras reiniciar y reintenta al minuto tras el aviso de depuración
        versionCode = (findProperty("agentVersionCode") as String?)?.toInt() ?: 2
        versionName = "1.0.1" +((findProperty("agentVersionCode") as String?)?.let { "-b$it" } ?: "")
        buildConfigField("String", "DEFAULT_SERVER", "\"https://smartpizarra.cloud/api/agent.php\"")
        buildConfigField("String", "COMMAND_PUBLIC_KEY", "\"$commandKey\"")
    }

    signingConfigs {
        create("release") {
            if (signProps.isNotEmpty()) {
                storeFile = rootProject.file(signProps.getProperty("storeFile"))
                storePassword = signProps.getProperty("storePassword")
                keyAlias = signProps.getProperty("keyAlias")
                keyPassword = signProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (signProps.isNotEmpty()) signingConfig = signingConfigs.getByName("release")
            manifestPlaceholders["cleartext"] = "false"
        }
        debug {
            manifestPlaceholders["cleartext"] = "true"
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures { buildConfig = true }
    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
}
