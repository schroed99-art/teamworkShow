package com.example.teamworkshow

import android.animation.ObjectAnimator
import android.app.AlertDialog
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.util.TypedValue
import android.view.Gravity
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
import com.example.teamworkshow.model.MediaItem
import com.example.teamworkshow.model.MediaType
import com.example.teamworkshow.network.SyncManager
import com.example.teamworkshow.player.PlayerCallback
import com.example.teamworkshow.player.SlideShowController
import com.example.teamworkshow.playlist.PlaylistManager
import java.io.File
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity(), PlayerCallback {

    private lateinit var imageViewA: ImageView
    private lateinit var imageViewB: ImageView
    private lateinit var playerView: PlayerView
    private lateinit var emptyView: View
    private lateinit var slideProgress: ProgressBar
    private lateinit var noticesBar: TextView

    // Weather forecast interstitial (a file-less slide); its contents are built at
    // runtime from the global layout config (background + grid-positioned elements).
    private lateinit var weatherView: View
    private lateinit var wxBg: ImageView
    private lateinit var wxScrim: View
    private lateinit var wxLayer: android.widget.FrameLayout
    private var latestWeather: SyncManager.WeatherInfo? = null
    private var lastStructSig: String? = null
    private lateinit var downloadOverlay: View
    private lateinit var downloadStatus: TextView
    private lateinit var downloadProgress: ProgressBar

    private var frontImageView: ImageView? = null
    private var preloaded: Pair<File, Bitmap>? = null
    private var slideAnimator: ObjectAnimator? = null

    private lateinit var exoPlayer: ExoPlayer
    private lateinit var slideShowController: SlideShowController

    private lateinit var syncManager: SyncManager
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
        private const val CROSSFADE_MS = 300L
        private const val MAINTENANCE_PIN = "0000"
        private const val TAP_COUNT_REQUIRED = 5
        private const val TAP_WINDOW_MS = 2_000L
        private const val CORNER_DP = 150f
        private const val SYNC_INTERVAL_MS = 60_000L
        private const val WEATHER_PLACEHOLDER = "__weather__"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
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
        weatherView = findViewById(R.id.weatherView)
        wxBg = findViewById(R.id.wxBg)
        wxScrim = findViewById(R.id.wxScrim)
        wxLayer = findViewById(R.id.wxLayer)
        downloadOverlay = findViewById(R.id.downloadOverlay)
        downloadStatus = findViewById(R.id.downloadStatus)
        downloadProgress = findViewById(R.id.downloadProgress)
        findViewById<TextView>(R.id.versionLabel).text = appVersionText()

        setupExoPlayer()

        val mediaDir = File(getExternalFilesDir(null), "media").also { it.mkdirs() }
        syncManager = SyncManager(this, mediaDir)
        syncManager.listener = downloadListener

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
                if (userTriggered) {
                    val msg = if (changed) R.string.sync_updated else R.string.sync_no_change
                    Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    /** Renders the notices ticker from the device's widget settings. Weather is shown as an interstitial slide. */
    private fun applyWidgets(widgets: SyncManager.WidgetSettings) {
        // Notices: single-line marquee ticker at the bottom.
        if (widgets.noticesEnabled && widgets.noticesText.isNotBlank()) {
            noticesBar.text = widgets.noticesText
            noticesBar.visibility = View.VISIBLE
            noticesBar.isSelected = true // required to start the marquee
        } else {
            noticesBar.visibility = View.GONE
        }
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
            addWxElement(tv, cityCfg)
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
            addWxElement(row, fcCfg)
        }

        // Analog clock (ticks on its own once attached).
        val clkCfg = cfg?.optJSONObject("clock")
        if (clkCfg == null || clkCfg.optBoolean("show", true)) {
            val sizeDp = (clkCfg?.optInt("size", 150) ?: 150).coerceIn(40, 600)
            val lp = android.widget.FrameLayout.LayoutParams(dp(sizeDp.toFloat()), dp(sizeDp.toFloat()))
            lp.gravity = wxGravity(clkCfg)
            wxLayer.addView(android.widget.AnalogClock(this), lp)
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
                addWxElement(tv, t)
            }
        }
    }

    /** Adds a view to the interstitial layer, positioned by its grid config (h/v). */
    private fun addWxElement(view: View, cfg: org.json.JSONObject?) {
        val lp = android.widget.FrameLayout.LayoutParams(
            android.widget.FrameLayout.LayoutParams.WRAP_CONTENT,
            android.widget.FrameLayout.LayoutParams.WRAP_CONTENT
        )
        lp.gravity = wxGravity(cfg)
        wxLayer.addView(view, lp)
    }

    /** Maps an element's {h,v} grid cell to a FrameLayout gravity. */
    private fun wxGravity(cfg: org.json.JSONObject?): Int {
        val h = when (cfg?.optString("h", "center")) {
            "left" -> Gravity.START
            "right" -> Gravity.END
            else -> Gravity.CENTER_HORIZONTAL
        }
        val v = when (cfg?.optString("v", "middle")) {
            "top" -> Gravity.TOP
            "bottom" -> Gravity.BOTTOM
            else -> Gravity.CENTER_VERTICAL
        }
        return h or v
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

    private fun showPinDialog() {
        val pinInput = EditText(this).apply {
            hint = getString(R.string.maintenance_pin_hint)
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.maintenance_title)
            .setView(pinInput)
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
            getString(R.string.maintenance_exit)
        )
        AlertDialog.Builder(this)
            .setTitle(R.string.maintenance_title)
            .setItems(options) { _, which ->
                when (which) {
                    0 -> showServerUrlDialog()
                    1 -> showPairingDialog()
                    2 -> syncNow(userTriggered = true)
                    3 -> slideShowController.reload()
                    4 -> confirmExit()
                }
            }
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun confirmExit() {
        AlertDialog.Builder(this)
            .setTitle(R.string.exit_confirm_title)
            .setMessage(R.string.exit_confirm_msg)
            .setPositiveButton(R.string.exit_confirm_ok) { _, _ -> finishAndRemoveTask() }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun showServerUrlDialog() {
        val urlInput = EditText(this).apply {
            hint = getString(R.string.server_url_hint)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            setText(syncManager.getServerUrl() ?: "")
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.maintenance_server)
            .setView(urlInput)
            .setPositiveButton(R.string.maintenance_ok) { _, _ ->
                syncManager.setServerUrl(urlInput.text.toString())
                syncNow(userTriggered = true)
            }
            .setNegativeButton(R.string.maintenance_cancel, null)
            .setOnDismissListener { hideSystemBars() }
            .show()
    }

    private fun showPairingDialog() {
        val codeInput = EditText(this).apply {
            hint = getString(R.string.pairing_hint)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS
            setText(syncManager.getPairingCode() ?: "")
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.maintenance_pairing)
            .setView(codeInput)
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
