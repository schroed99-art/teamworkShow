plugins {
    alias(libs.plugins.android.application)
}

// Single source of truth for the version: the repo-root VERSION file.
val appVersionName = rootProject.file("VERSION").readText().trim()
val appVersionParts = appVersionName.split(".").map { it.toIntOrNull() ?: 0 }
val appVersionCode = appVersionParts.getOrElse(0) { 0 } * 10000 +
    appVersionParts.getOrElse(1) { 0 } * 100 +
    appVersionParts.getOrElse(2) { 0 }

android {
    namespace = "com.example.teamworkshow"
    compileSdk {
        version = release(36) {
            minorApiLevel = 1
        }
    }

    defaultConfig {
        applicationId = "com.example.teamworkshow"
        minSdk = 26
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            optimization {
                enable = false
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
    implementation(libs.material)
    implementation(libs.androidx.media3.exoplayer)
    implementation(libs.androidx.media3.ui)
    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
}