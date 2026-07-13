package de.teamworkshow.app

import android.animation.ObjectAnimator
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.InputType
import android.util.TypedValue
import android.view.Gravity
import android.view.KeyEvent
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import android.view.animation.LinearInterpolator
import android.widget.EditText
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.media3.common.MediaItem as Media3Item
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import de.teamworkshow.app.model.MediaItem
import de.teamworkshow.app.model.MediaType
import de.teamworkshow.app.network.SyncManager
import de.teamworkshow.app.update.UpdateManager
import de.teamworkshow.app.player.PlayerCallback
import de.teamworkshow.app.player.SlideShowController
import de.teamworkshow.app.playlist.PlaylistManager
import de.teamworkshow.app.util.AppLog
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity(), PlayerCallback {

    private lateinit var imageViewA: ImageView
    private lateinit var imageViewB: ImageView
    private lateinit var playerView: PlayerView
    private lateinit var emptyView: View
    private lateinit var slideProgress: ProgressBar
    private lateinit var noticesBar: android.widget.FrameLayout
    private lateinit var noticesText: TextView
    private var tickerAnimator: android.animation.ValueAnimator? = null
    private var tickerText: String? = null
    private var tickerSpeedDp: Int = 90

    // Weather forecast interstitial (a file-less slide); its contents are built at
    // runtime from the global layout config (background + grid-positioned elements).
    private lateinit var weatherView: View
    private lateinit var wxBg: ImageView
    private lateinit var wxScrim: View
    private lateinit var wxLayer: android.widget.FrameLayout
    private val WX_ROWS = listOf("header", "1", "2", "3", "4", "5", "6", "footer")
    private var latestWeather: SyncManager.WeatherInfo? = null
    private var lastStructSig: String? = null
    private lateinit var downloadOverlay: View
    private lateinit var downloadStatus: TextView
    private lateinit var downloadProgress: ProgressBar
    private lateinit var pairingOverlay: View
    private lateinit var pairingCodeLabel: TextView

    private var frontImageView: ImageView? = null
    private var preloaded: Pair<File, Bitmap>? = null
    private var slideAnimator: ObjectAnimator? = null

    private lateinit var exoPlayer: ExoPlayer
    private lateinit var slideShowController: SlideShowController

    private lateinit var syncManager: SyncManager
    private val updateManager = UpdateManager(this)
    private lateinit var updateBadge: TextView
    private var pendingUpdate: UpdateManager.Info? = null
    private var installing = false
    private val syncExecutor = Executors.newSingleThreadExecutor()
    private val mainHandler = Handler(Looper.getMainLooper())
    private val syncRunnable = object : Runnable {
        override fun run() {
            syncNow(userTriggered = false)
            mainHandler.postDelayed(this, SYNC_INTERVAL_MS)
        }
    }

    /** Shows the download overlay while media is being fetched from the server. */
    private val downloadListener = object : SyncManager.SyncListener {
        override fun onStart(total: Int) {
            mainHandler.post {
                downloadProgress.progress = 0
                downloadStatus.text = getString(R.string.download_status, 0, total, 0)
                downloadOverlay.visibility = View.VISIBLE
            }
        }

        override fun onProgress(done: Int, total: Int, name: String) {
            mainHandler.post {
                val pct = if (total > 0) done * 100 / total else 0
                downloadProgress.progress = pct
                downloadStatus.text = getString(R.string.download_status, done, total, pct)
            }
        }

        override fun onFinish(changed: Boolean) {
            mainHandler.post { downloadOverlay.visibility = View.GONE }
        }
    }

    private val tapTimestamps = ArrayDeque<Long>()

    companion object {
        private const val TAG = "MainActivity"
        private const val CROSSFADE_MS = 300L
        private const val MAINTENANCE_PIN = "0000"
        private const val TAP_COUNT_REQUIRED = 5
        private const val TAP_WINDOW_MS = 2_000L
        private const val CORNER_DP = 150f
        private const val SYNC_INTERVAL_MS = 60_000L
        private const val WEATHER_PLACEHOLDER = "__weather__"
        private const val PREFS = "teamworkshow_settings"
        private const val KEY_STORAGE_BASE = "storage_base"
    }

    /** Shared with SyncManager's prefs; holds the optional custom storage base path. */
    private val settingsPrefs by lazy { getSharedPreferences(PREFS, MODE_PRIVATE) }

    /**
     * Media download directory: a `media/` subfolder under the technician-chosen
     * base path when set + writable (needs all-files access), else the app's own
     * external files dir. Logs export next to it.
     */
    private fun resolveMediaDir(): File {
        val base = settingsPrefs.getString(KEY_STORAGE_BASE, null)
        if (!base.isNullOrBlank()) {
            val dir = File(base, "media")
            if ((dir.exists() || dir.mkdirs()) && dir.canWrite()) return dir
            AppLog.w(TAG, "custom storage '$base' not usable — falling back to app dir")
        }
        return File(getExternalFilesDir(null), "media").also { it.mkdirs() }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        AppLog.init(this)
        AppLog.i(TAG, "app start ${appVersionText()}")
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        setContentView(R.layout.activity_main)
        hideSystemBars()

        imageViewA = findViewById(R.id.imageViewA)
        imageViewB = findViewById(R.id.imageViewB)
        playerView = findViewById(R.id.playerView)
        emptyView = findViewById(R.id.emptyView)
        slideProgress = findViewById(R.id.slideProgress)
        noticesBar = findViewById(R.id.noticesBar)
        noticesText = findViewById(R.id.noticesText)
        weatherView = findViewById(R.id.weatherView)
        wxBg = findViewById(R.id.wxBg)
        wxScrim = findViewById(R.id.wxScrim)
        wxLayer = findViewById(R.id.wxLayer)
        downloadOverlay = findViewById(R.id.downloadOverlay)
        downloadStatus = findViewById(R.id.downloadStatus)
        downloadProgress = findViewById(R.id.downloadProgress)
        pairingOverlay = findViewById(R.id.pairingOverlay)
        pairingCodeLabel = findViewById(R.id.pairingCode)
        findViewById<TextView>(R.id.versionLabel).text = appVersionText()
        updateBadge = findViewById(R.id.updateBadge)
        updateBadge.setOnClickListener { onUpdateBadgeClicked() }

        setupExoPlayer()

        val mediaDir = resolveMediaDir()
        AppLog.i(TAG, "media dir: ${mediaDir.absolutePath}")
        syncManager = SyncManager(this, mediaDir)
        syncManager.listener = downloadListener
        // Show this device's pairing code until the backend recognises it.
        pairingCodeLabel.text = syncManager.getOrCreatePairingCode()
        updatePairingOverlay()

        val playlist = PlaylistManager(mediaDir)
        // Honor the server-defined order + per-slide duration when a device is paired.
        playlist.metaProvider = { syncManager.getPlaylistMeta() }
        // Weave in file-less weather interstitials at their server-defined positions.
        playlist.weatherProvider = {
            syncManager.getWeatherSlides().map {
                MediaItem(File(mediaDir, WEATHER_PLACEHOLDER), MediaType.WEATHER, it.durationMs, it.position)
            }
        }
        slideShowController = SlideShowController(playlist, this)
        slideShowController.start()

        // Immediate sync on launch, then poll periodically.
        mainHandler.post(syncRunnable)
    }

    // ---------- Server sync ----------

    /** Runs a sync on a background thread; reloads the slideshow if media changed. */
    private fun syncNow(userTriggered: Boolean) {
        if (syncManager.getServerUrl() == null) {
            if (userTriggered) {
                Toast.makeText(this, R.string.sync_no_server, Toast.LENGTH_SHORT).show()
            }
            return
        }
        syncExecutor.execute {
            val changed = syncManager.sync()
            // Widget settings arrive with the playlist; weather is a separate live call.
            val widgets = syncManager.getWidgetSettings()
            // Fetch the forecast when weather is enabled OR a weather interstitial is in the playlist.
            val wantWeather = widgets.weatherEnabled || syncManager.getWeatherSlides().isNotEmpty()
            val weather = if (wantWeather) syncManager.fetchWeather() else null
            // Reload when media changed OR the slide structure (order/duration/weather) changed.
            val sig = syncManager.playlistSignature()
            mainHandler.post {
                latestWeather = weather
                if (changed || sig != lastStructSig) {
                    lastStructSig = sig
                    slideShowController.reload()
                }
                applyWidgets(widgets)
                updatePairingOverlay()
                if (userTriggered) {
                    val msg = if (changed) R.string.sync_updated else R.string.sync_no_change
                    Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
                }
            }
            // In-app self-update: only DETECT a newer signed APK here and surface a
            // discreet badge. The operator taps it to start the install, so the
            // system install prompt never overlays a running presentation.
            syncManager.getServerUrl()?.let { base ->
                val info = updateManager.check(base)
                runOnUiThread {
                    if (info != null) showUpdateBadge(info) else hideUpdateBadge()
                }
            }
        }
    }

    /** Shows the discreet "update available" badge under the version label. */
    private fun showUpdateBadge(info: UpdateManager.Info) {
        if (installing) return // don't overwrite the "Aktualisiere…" state
        pendingUpdate = info
        updateBadge.text = getString(R.string.update_available, info.versionName)
        updateBadge.visibility = View.VISIBLE
    }

    private fun hideUpdateBadge() {
        if (installing) return
        pendingUpdate = null
        updateBadge.visibility = View.GONE
    }

    /** Operator tapped the badge: start the deliberate download + install. */
    private fun onUpdateBadgeClicked() {
        if (installing) return
        val info = pendingUpdate ?: return
        val base = syncManager.getServerUrl() ?: return
        installing = true
        updateBadge.text = getString(R.string.update_installing)
        updateManager.startInstall(this, base, info)
    }

    /**
     * Explicit "App aktualisieren" menu action: checks the backend for a newer
     * signed APK and, if there is one, starts the install immediately; otherwise
     * reports that the app is already up to date.
     */
    private fun checkForUpdateFromMenu() {
        val base = syncManager.getServerUrl()
        if (base == null) {
            Toast.makeText(this, R.string.sync_no_server, Toast.LENGTH_SHORT).show()
            return
        }
        Toast.makeText(this, R.string.update_checking, Toast.LENGTH_SHORT).show()
        syncExecutor.execute {
            val info = updateManager.check(base)
            runOnUiThread {
                if (info != null) {
                    showUpdateBadge(info)
                    onUpdateBadgeClicked()
                } else {
                    Toast.makeText(
                        this,
                        getString(R.string.update_none, appVersionText()),
                        Toast.LENGTH_LONG
                    ).show()
                }
            }
        }
    }

    /** Full-screen pairing code prompt, shown until the backend claims this device. */
    private fun updatePairingOverlay() {
        val show = syncManager.pairingStatus == SyncManager.Pairing.UNPAIRED
        pairingOverlay.visibility = if (show) View.VISIBLE else View.GONE
    }

    /** Renders the notices ticker from the device's widget settings. Weather is shown as an interstitial slide. */
    private fun applyWidgets(widgets: SyncManager.WidgetSettings) {
        // Notices: single-line marquee ticker at the bottom, styled per device.
        if (widgets.noticesEnabled && widgets.noticesText.isNotBlank()) {
            val density = resources.displayMetrics.density
            noticesText.text = widgets.noticesText
            noticesText.ellipsize = null // scroll the full text; never truncate with "…"
            noticesText.setTextSize(TypedValue.COMPLEX_UNIT_SP, widgets.noticesSize.toFloat())
            noticesText.setTextColor(parseColor(widgets.noticesColor, 0xFFFFFFFF.toInt()))
            noticesText.typeface = android.graphics.Typeface.create(
                if (widgets.noticesFont.isBlank()) "sans-serif" else widgets.noticesFont,
                android.graphics.Typeface.NORMAL
            )
            noticesBar.setBackgroundColor(parseColor(widgets.noticesBg, 0x66000000))
            // Fixed box height (dp) with vertically centred text; 0 = auto (wrap).
            val lp = noticesBar.layoutParams
            lp.height = if (widgets.noticesHeight > 0) {
                (widgets.noticesHeight * density).toInt()
            } else {
                android.view.ViewGroup.LayoutParams.WRAP_CONTENT
            }
            noticesBar.layoutParams = lp
            noticesBar.visibility = View.VISIBLE
            // (Re)start the continuous scroll only when text or speed changed, so the
            // 60s sync doesn't make an unchanged ticker jump back mid-run.
            val restart = tickerAnimator == null || tickerText != widgets.noticesText ||
                tickerSpeedDp != widgets.noticesSpeed
            tickerSpeedDp = widgets.noticesSpeed
            if (restart) {
                tickerText = widgets.noticesText
                startTicker()
            }
        } else {
            stopTicker()
            noticesBar.visibility = View.GONE
        }
    }

    /**
     * Continuously scrolls the notices text right-to-left across the full width
     * of the bar. Runs forever at a constant speed; restarts from the right edge
     * each loop. Waits for layout so the bar/text widths are known.
     */
    private fun startTicker() {
        tickerAnimator?.cancel()
        noticesText.translationX = 0f
        noticesText.post {
            val barWidth = noticesBar.width
            // Measure the FULL intrinsic text width so the whole string scrolls; the
            // view is wrap_content inside a match_parent bar, so its laid-out width
            // would otherwise be capped at the bar width (and ellipsized to "…").
            val textWidth = kotlin.math.ceil(
                noticesText.paint.measureText(noticesText.text?.toString() ?: "")
            ).toInt()
            if (barWidth <= 0 || textWidth <= 0) return@post
            // Grow the view to the full text width so it renders every character.
            val lp = noticesText.layoutParams
            if (lp.width != textWidth) { lp.width = textWidth; noticesText.layoutParams = lp }
            val start = barWidth.toFloat()      // enter from off-screen right
            val end = -textWidth.toFloat()      // exit fully off-screen left
            val speedPx = tickerSpeedDp * resources.displayMetrics.density // dp/s from device config
            val durationMs = ((start - end) / speedPx * 1000f).toLong().coerceAtLeast(1000L)
            tickerAnimator = android.animation.ValueAnimator.ofFloat(start, end).apply {
                duration = durationMs
                interpolator = android.view.animation.LinearInterpolator()
                repeatCount = android.animation.ValueAnimator.INFINITE
                repeatMode = android.animation.ValueAnimator.RESTART
                addUpdateListener { noticesText.translationX = it.animatedValue as Float }
                start()
            }
        }
    }

    private fun stopTicker() {
        tickerAnimator?.cancel()
        tickerAnimator = null
        tickerText = null
    }

    // ---------- Immersive Mode ----------

    private fun hideSystemBars() {
        val ctrl = WindowInsetsControllerCompat(window, window.decorView)
        ctrl.hide(WindowInsetsCompat.Type.systemBars())
        ctrl.systemBarsBehavior =
            WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) hideSystemBars()
    }

    // ---------- ExoPlayer ----------

    private fun setupExoPlayer() {
        exoPlayer = ExoPlayer.Builder(this).build().apply {
            volume = 0f
            repeatMode = Player.REPEAT_MODE_OFF
        }
        playerView.player = exoPlayer
        playerView.useController = false

        exoPlayer.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(playbackState: Int) {
                if (playbackState == Player.STATE_ENDED) {
                    slideShowController.onVideoDone()
                }
            }
        })
    }

    // ---------- PlayerCallback ----------

    override fun showImage(item: MediaItem) {
        val bitmap = preloaded?.takeIf { it.first == item.file }?.second
            ?: loadScaledBitmap(item.file) ?: return
        preloaded = null

        val backView = if (frontImageView == imageViewA) imageViewB else imageViewA
        backView.setImageBitmap(bitmap)

        if (playerView.alpha > 0f) {
            playerView.animate().alpha(0f).setDuration(CROSSFADE_MS).withEndAction {
                exoPlayer.stop()
            }.start()
        }

        backView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        frontImageView?.animate()?.alpha(0f)?.setDuration(CROSSFADE_MS)?.start()
        frontImageView = backView

        weatherView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        emptyView.visibility = View.GONE
    }

    override fun showVideo(item: MediaItem) {
        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        frontImageView = null

        exoPlayer.stop()
        exoPlayer.clearMediaItems()
        exoPlayer.setMediaItem(Media3Item.fromUri(Uri.fromFile(item.file)))
        exoPlayer.prepare()
        exoPlayer.play()

        playerView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        weatherView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        emptyView.visibility = View.GONE
    }

    override fun showWeather(item: MediaItem) {
        populateWeather(latestWeather)

        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        frontImageView = null
        if (playerView.alpha > 0f) {
            playerView.animate().alpha(0f).setDuration(CROSSFADE_MS).withEndAction { exoPlayer.stop() }.start()
        }

        weatherView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        emptyView.visibility = View.GONE
    }

    override fun showEmpty() {
        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        playerView.animate().alpha(0f).setDuration(CROSSFADE_MS).withEndAction {
            exoPlayer.stop()
        }.start()
        weatherView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        frontImageView = null
        emptyView.visibility = View.VISIBLE
    }

    // ---------- Weather interstitial ----------

    /**
     * Builds the interstitial from the global layout config: background + scrim, and the
     * enabled elements (city, 3-day forecast, analog clock, free texts) each placed by its
     * grid cell. Falls back to sensible defaults when no config is present.
     */
    private fun populateWeather(w: SyncManager.WeatherInfo?) {
        val cfg = syncManager.getWeatherLayout()
        val dm = resources.displayMetrics
        fun dp(v: Float) = (v * dm.density).toInt()

        // Background (downloaded pool image) + readability scrim.
        val bg = syncManager.getWeatherBackgroundFile()
        wxBg.setImageBitmap(bg?.let { BitmapFactory.decodeFile(it.absolutePath) })
        wxScrim.alpha = (cfg?.optInt("scrim", 20) ?: 20).coerceIn(0, 100) / 100f

        wxLayer.removeAllViews()

        // Vertical layout is a fixed stack of equal rows (Header, 1-6, Footer). Each element
        // lands in its row and is aligned horizontally within it — no overlap across rows.
        val rowsBox = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val rowFrames = WX_ROWS.map { android.widget.FrameLayout(this) }
        for (fr in rowFrames) {
            rowsBox.addView(fr, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f))
        }
        wxLayer.addView(
            rowsBox,
            android.widget.FrameLayout.LayoutParams(
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT,
                android.widget.FrameLayout.LayoutParams.MATCH_PARENT
            )
        )
        // Places a view into its configured row, aligned horizontally + vertically centred.
        fun place(view: View, c: org.json.JSONObject?, w0: Int = android.widget.FrameLayout.LayoutParams.WRAP_CONTENT, h0: Int = android.widget.FrameLayout.LayoutParams.WRAP_CONTENT) {
            val lp = android.widget.FrameLayout.LayoutParams(w0, h0)
            lp.gravity = wxHGravity(c) or Gravity.CENTER_VERTICAL
            rowFrames[wxRowIndex(c?.optString("v", "header"))].addView(view, lp)
        }

        // City name (from device Wetter-Ort / live weather).
        val cityCfg = cfg?.optJSONObject("city")
        if (cityCfg == null || cityCfg.optBoolean("show", true)) {
            val city = (w?.location ?: syncManager.getWidgetSettings().weatherLocation)
                .substringBefore(',').trim()
            val tv = TextView(this).apply {
                text = if (city.isNotEmpty()) city else getString(R.string.weather_title)
                setTextColor(parseColor(cityCfg?.optString("color"), 0xFFFFFFFF.toInt()))
                setTextSize(TypedValue.COMPLEX_UNIT_SP, (cityCfg?.optInt("size", 34) ?: 34).toFloat())
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                setShadowLayer(8f, 0f, 0f, 0xFF000000.toInt())
            }
            place(tv, cityCfg)
        }

        // 3-day forecast row, scaled by the configured percentage.
        val fcCfg = cfg?.optJSONObject("forecast")
        if (fcCfg == null || fcCfg.optBoolean("show", true)) {
            val scale = (fcCfg?.optInt("size", 100) ?: 100).coerceIn(20, 300) / 100f
            val row = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER
            }
            for (day in (w?.forecast ?: emptyList())) {
                val col = LinearLayout(this).apply {
                    orientation = LinearLayout.VERTICAL
                    gravity = Gravity.CENTER_HORIZONTAL
                    setPadding(dp(10f), 0, dp(10f), 0)
                }
                fun label(text: String, sizeSp: Float, bold: Boolean) = TextView(this).apply {
                    this.text = text
                    setTextColor(0xFFFFFFFF.toInt())
                    setTextSize(TypedValue.COMPLEX_UNIT_SP, sizeSp * scale)
                    if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
                    gravity = Gravity.CENTER
                    setShadowLayer(6f, 0f, 0f, 0xFF000000.toInt())
                }
                col.addView(label(day.weekday, 20f, true))
                col.addView(label(day.date, 15f, false))
                col.addView(label(weatherEmoji(day.icon), 40f, false).apply {
                    setPadding(0, dp(4f), 0, dp(4f))
                    setShadowLayer(0f, 0f, 0f, 0)
                })
                col.addView(label(day.tempC?.let { "$it°" } ?: "–", 22f, true))
                row.addView(col)
            }
            place(row, fcCfg)
        }

        // Analog clock (ticks on its own once attached).
        val clkCfg = cfg?.optJSONObject("clock")
        if (clkCfg == null || clkCfg.optBoolean("show", true)) {
            val sizeDp = (clkCfg?.optInt("size", 150) ?: 150).coerceIn(40, 600)
            place(android.widget.AnalogClock(this), clkCfg, dp(sizeDp.toFloat()), dp(sizeDp.toFloat()))
        }

        // Free-text blocks.
        cfg?.optJSONArray("texts")?.let { arr ->
            for (i in 0 until arr.length()) {
                val t = arr.optJSONObject(i) ?: continue
                val txt = t.optString("text", "").trim()
                if (txt.isEmpty()) continue
                val tv = TextView(this).apply {
                    text = txt
                    setTextColor(parseColor(t.optString("color"), 0xFFFFFFFF.toInt()))
                    setTextSize(TypedValue.COMPLEX_UNIT_SP, t.optInt("size", 20).toFloat())
                    setShadowLayer(6f, 0f, 0f, 0xFF000000.toInt())
                }
                place(tv, t)
            }
        }
    }

    /** Horizontal alignment for an element's `h` value. */
    private fun wxHGravity(cfg: org.json.JSONObject?): Int = when (cfg?.optString("h", "center")) {
        "left" -> Gravity.START
        "right" -> Gravity.END
        else -> Gravity.CENTER_HORIZONTAL
    }

    /** Row index for an element's `v` value (legacy top/middle/bottom mapped onto rows). */
    private fun wxRowIndex(v: String?): Int {
        val mapped = when (v) { "top" -> "header"; "middle" -> "4"; "bottom" -> "footer"; else -> v }
        val i = WX_ROWS.indexOf(mapped)
        return if (i >= 0) i else 0
    }

    /** Parses a #rgb/#rrggbb color, or returns [fallback]. */
    private fun parseColor(s: String?, fallback: Int): Int =
        try {
            if (s.isNullOrBlank()) fallback else android.graphics.Color.parseColor(s)
        } catch (e: Exception) {
            fallback
        }

    /** Maps an OpenWeather icon code (e.g. "04d") to a weather emoji. */
    private fun weatherEmoji(icon: String): String = when (icon.take(2)) {
        "01" -> "☀️"
        "02" -> "🌤️"
        "03" -> "⛅"
        "04" -> "☁️"
        "09" -> "🌧️"
        "10" -> "🌦️"
        "11" -> "⛈️"
        "13" -> "❄️"
        "50" -> "🌫️"
        else -> "🌡️"
    }

    override fun onSlideStarted(durationMs: Long, next: MediaItem?) {
        // Discreet progress line for image slides (videos have an unknown duration).
        slideAnimator?.cancel()
        if (durationMs > 0) {
            slideProgress.visibility = View.VISIBLE
            slideProgress.progress = 0
            slideAnimator = ObjectAnimator.ofInt(slideProgress, "progress", 0, slideProgress.max)
                .apply {
                    duration = durationMs
                    interpolator = LinearInterpolator()
                    start()
                }
        } else {
            slideProgress.visibility = View.GONE
        }
        // Preload the next image so the upcoming crossfade is instant.
        if (next != null && next.type == MediaType.IMAGE) {
            val file = next.file
            syncExecutor.execute {
                val bmp = loadScaledBitmap(file)
                if (bmp != null) mainHandler.post { preloaded = file to bmp }
            }
        }
    }

    // ---------- Touch handling ----------

    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        if (ev.action == MotionEvent.ACTION_DOWN) {
            val cornerPx = CORNER_DP * resources.displayMetrics.density
            val inCorner = ev.x >= (window.decorView.width - cornerPx) && ev.y <= cornerPx
            if (inCorner) recordTap()
        }
        // Consume all events; AlertDialog windows are unaffected (separate Window)
        return true
    }

    /** Hardware/soft MENU key is a second, discreet way into the settings menu. */
    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        if (keyCode == KeyEvent.KEYCODE_MENU) {
            showPinDialog()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    private fun recordTap() {
        val now = System.currentTimeMillis()
        tapTimestamps.addLast(now)
        while (tapTimestamps.isNotEmpty() && now - tapTimestamps.first() > TAP_WINDOW_MS) {
            tapTimestamps.removeFirst()
        }
        if (tapTimestamps.size >= TAP_COUNT_REQUIRED) {
            tapTimestamps.clear()
            showPinDialog()
        }
    }

    // ---------- Maintenance dialogs ----------

    /**
     * Builds a nicely inset, rounded input field for a dialog and returns the
     * container to pass to [MaterialAlertDialogBuilder.setView] plus the EditText
     * to read. Keeps the input legible and padded on the dark dialog surface.
     */
    private fun dialogInput(hintRes: Int, inputType: Int, prefill: String? = null): Pair<View, EditText> {
        val d = resources.displayMetrics.density
        val edit = EditText(this).apply {
            setTextColor(0xFFF1F5F9.toInt())
            setHintTextColor(0xFF8A93A3.toInt())
            hint = getString(hintRes)
            this.inputType = inputType
            setBackgroundResource(R.drawable.tw_input_bg)
            val ip = (14 * d).toInt()
            setPadding(ip, (13 * d).toInt(), ip, (13 * d).toInt())
            textSize = 16f
            if (prefill != null) setText(prefill)
        }
        val wrap = android.widget.FrameLayout(this).apply {
            val h = (22 * d).toInt()
            setPadding(h, (8 * d).toInt(), h, (4 * d).toInt())
            addView(
                edit,
                android.widget.FrameLayout.LayoutParams(
                    android.view.ViewGroup.LayoutParams.MATCH_PARENT,
                    android.view.ViewGroup.LayoutParams.WRAP_CONTENT
                )
            )
        }
        return wrap to edit
    }

    private fun showPinDialog() {
        val (view, pinInput) = dialogInput(
            R.string.maintenance_pin_hint,
            InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
        )
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.maintenance_title)
            .setMessage(R.string.maintenance_pin_prompt)
            .setView(view)
            .setPositiveButton(R.string.maintenance_ok) { _, _ ->
                if (pinInput.text.toString() == MAINTENANCE_PIN) {
                    showMaintenanceMenu()
                } else {
                    Toast.makeText(this, R.string.maintenance_wrong_pin, Toast.LENGTH_SHORT).show()
                }
            }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun showMaintenanceMenu() {
        val options = arrayOf(
            getString(R.string.maintenance_server),
            getString(R.string.maintenance_pairing),
            getString(R.string.maintenance_sync_now),
            getString(R.string.maintenance_reload),
            getString(R.string.settings_update),
            getString(R.string.settings_help),
            getString(R.string.settings_storage),
            getString(R.string.settings_export_logs),
            getString(R.string.maintenance_exit)
        )
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.maintenance_title)
            .setItems(options) { _, which ->
                when (which) {
                    0 -> showServerUrlDialog()
                    1 -> showPairingDialog()
                    2 -> syncNow(userTriggered = true)
                    3 -> slideShowController.reload()
                    4 -> checkForUpdateFromMenu()
                    5 -> showHelpDialog()
                    6 -> showStorageDialog()
                    7 -> exportLogs()
                    8 -> confirmExit()
                }
            }
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun confirmExit() {
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.exit_confirm_title)
            .setMessage(R.string.exit_confirm_msg)
            .setPositiveButton(R.string.exit_confirm_ok) { _, _ ->
                AppLog.i(TAG, "app exit requested by operator")
                finishAndRemoveTask()
            }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    /** Read-only help &amp; contact card fed centrally from the dashboard via the playlist sync. */
    private fun showHelpDialog() {
        val h = syncManager.getHelpInfo()
        // Version + app name fall back to what's actually installed when unset on the server.
        val appName = h.app.ifBlank { getString(R.string.app_name) }
        val version = h.version.ifBlank { appVersionText() }
        val body = buildString {
            if (h.company.isNotBlank()) append(h.company).append("\n")
            append(getString(R.string.help_app, appName)).append("\n")
            append(getString(R.string.help_version, version)).append("\n")
            val hasContact = h.phone.isNotBlank() || h.contact.isNotBlank() ||
                h.supportMail.isNotBlank() || h.supportPhone.isNotBlank() || h.website.isNotBlank()
            if (hasContact) append("\n")
            if (h.contact.isNotBlank()) append(getString(R.string.help_contact, h.contact)).append("\n")
            if (h.phone.isNotBlank()) append(getString(R.string.help_phone, h.phone)).append("\n")
            if (h.supportMail.isNotBlank()) append(getString(R.string.help_support_mail, h.supportMail)).append("\n")
            if (h.supportPhone.isNotBlank()) append(getString(R.string.help_support_phone, h.supportPhone)).append("\n")
            if (h.website.isNotBlank()) append(getString(R.string.help_website, h.website)).append("\n")
        }.trim()
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.settings_help)
            .setMessage(body)
            .setPositiveButton(R.string.help_close, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    /** Lets the technician relocate downloaded media + log exports to a chosen path. */
    private fun showStorageDialog() {
        val current = settingsPrefs.getString(KEY_STORAGE_BASE, "").orEmpty()
        val effective = resolveMediaDir().parentFile?.absolutePath ?: ""
        val (view, input) = dialogInput(
            R.string.storage_hint,
            InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI,
            current
        )
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.settings_storage)
            .setMessage(getString(R.string.storage_current, effective))
            .setView(view)
            .setPositiveButton(R.string.maintenance_ok) { _, _ -> applyStoragePath(input.text.toString().trim()) }
            .setNeutralButton(R.string.storage_reset) { _, _ -> applyStoragePath("") }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun applyStoragePath(path: String) {
        // Writing outside app-specific storage needs "all files access" on Android 11+.
        if (path.isNotEmpty() && Build.VERSION.SDK_INT >= Build.VERSION_CODES.R
            && !Environment.isExternalStorageManager()
        ) {
            Toast.makeText(this, R.string.storage_need_permission, Toast.LENGTH_LONG).show()
            try {
                startActivity(
                    Intent(
                        Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION,
                        Uri.parse("package:$packageName")
                    )
                )
            } catch (e: Exception) {
                try {
                    startActivity(Intent(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION))
                } catch (e2: Exception) {
                    AppLog.w(TAG, "cannot open all-files-access settings: ${e2.message}")
                }
            }
            // Fall through: save the path so it takes effect once permission is granted.
        }
        settingsPrefs.edit().apply {
            if (path.isEmpty()) remove(KEY_STORAGE_BASE) else putString(KEY_STORAGE_BASE, path)
        }.apply()
        AppLog.i(TAG, "storage base set to '${path.ifEmpty { "(default)" }}'")
        Toast.makeText(this, R.string.storage_saved, Toast.LENGTH_SHORT).show()
        // Rebuild SyncManager/PlaylistManager against the new directory.
        recreate()
    }

    /** Exports the collected logs to a timestamped file next to the media directory. */
    private fun exportLogs() {
        syncExecutor.execute {
            val baseDir = resolveMediaDir().parentFile ?: filesDir
            val stamp = SimpleDateFormat("yyyyMMdd-HHmmss", Locale.GERMANY).format(Date())
            val dest = File(baseDir, "teamworkshow-logs-$stamp.log")
            val ok = AppLog.exportTo(dest)
            mainHandler.post {
                val text = if (ok != null) {
                    getString(R.string.logs_exported, dest.absolutePath)
                } else {
                    getString(R.string.logs_export_failed)
                }
                Toast.makeText(this, text, Toast.LENGTH_LONG).show()
                hideSystemBars()
            }
        }
    }

    private fun showServerUrlDialog() {
        val (view, urlInput) = dialogInput(
            R.string.server_url_hint,
            InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI,
            syncManager.getServerUrl() ?: ""
        )
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.maintenance_server)
            .setView(view)
            .setPositiveButton(R.string.maintenance_ok) { _, _ ->
                syncManager.setServerUrl(urlInput.text.toString())
                syncNow(userTriggered = true)
            }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun showPairingDialog() {
        val (view, codeInput) = dialogInput(
            R.string.pairing_hint,
            InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS,
            syncManager.getPairingCode() ?: ""
        )
        MaterialAlertDialogBuilder(this, R.style.ThemeOverlay_TeamworkShow_Dialog)
            .setTitle(R.string.maintenance_pairing)
            .setView(view)
            .setPositiveButton(R.string.maintenance_ok) { _, _ ->
                val code = codeInput.text.toString().trim()
                syncManager.setPairingCode(code)
                val msg = if (code.isEmpty()) {
                    getString(R.string.pairing_cleared)
                } else {
                    getString(R.string.pairing_saved, code)
                }
                Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
                syncNow(userTriggered = true)
            }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    // ---------- Bitmap loading ----------

    private fun loadScaledBitmap(file: File): Bitmap? {
        val opts = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.absolutePath, opts)
        val dm = resources.displayMetrics
        var scale = 1
        while (opts.outWidth / scale > dm.widthPixels || opts.outHeight / scale > dm.heightPixels) {
            scale *= 2
        }
        opts.inSampleSize = scale
        opts.inJustDecodeBounds = false
        return BitmapFactory.decodeFile(file.absolutePath, opts)
    }

    // ---------- Lifecycle ----------

    private fun appVersionText(): String = try {
        "v" + packageManager.getPackageInfo(packageName, 0).versionName
    } catch (e: Exception) {
        ""
    }

    override fun onDestroy() {
        super.onDestroy()
        slideAnimator?.cancel()
        mainHandler.removeCallbacksAndMessages(null)
        syncExecutor.shutdownNow()
        slideShowController.stop()
        exoPlayer.release()
    }
}
