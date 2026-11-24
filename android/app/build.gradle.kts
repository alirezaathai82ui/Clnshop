plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("com.google.gms.google-services")
}

android {
    namespace = "ir.clnshop.app"
    compileSdk = 34

    defaultConfig {
        applicationId = "ir.clnshop.app"
        minSdk = 26
        targetSdk = 34
        versionCode = 3
        versionName = "1.0.2"

        // آدرس فروشگاه و تاپیک عمومی نوتیفیکیشن
        buildConfigField("String", "BASE_URL", "\"https://clnshop.ir/\"")
        buildConfigField("String", "SITE_HOST", "\"clnshop.ir\"")
        buildConfigField("String", "TOPIC_ALL", "\"all\"")
    }

    buildFeatures {
        buildConfig = true
    }

    val ksPath: String? = System.getenv("KEYSTORE_PATH")
    val hasReleaseKey = !ksPath.isNullOrBlank() && file(ksPath).exists()

    signingConfigs {
        if (hasReleaseKey) {
            create("release") {
                storeFile = file(ksPath!!)
                storePassword = System.getenv("KEYSTORE_PASSWORD")
                keyAlias = System.getenv("KEY_ALIAS")
                keyPassword = System.getenv("KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // بدون کلید اختصاصی (مثلاً ساخت محلی) با کلید دیباگ امضا می‌شود
            signingConfig = if (hasReleaseKey) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.core:core-splashscreen:1.0.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.webkit:webkit:1.11.0")

    implementation(platform("com.google.firebase:firebase-bom:33.1.2"))
    implementation("com.google.firebase:firebase-messaging-ktx")
}
