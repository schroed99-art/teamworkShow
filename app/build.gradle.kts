import java.io.FileInputStream
import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
}

// Release signing credentials live in keystore.properties (NOT committed).
// Copy keystore.properties.example -> keystore.properties and fill it in.
val keystorePropsFile = rootProject.file("keystore.properties")
val keystoreProps = Properties().apply {
    if (keystorePropsFile.exists()) load(FileInputStream(keystorePropsFile))
}

// Single source of truth for the version: the repo-root VERSION file.
val appVersionName = rootProject.file("VERSION").readText().trim()
val appVersionParts = appVersionName.split(".").map { it.toIntOrNull() ?: 0 }
val appVersionCode = appVersionParts.getOrElse(0) { 0 } * 10000 +
    appVersionParts.getOrElse(1) { 0 } * 100 +
    appVersionParts.getOrElse(2) { 0 }

android {
    namespace = "de.teamworkshow.app"
    compileSdk {
        version = release(36) {
            minorApiLevel = 1
        }
    }

    defaultConfig {
        // Permanent Play Store app identity — cannot be changed after first upload.
        applicationId = "de.teamworkshow.app"
        minSdk = 26
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Baked-in backend base URL so paired devices need no manual URL entry.
        // Override per build: ./gradlew ... -Pteamwork.serverUrl=https://…
        val serverUrl = (project.findProperty("teamwork.serverUrl") as String?)
            ?: "https://teamworkshow.itandmedia-solution.de"
        buildConfigField("String", "SERVER_URL", "\"$serverUrl\"")
    }

    buildFeatures {
        buildConfig = true
    }

    signingConfigs {
        create("release") {
            if (keystorePropsFile.exists()) {
                storeFile = rootProject.file(keystoreProps["storeFile"] as String)
                storePassword = keystoreProps["storePassword"] as String
                keyAlias = keystoreProps["keyAlias"] as String
                keyPassword = keystoreProps["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            optimization {
                enable = false
            }
            // Signed only when keystore.properties is present (local release builds).
            if (keystorePropsFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
}

dependencies {
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.constraintlayout)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.core.splashscreen)
    implementation(libs.material)
    implementation(libs.androidx.media3.exoplayer)
    implementation(libs.androidx.media3.ui)
    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
}