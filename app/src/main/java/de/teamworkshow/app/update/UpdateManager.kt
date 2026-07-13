package de.teamworkshow.app.update

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import android.util.Log
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.MessageDigest

/**
 * In-app self-update for sideloaded builds. [check] asks the backend
 * (app_update.php) whether a newer signed APK is published; the UI surfaces a
 * discreet badge and calls [startInstall] only when the operator taps it. That
 * downloads the APK to the cache, verifies its SHA-256, and launches the system
 * package installer. The operator confirms the final install prompt (and the
 * one-time "install unknown apps" / Play Protect prompts) — silent installs
 * need device-owner/kiosk provisioning, which we don't require here.
 *
 * Nothing installs automatically, so an install prompt never overlays a running
 * presentation. All updates are signed with the same release key as the running
 * build, so the installer treats them as an in-place update (no uninstall needed).
 */
class UpdateManager(private val context: Context) {

    data class Info(
        val versionCode: Int,
        val versionName: String,
        val apk: String,
        val size: Long,
        val sha256: String,
    )

    /**
     * Operator-triggered install: downloads the advertised APK (off the UI
     * thread), verifies its SHA-256, and launches the system installer. Called
     * when the user taps the update badge — never automatically — so the install
     * prompt never overlays a running presentation.
     *
     * If the "install unknown apps" grant is missing, it opens that settings
     * screen instead; the operator grants it and taps the badge again.
     */
    fun startInstall(activity: Activity, baseUrl: String, info: Info) {
        if (!context.packageManager.canRequestPackageInstalls()) {
            requestInstallPermission(activity)
            return
        }
        Thread {
            val file = download(baseUrl, info)
            if (file != null) {
                activity.runOnUiThread { launchInstaller(activity, file) }
            }
        }.start()
    }

    /** Returns update Info when the backend advertises a versionCode newer than ours. */
    fun check(baseUrl: String): Info? = try {
        val conn = openGet("$baseUrl/app_update.php", readTimeoutMs = 15_000)
        conn.inputStream.bufferedReader().use { reader ->
            val o = JSONObject(reader.readText())
            if (!o.optBoolean("available", false)) {
                null
            } else {
                val info = Info(
                    versionCode = o.getInt("versionCode"),
                    versionName = o.optString("versionName"),
                    apk = o.optString("apk", "app-release.apk"),
                    size = o.optLong("size"),
                    sha256 = o.optString("sha256", ""),
                )
                if (info.versionCode > currentVersionCode()) info else null
            }
        }
    } catch (e: Exception) {
        Log.w(TAG, "update check failed: ${e.message}")
        null
    }

    fun currentVersionCode(): Int = try {
        val pi = context.packageManager.getPackageInfo(context.packageName, 0)
        pi.longVersionCode.toInt()
    } catch (e: Exception) {
        0
    }

    /** Downloads the APK to cacheDir/updates, verifying SHA-256. Reuses a valid cache. */
    private fun download(baseUrl: String, info: Info): File? = try {
        val dir = File(context.cacheDir, "updates").apply { mkdirs() }
        val out = File(dir, "update-${info.versionCode}.apk")
        if (out.exists() && info.sha256.isNotEmpty() && sha256(out).equals(info.sha256, ignoreCase = true)) {
            out // already downloaded + verified
        } else {
            openGet("$baseUrl/apk.php${pairingParam()}", readTimeoutMs = 120_000).inputStream.use { input ->
                out.outputStream().use { input.copyTo(it) }
            }
            if (info.sha256.isNotEmpty() && !sha256(out).equals(info.sha256, ignoreCase = true)) {
                Log.w(TAG, "update sha256 mismatch — discarding download")
                out.delete()
                null
            } else {
                out
            }
        }
    } catch (e: Exception) {
        Log.w(TAG, "update download failed: ${e.message}")
        null
    }

    private fun launchInstaller(activity: Activity, apk: File) {
        try {
            val uri: Uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", apk)
            @Suppress("DEPRECATION")
            val intent = Intent(Intent.ACTION_INSTALL_PACKAGE).apply {
                data = uri
                flags = Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK
                putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true)
            }
            activity.startActivity(intent)
        } catch (e: Exception) {
            Log.w(TAG, "launching installer failed: ${e.message}")
        }
    }

    private fun requestInstallPermission(activity: Activity) {
        try {
            activity.startActivity(
                Intent(
                    Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:${context.packageName}"),
                )
            )
        } catch (e: Exception) {
            Log.w(TAG, "cannot open unknown-sources settings: ${e.message}")
        }
    }

    /** `?device=CODE` for the gated apk.php, using the same pairing code SyncManager stores. */
    private fun pairingParam(): String {
        val code = context.getSharedPreferences("teamworkshow_settings", Context.MODE_PRIVATE)
            .getString("pairing_code", null)?.trim().orEmpty()
        return if (code.isNotEmpty()) "?device=" + URLEncoder.encode(code, "UTF-8") else ""
    }

    private fun openGet(urlStr: String, readTimeoutMs: Int): HttpURLConnection =
        (URL(urlStr).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 10_000
            readTimeout = readTimeoutMs
            instanceFollowRedirects = true
        }

    private fun sha256(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buf = ByteArray(8192)
            while (true) {
                val read = input.read(buf)
                if (read < 0) break
                md.update(buf, 0, read)
            }
        }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    companion object {
        private const val TAG = "UpdateManager"
    }
}
