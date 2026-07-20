package de.teamworkshow.app.network

import android.content.Context
import android.util.Log
import de.teamworkshow.app.BuildConfig
import de.teamworkshow.app.model.NewsSlide
import de.teamworkshow.app.model.SlideMeta
import kotlin.random.Random
import de.teamworkshow.app.util.AppLog
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

    /** Weather-interstitial background lives here, out of the rotating media scan. */
    private val assetDir = File(mediaDir, ".assets")

    /** News-slide backgrounds live here, also out of the rotating media scan. */
    private val newsDir = File(mediaDir, ".news")

    /** Background hinted by the last playlist response (name/hash/size), or null. */
    private var pendingWeatherAsset: RemoteItem? = null

    /** News backgrounds referenced by the last playlist (name -> file), for pre-fetch. */
    private val pendingNewsAssets = LinkedHashMap<String, RemoteItem>()

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
        val noticesText: String,
        val noticesSize: Int,
        val noticesBg: String,
        val noticesHeight: Int,
        val noticesFont: String,
        val noticesColor: String,
        val noticesSpeed: Int
    ) {
        companion object {
            val EMPTY = WidgetSettings(false, "", false, "", 15, "#66000000", 0, "", "#FFFFFFFF", 90)
        }
    }

    /** One day of the 3-day outlook shown on the weather interstitial. */
    data class ForecastDay(
        val weekday: String,
        val date: String,
        val tempC: Int?,
        val icon: String
    )

    /** Live weather from `weather.php?device=`. `stub` = no API key / location on the server. */
    data class WeatherInfo(
        val enabled: Boolean,
        val stub: Boolean,
        val location: String,
        val tempC: Double?,
        val description: String,
        val icon: String,
        val forecast: List<ForecastDay> = emptyList()
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
            ?: BuildConfig.SERVER_URL.trim().trimEnd('/').takeIf { it.isNotEmpty() }

    fun setServerUrl(url: String) {
        prefs.edit().putString(KEY_URL, url.trim().trimEnd('/')).apply()
    }

    /** True once a server URL is explicitly stored (maintenance menu or auto-pin) —
     *  as opposed to falling back to the app's built-in default. */
    fun hasStoredServerUrl(): Boolean =
        !prefs.getString(KEY_URL, null).isNullOrBlank()

    /** Device pairing code; when set, the server returns an ordered, timed playlist. */
    fun getPairingCode(): String? =
        prefs.getString(KEY_PAIRING, null)?.trim()?.takeIf { it.isNotEmpty() }

    fun setPairingCode(code: String) {
        val clean = code.trim()
        prefs.edit().apply {
            if (clean.isEmpty()) remove(KEY_PAIRING) else putString(KEY_PAIRING, clean)
        }.apply()
    }

    /** Returns the device's pairing code, generating + persisting one on first use. */
    fun getOrCreatePairingCode(): String =
        getPairingCode() ?: generatePairingCode().also { setPairingCode(it) }

    private fun generatePairingCode(): String {
        val hex = (0 until 3).joinToString("") { "%02X".format(Random.nextInt(256)) }
        return hex.substring(0, 3) + "-" + hex.substring(3, 6)
    }

    /** Whether the backend recognises this device; driven by the playlist pull. */
    enum class Pairing { UNKNOWN, PAIRED, UNPAIRED }

    @Volatile
    var pairingStatus: Pairing =
        if (prefs.getBoolean(KEY_PAIRED, false)) Pairing.PAIRED else Pairing.UNKNOWN
        private set

    private fun updatePairing(paired: Boolean) {
        pairingStatus = if (paired) Pairing.PAIRED else Pairing.UNPAIRED
        prefs.edit().putBoolean(KEY_PAIRED, paired).apply()
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

    /** File-less weather interstitial slides (position + duration) from the last device playlist. */
    fun getWeatherSlides(): List<SlideMeta> {
        val raw = prefs.getString(KEY_WEATHER, null) ?: return emptyList()
        return try {
            val arr = org.json.JSONArray(raw)
            val out = ArrayList<SlideMeta>(arr.length())
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                out.add(SlideMeta(o.optInt("p", Int.MAX_VALUE), o.optLong("d", 0L)))
            }
            out
        } catch (e: Exception) {
            emptyList()
        }
    }

    private fun saveWeatherSlides(slides: List<SlideMeta>) {
        val arr = org.json.JSONArray()
        for (s in slides) {
            arr.put(JSONObject().put("p", s.position).put("d", s.durationMs))
        }
        prefs.edit().putString(KEY_WEATHER, arr.toString()).apply()
    }

    /** File-less news slides (title + body + position/duration) from the last device playlist. */
    fun getNewsSlides(): List<NewsSlide> {
        val raw = prefs.getString(KEY_NEWS, null) ?: return emptyList()
        return try {
            val arr = org.json.JSONArray(raw)
            val out = ArrayList<NewsSlide>(arr.length())
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                out.add(
                    NewsSlide(
                        title = o.optString("t", ""),
                        body = o.optString("b", ""),
                        position = o.optInt("p", Int.MAX_VALUE),
                        durationMs = o.optLong("d", 0L),
                        bg = o.optString("bg", ""),
                        font = o.optString("f", ""),
                        color = o.optString("c", ""),
                        size = o.optInt("s", 0),
                    )
                )
            }
            out
        } catch (e: Exception) {
            emptyList()
        }
    }

    private fun saveNewsSlides(slides: List<NewsSlide>) {
        val arr = org.json.JSONArray()
        for (s in slides) {
            arr.put(
                JSONObject().put("t", s.title).put("b", s.body)
                    .put("p", s.position).put("d", s.durationMs)
                    .put("bg", s.bg).put("f", s.font).put("c", s.color).put("s", s.size)
            )
        }
        prefs.edit().putString(KEY_NEWS, arr.toString()).apply()
    }

    /** Global weather-interstitial template config, or null when unset (app uses defaults). */
    fun getWeatherLayout(): JSONObject? {
        val raw = prefs.getString(KEY_WEATHER_LAYOUT, null) ?: return null
        return try {
            JSONObject(raw)
        } catch (e: Exception) {
            null
        }
    }

    private fun saveWeatherLayout(layout: JSONObject?) {
        prefs.edit().apply {
            if (layout == null) remove(KEY_WEATHER_LAYOUT) else putString(KEY_WEATHER_LAYOUT, layout.toString())
        }.apply()
    }

    /** The downloaded weather background file, or null when unset/not yet on disk. */
    fun getWeatherBackgroundFile(): File? {
        val name = getWeatherLayout()?.optString("background", "").orEmpty()
        if (name.isEmpty()) return null
        val f = File(assetDir, name)
        return if (f.isFile) f else null
    }

    /** Fingerprint of the current slide structure (order/timing + weather slides + layout). */
    fun playlistSignature(): String =
        (prefs.getString(KEY_META, "") ?: "") + "|" +
            (prefs.getString(KEY_WEATHER, "") ?: "") + "|" +
            (prefs.getString(KEY_WEATHER_LAYOUT, "") ?: "") + "|" +
            (prefs.getString(KEY_FORMAT, "") ?: "") + "|" +
            (prefs.getString(KEY_ZONES, "") ?: "") + "|" +
            (prefs.getString(KEY_NEWS, "") ?: "")

    // ---------- Screen zones (Phase 5.3) ----------

    /** One slide of a zone. 'weather' and 'news' are file-less; 'news' carries its text. */
    data class ZoneSlide(
        val name: String,
        val kind: String,          // "media" | "weather" | "news"
        val title: String,
        val body: String,
        val position: Int,
        val durationMs: Long,
        val bg: String = "",       // news background image (media-pool file name)
        val font: String = "",
        val color: String = "",
        val size: Int = 0,
    )

    /**
     * A node of the zone tree (Phase 5.3 Vollausbau). A [Split] divides its area
     * along [axis] ("rows" = stacked, "cols" = side by side) among weighted
     * [children]; a [Leaf] is one independent slideshow. The legacy fixed split is
     * mapped onto this same tree (two leaves) so one renderer serves both.
     */
    sealed class ZoneNode {
        data class Split(val axis: String, val children: List<ZoneChild>) : ZoneNode()
        data class Leaf(val slides: List<ZoneSlide>) : ZoneNode()
    }

    /** One weighted child of a split; [size] is a relative weight, not a percent. */
    data class ZoneChild(val size: Float, val node: ZoneNode)

    private fun saveZones(zones: JSONObject?) {
        prefs.edit().apply {
            if (zones == null) remove(KEY_ZONES) else putString(KEY_ZONES, zones.toString())
        }.apply()
    }

    private fun parseZoneSlides(arr: org.json.JSONArray?): List<ZoneSlide> {
        if (arr == null) return emptyList()
        val out = ArrayList<ZoneSlide>(arr.length())
        for (i in 0 until arr.length()) {
            val o = arr.optJSONObject(i) ?: continue
            val kind = o.optString("kind", "media")
            val name = o.optString("name", "")
            // Only media slides have a file — and it must never escape the media directory.
            if (kind == "media" &&
                (name.isEmpty() || name.contains('/') || name.contains('\\') || name.contains(".."))
            ) {
                continue
            }
            out.add(
                ZoneSlide(
                    name = name,
                    kind = kind,
                    title = o.optString("title", ""),
                    body = o.optString("body", ""),
                    position = o.optInt("position", Int.MAX_VALUE),
                    durationMs = o.optLong("duration_ms", 0L),
                    bg = o.optString("bg", ""),
                    font = o.optString("font", ""),
                    color = o.optString("color", ""),
                    size = o.optInt("size", 0),
                )
            )
        }
        return out
    }

    /** One node of the server's resolved zone tree. Splits carry >=2 weighted
     *  children; anything else is a leaf slideshow (its `slides`, possibly empty). */
    private fun parseZoneNode(o: JSONObject?): ZoneNode? {
        if (o == null) return null
        val children = o.optJSONArray("children")
        if (children != null && children.length() > 0) {
            val list = ArrayList<ZoneChild>(children.length())
            for (i in 0 until children.length()) {
                val c = children.optJSONObject(i) ?: continue
                val node = parseZoneNode(c.optJSONObject("node")) ?: continue
                val size = c.optDouble("size", 1.0).toFloat().let { if (it > 0f) it else 1f }
                list.add(ZoneChild(size, node))
            }
            if (list.size < 2) return null // a split needs at least two children
            val axis = if (o.optString("axis", "rows") == "cols") "cols" else "rows"
            return ZoneNode.Split(axis, list)
        }
        return ZoneNode.Leaf(parseZoneSlides(o.optJSONArray("slides")))
    }

    /** The device's zone tree, or null when it runs one full-screen slideshow. */
    fun getZoneTree(): ZoneNode? {
        val raw = prefs.getString(KEY_ZONES, null) ?: return null
        return try {
            val o = JSONObject(raw)
            when (o.optString("mode", "single")) {
                "custom" -> parseZoneNode(o.optJSONObject("tree"))
                "split" -> {
                    // Legacy fixed split -> two leaves, so the tree renderer serves both.
                    val axis = if (o.optString("axis", "rows") == "cols") "cols" else "rows"
                    val pct = o.optInt("split", 70).coerceIn(10, 90)
                    ZoneNode.Split(
                        axis,
                        listOf(
                            ZoneChild(pct.toFloat(), ZoneNode.Leaf(parseZoneSlides(o.optJSONArray("company")))),
                            ZoneChild((100 - pct).toFloat(), ZoneNode.Leaf(parseZoneSlides(o.optJSONArray("customer")))),
                        )
                    )
                }
                else -> null
            }
        } catch (e: Exception) {
            null
        }
    }

    /** Every leaf of the tree in a stable pre-order — the order stages are built in. */
    fun leavesOf(node: ZoneNode): List<ZoneNode.Leaf> = when (node) {
        is ZoneNode.Leaf -> listOf(node)
        is ZoneNode.Split -> node.children.flatMap { leavesOf(it.node) }
    }

    /**
     * Layout-affecting shape of the zone tree: axes, weights and leaf arrangement.
     * A change here must rebuild the stages (recreate the activity). Slide-content
     * changes are NOT part of it — they ride the lighter reload path, because the
     * whole zones payload is already in [playlistSignature].
     */
    fun zoneLayoutSignature(): String {
        val root = getZoneTree() ?: return "single"
        val sb = StringBuilder()
        fun walk(n: ZoneNode) {
            when (n) {
                is ZoneNode.Leaf -> sb.append("L;")
                is ZoneNode.Split -> {
                    sb.append("S:").append(n.axis).append('[')
                    n.children.forEach { sb.append(it.size).append(','); walk(it.node) }
                    sb.append(']')
                }
            }
        }
        walk(root)
        return sb.toString()
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
                noticesText = o.optString("nt", ""),
                noticesSize = o.optInt("ns", 15),
                noticesBg = o.optString("nb", "#66000000"),
                noticesHeight = o.optInt("nh", 0),
                noticesFont = o.optString("nf", ""),
                noticesColor = o.optString("nc", "#FFFFFFFF"),
                noticesSpeed = o.optInt("nsp", 90)
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
            .put("ns", w?.optInt("notices_size", 15) ?: 15)
            .put("nb", w?.optString("notices_bg", "#66000000") ?: "#66000000")
            .put("nh", w?.optInt("notices_height", 0) ?: 0)
            .put("nf", w?.optString("notices_font", "") ?: "")
            .put("nc", w?.optString("notices_color", "#FFFFFFFF") ?: "#FFFFFFFF")
            .put("nsp", w?.optInt("notices_speed", 90) ?: 90)
        prefs.edit().putString(KEY_WIDGETS, out.toString()).apply()
    }

    /** Device display format (portrait|phone|landscape|tablet); drives orientation + layout. */
    fun getDisplayFormat(): String =
        prefs.getString(KEY_FORMAT, DEFAULT_FORMAT)?.takeIf { it in DISPLAY_FORMATS } ?: DEFAULT_FORMAT

    private fun saveDisplayFormat(value: String?) {
        val clean = value?.trim()?.lowercase()?.takeIf { it in DISPLAY_FORMATS } ?: DEFAULT_FORMAT
        prefs.edit().putString(KEY_FORMAT, clean).apply()
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
                val fc = o.optJSONArray("forecast")
                val days = ArrayList<ForecastDay>(fc?.length() ?: 0)
                if (fc != null) {
                    for (i in 0 until fc.length()) {
                        val d = fc.getJSONObject(i)
                        days.add(
                            ForecastDay(
                                weekday = d.optString("weekday", ""),
                                date = d.optString("date", ""),
                                tempC = if (d.has("temp_c") && !d.isNull("temp_c")) d.optInt("temp_c") else null,
                                icon = d.optString("icon", "")
                            )
                        )
                    }
                }
                WeatherInfo(
                    enabled = o.optBoolean("enabled", false),
                    stub = o.optBoolean("stub", true),
                    location = o.optString("location", ""),
                    tempC = if (o.has("temp_c") && !o.isNull("temp_c")) o.optDouble("temp_c") else null,
                    description = o.optString("description", ""),
                    icon = o.optString("icon", ""),
                    forecast = days
                )
            } finally {
                conn.disconnect()
            }
        } catch (e: Exception) {
            AppLog.w(TAG, "Weather fetch failed: ${e.message}")
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
            AppLog.w(TAG, "Playlist fetch failed: ${e.message}")
            return false
        }

        // Pin the server URL on first successful contact: a device that relied on the
        // app's built-in default now stores it, so a changed default in a later release
        // can no longer move it to another server. A URL set in the maintenance menu
        // already counts as stored and is never overwritten here — a deliberate server
        // migration (enter a new URL) still takes effect.
        if (!hasStoredServerUrl()) {
            setServerUrl(base)
            AppLog.i(TAG, "Server URL pinned to $base after first successful sync")
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
                AppLog.w(TAG, "Download failed for ${item.name}: ${e.message}")
            }
            done++
            listener?.onProgress(done, toDownload.size, item.name)
        }

        // Weather-interstitial background: fetched into a hidden asset dir so it never
        // becomes a rotating slide, and pruned when unset/replaced.
        syncWeatherAsset(base)
        // News-slide backgrounds: same idea, in their own hidden dir.
        syncNewsAssets(base)

        listener?.onFinish(changed)
        AppLog.i(TAG, "sync done: changed=$changed, remote=${remote.size}, downloaded=$done")
        return changed
    }

    /** Downloads the hinted weather background into [assetDir] and prunes stale files. */
    private fun syncWeatherAsset(base: String) {
        val want = pendingWeatherAsset
        assetDir.listFiles()?.forEach { f ->
            if (f.isFile && (want == null || f.name != want.name)) f.delete()
        }
        if (want == null) return
        if (!assetDir.exists()) assetDir.mkdirs()
        val local = File(assetDir, want.name)
        val fresh = local.exists() && sha256(local).equals(want.hash, ignoreCase = true)
        if (!fresh) {
            try {
                downloadTo(base, want.name, local)
            } catch (e: Exception) {
                AppLog.w(TAG, "Weather background download failed for ${want.name}: ${e.message}")
            }
        }
    }

    /** Records a news slide's background (name+hash+size) for pre-fetch, if it has one. */
    private fun rememberNewsBg(o: JSONObject) {
        val name = o.optString("bg", "")
        if (name.isEmpty() || name.contains('/') || name.contains('\\') || name.contains("..")) return
        pendingNewsAssets[name] = RemoteItem(
            name = name, hash = o.optString("bg_hash", ""), size = o.optLong("bg_size", -1)
        )
    }

    /** Walks the resolved zone tree collecting every news slide's background. */
    private fun collectZoneNewsBg(node: JSONObject?) {
        if (node == null) return
        val children = node.optJSONArray("children")
        if (children != null) {
            for (i in 0 until children.length()) {
                collectZoneNewsBg(children.optJSONObject(i)?.optJSONObject("node"))
            }
            return
        }
        val slides = node.optJSONArray("slides") ?: return
        for (i in 0 until slides.length()) {
            val s = slides.optJSONObject(i) ?: continue
            if (s.optString("kind", "") == "news") rememberNewsBg(s)
        }
    }

    /** Downloads news backgrounds into [newsDir] and prunes ones no longer referenced. */
    private fun syncNewsAssets(base: String) {
        val want = pendingNewsAssets
        newsDir.listFiles()?.forEach { f ->
            if (f.isFile && f.name !in want) f.delete()
        }
        if (want.isEmpty()) return
        if (!newsDir.exists()) newsDir.mkdirs()
        for ((name, item) in want) {
            val local = File(newsDir, name)
            val fresh = local.exists() && sha256(local).equals(item.hash, ignoreCase = true)
            if (!fresh) {
                try {
                    downloadTo(base, name, local)
                } catch (e: Exception) {
                    AppLog.w(TAG, "News background download failed for $name: ${e.message}")
                }
            }
        }
    }

    /** The downloaded news background for [name], or null when unset/not yet on disk. */
    fun getNewsBackgroundFile(name: String): File? {
        if (name.isEmpty() || name.contains('/') || name.contains('\\') || name.contains("..")) return null
        val f = File(newsDir, name)
        return if (f.isFile) f else null
    }

    /** Central help/contact info, edited in the dashboard and delivered with the playlist. */
    data class HelpInfo(
        val company: String,
        val app: String,
        val version: String,
        val phone: String,
        val contact: String,
        val supportMail: String,
        val supportPhone: String,
        val website: String,
    ) {
        fun isEmpty(): Boolean = listOf(
            company, app, version, phone, contact, supportMail, supportPhone, website
        ).all { it.isBlank() }

        companion object { val EMPTY = HelpInfo("", "", "", "", "", "", "", "") }
    }

    fun getHelpInfo(): HelpInfo {
        val raw = prefs.getString(KEY_HELP, null) ?: return HelpInfo.EMPTY
        return try {
            val o = JSONObject(raw)
            HelpInfo(
                company = o.optString("company"),
                app = o.optString("app"),
                version = o.optString("version"),
                phone = o.optString("phone"),
                contact = o.optString("contact"),
                supportMail = o.optString("support_mail"),
                supportPhone = o.optString("support_phone"),
                website = o.optString("website"),
            )
        } catch (e: Exception) {
            HelpInfo.EMPTY
        }
    }

    private fun saveHelpInfo(h: JSONObject?) {
        prefs.edit().apply {
            if (h == null) remove(KEY_HELP) else putString(KEY_HELP, h.toString())
        }.apply()
    }

    /** Kundenstammdaten des Mandanten — auf der Leer-Ansicht gezeigt, wenn keine Präsentation läuft. */
    data class CustomerInfo(val company: String, val address: String) {
        fun isEmpty(): Boolean = company.isBlank() && address.isBlank()

        companion object { val EMPTY = CustomerInfo("", "") }
    }

    fun getCustomerInfo(): CustomerInfo {
        val raw = prefs.getString(KEY_CUSTOMER, null) ?: return CustomerInfo.EMPTY
        return try {
            val o = JSONObject(raw)
            CustomerInfo(company = o.optString("company"), address = o.optString("address"))
        } catch (e: Exception) {
            CustomerInfo.EMPTY
        }
    }

    private fun saveCustomerInfo(tenant: JSONObject?) {
        val company = tenant?.optString("company").orEmpty()
        val address = tenant?.optString("address").orEmpty()
        prefs.edit().apply {
            if (company.isBlank() && address.isBlank()) {
                remove(KEY_CUSTOMER)
            } else {
                putString(KEY_CUSTOMER, JSONObject().put("company", company).put("address", address).toString())
            }
        }.apply()
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
            val rc = conn.responseCode
            if (code != null) {
                // 200 = backend knows this device; 404 unknown_device = not yet claimed.
                when (rc) {
                    HttpURLConnection.HTTP_OK -> updatePairing(true)
                    HttpURLConnection.HTTP_NOT_FOUND -> updatePairing(false)
                }
            }
            if (rc != HttpURLConnection.HTTP_OK) {
                throw IllegalStateException("HTTP $rc")
            }
            val body = conn.inputStream.bufferedReader().use { it.readText() }
            val root = JSONObject(body)
            val arr = root.getJSONArray("items")
            val out = ArrayList<RemoteItem>(arr.length())
            val weather = ArrayList<SlideMeta>()
            val news = ArrayList<NewsSlide>()
            // Recomputed from this response; a news background is pre-fetched only
            // while some slide still references it (flat playlist OR a zone).
            pendingNewsAssets.clear()
            for (i in 0 until arr.length()) {
                val o = arr.getJSONObject(i)
                val kind = o.optString("kind", "media")
                // File-less weather interstitials carry ordering/timing but no media file.
                if (kind == "weather") {
                    weather.add(SlideMeta(o.optInt("position", Int.MAX_VALUE), o.optLong("duration_ms", 0L)))
                    continue
                }
                // News slides are file-less too — they carry their own text. Keeping them
                // out of `out` is what stops the downloader from chasing an empty name.
                if (kind == "news") {
                    news.add(
                        NewsSlide(
                            title = o.optString("title", ""),
                            body = o.optString("body", ""),
                            position = o.optInt("position", Int.MAX_VALUE),
                            durationMs = o.optLong("duration_ms", 0L),
                            bg = o.optString("bg", ""),
                            font = o.optString("font", ""),
                            color = o.optString("color", ""),
                            size = o.optInt("size", 0),
                        )
                    )
                    rememberNewsBg(o)
                    continue
                }
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
            saveWeatherSlides(weather)
            saveNewsSlides(news)
            // `widgets` is present only in device mode; folder mode clears it to defaults.
            saveWidgetSettings(root.optJSONObject("widgets"))
            // Global weather-interstitial template + its background download hint.
            saveWeatherLayout(root.optJSONObject("weather_layout"))
            // Central help/contact info (device mode only); folder mode clears it.
            saveHelpInfo(root.optJSONObject("help"))
            // Kundenstammdaten des Mandanten (device mode only) für die Leer-Ansicht.
            saveCustomerInfo(root.optJSONObject("tenant"))
            // Per-device display format from the `device` block (folder mode → default).
            saveDisplayFormat(root.optJSONObject("device")?.optString("display_format"))
            // Zone split (null = single full-screen stage, i.e. everything before 5.3).
            val zones = root.optJSONObject("zones")
            saveZones(zones)
            // News backgrounds hide inside zone slideshows too — collect those so the
            // downloader fetches them just like the flat-playlist ones.
            collectZoneNewsBg(zones)
            pendingWeatherAsset = root.optJSONObject("weather_asset")?.let { a ->
                val name = a.optString("name", "")
                if (name.isEmpty() || name.contains('/') || name.contains('\\') || name.contains("..")) {
                    null
                } else {
                    RemoteItem(name = name, hash = a.optString("hash", ""), size = a.optLong("size", -1))
                }
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
        private const val KEY_PAIRING = "pairing_code"
        private const val KEY_PAIRED = "device_paired"
        private const val KEY_META = "playlist_meta"
        private const val KEY_WEATHER = "weather_slides"
        private const val KEY_WEATHER_LAYOUT = "weather_layout"
        private const val KEY_WIDGETS = "widget_settings"
        private const val KEY_HELP = "help_info"
        private const val KEY_CUSTOMER = "customer_info"
        private const val KEY_FORMAT = "display_format"
        private const val KEY_ZONES = "zones"
        private const val KEY_NEWS = "news_slides"
        private const val DEFAULT_FORMAT = "portrait"
        private val DISPLAY_FORMATS = setOf("portrait", "phone", "landscape", "tablet")
        private const val CONNECT_TIMEOUT_MS = 10_000
        private const val READ_TIMEOUT_MS = 30_000
    }
}
