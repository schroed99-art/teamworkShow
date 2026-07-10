package com.example.teamworkshow.network

import android.content.Context
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.MessageDigest

/**
 * Syncs the local media directory with a remote server.
 *
 * HTTP contract (identical for the PHP backend and the Python test mock):
 *   GET {base}/playlist.php        -> { "items": [ { "name", "hash", "size" }, ... ] }
 *   GET {base}/media.php?name=NAME -> raw file bytes
 *
 * [hash] is the lowercase hex SHA-256 of the file contents; it is used to detect
 * changes so unchanged files are not re-downloaded.
 */
class SyncManager(context: Context, private val mediaDir: File) {

    private val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    data class RemoteItem(val name: String, val hash: String, val size: Long)

    /** Download progress callbacks. Invoked on the calling (background) thread. */
    interface SyncListener {
        fun onStart(total: Int)
        fun onProgress(done: Int, total: Int, name: String)
        fun onFinish(changed: Boolean)
    }

    var listener: SyncListener? = null

    fun getServerUrl(): String? =
        prefs.getString(KEY_URL, null)?.trim()?.trimEnd('/')?.takeIf { it.isNotEmpty() }

    fun setServerUrl(url: String) {
        prefs.edit().putString(KEY_URL, url.trim().trimEnd('/')).apply()
    }

    /**
     * Performs a full sync. Runs blocking I/O, so call it off the main thread.
     * @return true if the local media directory changed (added/updated/removed files).
     */
    fun sync(): Boolean {
        val base = getServerUrl() ?: return false
        val remote = try {
            fetchPlaylist(base)
        } catch (e: Exception) {
            Log.w(TAG, "Playlist fetch failed: ${e.message}")
            return false
        }

        if (!mediaDir.exists()) mediaDir.mkdirs()
        var changed = false
        val remoteNames = remote.map { it.name }.toHashSet()

        // Remove local files no longer present on the server.
        mediaDir.listFiles()?.forEach { f ->
            if (f.isFile && f.name !in remoteNames) {
                if (f.delete()) changed = true
            }
        }

        // Determine which files actually need downloading (new or changed by hash).
        val toDownload = remote.filter { item ->
            val local = File(mediaDir, item.name)
            !(local.exists() && sha256(local).equals(item.hash, ignoreCase = true))
        }
        if (toDownload.isNotEmpty()) listener?.onStart(toDownload.size)
        var done = 0
        for (item in toDownload) {
            try {
                downloadTo(base, item.name, File(mediaDir, item.name))
                changed = true
            } catch (e: Exception) {
                Log.w(TAG, "Download failed for ${item.name}: ${e.message}")
            }
            done++
            listener?.onProgress(done, toDownload.size, item.name)
        }
        listener?.onFinish(changed)
        return changed
    }

    private fun fetchPlaylist(base: String): List<RemoteItem> {
        val conn = openGet("$base/playlist.php")
        try {
            if (conn.responseCode != HttpURLConnection.HTTP_OK) {
                throw IllegalStateException("HTTP ${conn.responseCode}")
            }
            val body = conn.inputStream.bufferedReader().use { it.readText() }
            val arr = JSONObject(body).getJSONArray("items")
            val out = ArrayList<RemoteItem>(arr.length())
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                val name = o.getString("name")
                // Ignore anything that would escape the media directory.
                if (name.contains('/') || name.contains('\\') || name.contains("..")) continue
                out.add(RemoteItem(name, o.optString("hash", ""), o.optLong("size", -1)))
            }
            return out
        } finally {
            conn.disconnect()
        }
    }

    private fun downloadTo(base: String, name: String, dest: File) {
        val url = "$base/media.php?name=" + URLEncoder.encode(name, "UTF-8")
        val conn = openGet(url)
        try {
            if (conn.responseCode != HttpURLConnection.HTTP_OK) {
                throw IllegalStateException("HTTP ${conn.responseCode}")
            }
            val tmp = File(dest.parentFile, dest.name + ".tmp")
            conn.inputStream.use { input ->
                tmp.outputStream().use { output -> input.copyTo(output) }
            }
            if (!tmp.renameTo(dest)) {
                tmp.copyTo(dest, overwrite = true)
                tmp.delete()
            }
        } finally {
            conn.disconnect()
        }
    }

    private fun openGet(urlStr: String): HttpURLConnection {
        val conn = URL(urlStr).openConnection() as HttpURLConnection
        conn.requestMethod = "GET"
        conn.connectTimeout = CONNECT_TIMEOUT_MS
        conn.readTimeout = READ_TIMEOUT_MS
        conn.instanceFollowRedirects = true
        return conn
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
        private const val TAG = "SyncManager"
        private const val PREFS = "teamworkshow_settings"
        private const val KEY_URL = "server_url"
        private const val CONNECT_TIMEOUT_MS = 10_000
        private const val READ_TIMEOUT_MS = 30_000
    }
}
