// Versión del archivo: 1.0.0 (2026-11-16)
// Proyecto Gradle del AGENTE de gestión remota (launcher HOME) para las TV box.
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "SmartPizarraAgente"
include(":app")
