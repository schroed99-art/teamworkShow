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
import java.security.MessageDigest
import java.util.Collections

/**
 * In-app self-update for sideloaded builds. Once per sync it asks the backend
 * (app_update.php) whether a newer signed APK is published; if so it downloads
 * the APK to the cache, verifies its SHA-256, and launches the system package
 * installer. The user confirms the final install prompt — silent installs need
 * device-owner/kiosk provisioning, which we don't require here.
 *
 * All updates are signed with the same release key as the running build, so the
 * installer treats them as an in-place update (no uninstall needed).
 */
class UpdateManager(private val context: Context) {

    data class Info(
        val versionCode: Int,
        val versionName: String,
        val apk: String,
        val size: Long,
        val sha256: String,
    )

    /** versionCodes we've already launched the installer for this process. */
    private val offered = Collections.synchronizedSet(HashSet<Int>())

    /** Ask for the unknown-sources grant at most once per process (avoids nagging). */
    @Volatile private var permissionPrompted = false

    /**
     * Background-thread entry point: check + (if newer) download + prompt install.
     * Safe to call every sync; network cost is a small JSON unless an update exists.
     */
    fun maybeUpdate(activity: Activity, baseUrl: String) {
        val info = check(baseUrl) ?: return
        if (offered.contains(info.versionCode)) return

        // Our app needs the "install unknown apps" grant. If missing, ask once and
        // bail — we retry on a later sync once the user has granted it.
        if (!context.packageManager.canRequestPackageInstalls()) {
            if (!permissionPrompted) {
                permissionPrompted = true
                activity.runOnUiThread { requestInstallPermission(activity) }
            }
            return
        }

        val file = download(baseUrl, info) ?: return
        if (offered.add(info.versionCode)) {
            activity.runOnUiThread { launchInstaller(activity, file) }
        }
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
            openGet("$baseUrl/${info.apk}", readTimeoutMs = 120_000).inputStream.use { input ->
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
