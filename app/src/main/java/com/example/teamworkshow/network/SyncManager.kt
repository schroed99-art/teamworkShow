package com.example.teamworkshow.network

import android.content.Context
import android.util.Log
import com.example.teamworkshow.model.SlideMeta
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
 *   GET {base}/playlist.php                  -> folder scan: { "items": [ { name, hash, size }, ... ] }
 *   GET {base}/playlist.php?device=<pairing>  -> device playlist: items also carry { position, duration_ms }
 *   GET {base}/media.php?name=NAME            -> raw file bytes
 *
 * [hash] is the lowercase hex SHA-256 of the file contents; it is used to detect
 * changes so unchanged files are not re-downloaded. When a pairing code is set the
 * server returns an ordered, timed playlist; that ordering/timing is persisted so
 * [PlaylistManager] can honor it between syncs and across restarts.
 */
class SyncManager(context: Context, private val mediaDir: File) {

    private val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    data class RemoteItem(
        val name: String,
        val hash: String,
        val size: Long,
        val position: Int = Int.MAX_VALUE,
        val durationMs: Long = 0L
    )

    /** Device widget configuration from the playlist response's `widgets` block. */
    data class WidgetSettings(
        val weatherEnabled: Boolean,
        val weatherLocation: String,
        val noticesEnabled: Boolean,
        val noticesText: String
    ) {
        companion object {
            val EMPTY = WidgetSettings(false, "", false, "")
        }
    }

    /** Live weather from `weather.php?device=`. `stub` = no API key / location on the server. */
    data class WeatherInfo(
        val enabled: Boolean,
        val stub: Boolean,
        val location: String,
        val tempC: Double?,
        val description: String,
        val icon: String
    )

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

    /** Device pairing code; when set, the server returns an ordered, timed playlist. */
    fun getPairingCode(): String? =
        prefs.getString(KEY_PAIRING, null)?.trim()?.takeIf { it.isNotEmpty() }

    fun setPairingCode(code: String) {
        val clean = code.trim()
        prefs.edit().apply {
            if (clean.isEmpty()) remove(KEY_PAIRING) else putString(KEY_PAIRING, clean)
        }.apply()
    }

    /** Persisted ordering/timing from the last device playlist (name -> [SlideMeta]). */
    fun getPlaylistMeta(): Map<String, SlideMeta> {
        val raw = prefs.getString(KEY_META, null) ?: return emptyMap()
        return try {
            val obj = JSONObject(raw)
            val out = HashMap<String, SlideMeta>(obj.length())
            for (name in obj.keys()) {
                val o = obj.getJSONObject(name)
                out[name] = SlideMeta(o.optInt("p", Int.MAX_VALUE), o.optLong("d", 0L))
            }
            out
        } catch (e: Exception) {
            emptyMap()
        }
    }

    private fun savePlaylistMeta(items: List<RemoteItem>) {
        val obj = JSONObject()
        for (it in items) {
            obj.put(it.name, JSONObject().put("p", it.position).put("d", it.durationMs))
        }
        prefs.edit().putString(KEY_META, obj.toString()).apply()
    }

    /** Widget settings from the last device playlist (empty in folder mode). */
    fun getWidgetSettings(): WidgetSettings {
        val raw = prefs.getString(KEY_WIDGETS, null) ?: return WidgetSettings.EMPTY
        return try {
            val o = JSONObject(raw)
            WidgetSettings(
                weatherEnabled = o.optBoolean("we", false),
                weatherLocation = o.optString("wl", ""),
                noticesEnabled = o.optBoolean("ne", false),
                noticesText = o.optString("nt", "")
            )
        } catch (e: Exception) {
            WidgetSettings.EMPTY
        }
    }

    private fun saveWidgetSettings(w: JSONObject?) {
        val out = JSONObject()
            .put("we", w?.optBoolean("weather_enabled", false) ?: false)
            .put("wl", w?.optString("weather_location", "") ?: "")
            .put("ne", w?.optBoolean("notices_enabled", false) ?: false)
            .put("nt", w?.optString("notices_text", "") ?: "")
        prefs.edit().putString(KEY_WIDGETS, out.toString()).apply()
    }

    /**
     * Fetches live weather for the paired device. Returns null when no server/pairing is
     * configured or the request fails. Runs blocking I/O; call it off the main thread.
     */
    fun fetchWeather(): WeatherInfo? {
        val base = getServerUrl() ?: return null
        val code = getPairingCode() ?: return null
        return try {
            val conn = openGet("$base/weather.php?device=" + URLEncoder.encode(code, "UTF-8"))
            try {
                if (conn.responseCode != HttpURLConnection.HTTP_OK) return null
                val body = conn.inputStream.bufferedReader().use { it.readText() }
                val o = JSONObject(body)
                WeatherInfo(
                    enabled = o.optBoolean("enabled", false),
                    stub = o.optBoolean("stub", true),
                    location = o.optString("location", ""),
                    tempC = if (o.has("temp_c") && !o.isNull("temp_c")) o.optDouble("temp_c") else null,
                    description = o.optString("description", ""),
                    icon = o.optString("icon", "")
                )
            } finally {
                conn.disconnect()
            }
        } catch (e: Exception) {
            Log.w(TAG, "Weather fetch failed: ${e.message}")
            null
        }
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
        val code = getPairingCode()
        val url = if (code != null) {
            "$base/playlist.php?device=" + URLEncoder.encode(code, "UTF-8")
        } else {
            "$base/playlist.php"
        }
        val conn = openGet(url)
        try {
            if (conn.responseCode != HttpURLConnection.HTTP_OK) {
                throw IllegalStateException("HTTP ${conn.responseCode}")
            }
            val body = conn.inputStream.bufferedReader().use { it.readText() }
            val root = JSONObject(body)
            val arr = root.getJSONArray("items")
            val out = ArrayList<RemoteItem>(arr.length())
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                val name = o.getString("name")
                // Ignore anything that would escape the media directory.
                if (name.contains('/') || name.contains('\\') || name.contains("..")) continue
                out.add(
                    RemoteItem(
                        name = name,
                        hash = o.optString("hash", ""),
                        size = o.optLong("size", -1),
                        position = o.optInt("position", Int.MAX_VALUE),
                        durationMs = o.optLong("duration_ms", 0L)
                    )
                )
            }
            savePlaylistMeta(out)
            // `widgets` is present only in device mode; folder mode clears it to defaults.
            saveWidgetSettings(root.optJSONObject("widgets"))
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
        private const val KEY_PAIRING = "pairing_code"
        private const val KEY_META = "playlist_meta"
        private const val KEY_WIDGETS = "widget_settings"
        private const val CONNECT_TIMEOUT_MS = 10_000
        private const val READ_TIMEOUT_MS = 30_000
    }
}
