package de.teamworkshow.app

import android.animation.ObjectAnimator
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import android.content.Intent
import android.content.pm.ActivityInfo
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
import de.teamworkshow.app.model.MediaItem
import de.teamworkshow.app.model.MediaType
import de.teamworkshow.app.model.NewsSlide
import de.teamworkshow.app.network.SyncManager
import de.teamworkshow.app.update.UpdateManager
import de.teamworkshow.app.player.Stage
import de.teamworkshow.app.playlist.PlaylistManager
import de.teamworkshow.app.util.AppLog
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {

    private lateinit var noticesBar: android.widget.FrameLayout
    private lateinit var noticesText: TextView
    private var tickerAnimator: android.animation.ValueAnimator? = null
    private var tickerText: String? = null
    private var tickerSpeedDp: Int = 90

    // The screen is one Stage (full screen) or a tree of them (one per zone leaf).
    // Each Stage owns its views, player and playlist; everything below is per-screen.
    // Built in pre-order (see buildStages); the list order matches leaf indices.
    private val stages = ArrayList<Stage>()
    private var zoneLayoutSig: String = "single"

    // Weather forecast interstitial (a file-less slide); its contents are built at
    // runtime from the global layout config (background + grid-positioned elements)
    // into whichever zone is currently showing it.
    private val WX_ROWS = listOf("header", "1", "2", "3", "4", "5", "6", "footer")
    private var latestWeather: SyncManager.WeatherInfo? = null
    private var lastStructSig: String? = null
    private lateinit var downloadOverlay: View
    private lateinit var downloadStatus: TextView
    private lateinit var downloadProgress: ProgressBar
    private lateinit var pairingOverlay: View
    private lateinit var pairingCodeLabel: TextView
    private lateinit var pairingServerLabel: TextView

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
        private const val NEWS_PLACEHOLDER = "__news__"
        private const val PREFS = "teamworkshow_settings"
        private const val KEY_STORAGE_BASE = "storage_base"
        private const val KEY_FORMAT = "display_format"
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

        // Lock the activity to the orientation configured for this device (from the
        // last playlist sync). Set before setContentView so the right layout-*/values-*
        // resources are inflated. A later format change recreates the activity.
        applyDisplayOrientation()
        setContentView(R.layout.activity_main)
        hideSystemBars()

        noticesBar = findViewById(R.id.noticesBar)
        noticesText = findViewById(R.id.noticesText)
        downloadOverlay = findViewById(R.id.downloadOverlay)
        downloadStatus = findViewById(R.id.downloadStatus)
        downloadProgress = findViewById(R.id.downloadProgress)
        pairingOverlay = findViewById(R.id.pairingOverlay)
        pairingCodeLabel = findViewById(R.id.pairingCode)
        pairingServerLabel = findViewById(R.id.pairingServerLabel)
        // Always-visible way out of the pairing screen (fixes "stuck in Gerät koppeln"):
        // the same PIN-gated maintenance entry as the discreet 5-tap corner.
        findViewById<View>(R.id.pairingSettingsBtn).setOnClickListener { showPinDialog() }
        findViewById<TextView>(R.id.versionLabel).text = appVersionText()
        updateBadge = findViewById(R.id.updateBadge)
        updateBadge.setOnClickListener { onUpdateBadgeClicked() }

        val mediaDir = resolveMediaDir()
        AppLog.i(TAG, "media dir: ${mediaDir.absolutePath}")
        syncManager = SyncManager(this, mediaDir)
        syncManager.listener = downloadListener
        // Show this device's pairing code until the backend recognises it.
        pairingCodeLabel.text = syncManager.getOrCreatePairingCode()
        updatePairingOverlay()

        buildStages(mediaDir)

        // Immediate sync on launch, then poll periodically.
        mainHandler.post(syncRunnable)
    }

    // ---------- Zones ----------

    /**
     * Creates the stage(s) for the device's current zone tree: one full-screen stage
     * (single), or one Stage per leaf of a nested split/custom tree. Called once per
     * activity instance — a later structural change recreates the activity (see
     * [syncNow]), the same route a display-format change takes.
     */
    private fun buildStages(mediaDir: File) {
        val tree = syncManager.getZoneTree()
        zoneLayoutSig = syncManager.zoneLayoutSignature()

        val container = findViewById<LinearLayout>(R.id.stageContainer)
        container.removeAllViews()
        stages.clear()

        if (tree == null) {
            // Single stage: it IS the screen, fed by the folder scan + server meta
            // exactly as before zones existed.
            container.orientation = LinearLayout.VERTICAL
            val root = inflateZoneStage(container, 1f, vertical = true)

            val playlist = PlaylistManager(mediaDir)
            playlist.metaProvider = { syncManager.getPlaylistMeta() }
            playlist.weatherProvider = {
                syncManager.getWeatherSlides().map {
                    MediaItem(File(mediaDir, WEATHER_PLACEHOLDER), MediaType.WEATHER, it.durationMs, it.position)
                }
            }
            playlist.newsProvider = {
                syncManager.getNewsSlides().map {
                    // A news background lives in a hidden dir; use it as the item's file so
                    // Stage can decode it via the shared bitmap loader (else the placeholder).
                    val bg = syncManager.getNewsBackgroundFile(it.bg)
                    MediaItem(bg ?: File(mediaDir, NEWS_PLACEHOLDER), MediaType.NEWS, it.durationMs, it.position, news = it)
                }
            }
            val stage = newStage(root, playlist, mediaDir)
            stages.add(stage)
            stage.start()
            return
        }

        // Zone tree (split or custom): nested LinearLayouts, one Stage per leaf. Each
        // leaf's playlist re-reads its slides by pre-order index, so a periodic sync
        // reloads content smoothly; a structural change recreates the whole activity.
        container.orientation = LinearLayout.VERTICAL
        val leafIndex = intArrayOf(0)
        addZoneNode(container, parentVertical = true, node = tree, weight = 1f, mediaDir = mediaDir, leafIndex = leafIndex)
        stages.forEach { it.start() }
        AppLog.i(TAG, "zones: ${stages.size} stage(s), sig=$zoneLayoutSig")
    }

    /**
     * Adds one zone node into [parent] (a LinearLayout laid out along its own axis).
     * A leaf inflates a zone_stage and gets a Stage bound to its pre-order index; a
     * split adds a nested LinearLayout and recurses. [weight] is this node's share of
     * the parent; [parentVertical] decides whether weight applies to height or width.
     */
    private fun addZoneNode(
        parent: LinearLayout,
        parentVertical: Boolean,
        node: SyncManager.ZoneNode,
        weight: Float,
        mediaDir: File,
        leafIndex: IntArray,
    ) {
        when (node) {
            is SyncManager.ZoneNode.Leaf -> {
                val root = inflateZoneStage(parent, weight, parentVertical)
                val index = leafIndex[0]++
                stages.add(newStage(root, leafPlaylist(mediaDir, index), mediaDir))
            }
            is SyncManager.ZoneNode.Split -> {
                val vertical = node.axis == "rows"
                val box = LinearLayout(this).apply {
                    orientation = if (vertical) LinearLayout.VERTICAL else LinearLayout.HORIZONTAL
                    layoutParams = zoneWeightParams(weight, parentVertical)
                }
                parent.addView(box)
                node.children.forEach { addZoneNode(box, vertical, it.node, it.size, mediaDir, leafIndex) }
            }
        }
    }

    /** Inflates a zone_stage into [parent], weighted along the parent's axis. */
    private fun inflateZoneStage(parent: LinearLayout, weight: Float, vertical: Boolean): View {
        val v = layoutInflater.inflate(R.layout.zone_stage, parent, false)
        v.layoutParams = zoneWeightParams(weight, vertical)
        parent.addView(v)
        return v
    }

    /** LayoutParams that give a child [weight] along its parent's orientation. */
    private fun zoneWeightParams(weight: Float, parentVertical: Boolean) = LinearLayout.LayoutParams(
        if (parentVertical) LinearLayout.LayoutParams.MATCH_PARENT else 0,
        if (parentVertical) 0 else LinearLayout.LayoutParams.MATCH_PARENT,
        weight
    )

    /**
     * A leaf's playlist: exactly the slides the server assigned to leaf #[index] of
     * the current zone tree, re-read on each reload so periodic syncs pick up content
     * (or source) changes without recreating the activity.
     */
    private fun leafPlaylist(mediaDir: File, index: Int) = PlaylistManager(mediaDir).apply {
        itemsProvider = {
            val tree = syncManager.getZoneTree()
            val slides = if (tree == null) emptyList()
            else syncManager.leavesOf(tree).getOrNull(index)?.slides ?: emptyList()
            // File-less slides always play; a media slide only once its file is here.
            slides.map { zoneSlideToItem(mediaDir, it) }
                .filter { it.type == MediaType.WEATHER || it.type == MediaType.NEWS || it.file.isFile }
        }
    }

    /** Maps one zone slide to a playable item (weather/news are file-less). */
    private fun zoneSlideToItem(mediaDir: File, s: SyncManager.ZoneSlide): MediaItem = when (s.kind) {
        "weather" ->
            MediaItem(File(mediaDir, WEATHER_PLACEHOLDER), MediaType.WEATHER, s.durationMs, s.position)
        "news" -> {
            val news = NewsSlide(s.title, s.body, s.position, s.durationMs, s.bg, s.font, s.color, s.size)
            val bg = syncManager.getNewsBackgroundFile(s.bg)
            MediaItem(bg ?: File(mediaDir, NEWS_PLACEHOLDER), MediaType.NEWS, s.durationMs, s.position, news = news)
        }
        else -> {
            val file = File(mediaDir, s.name)
            val type = if (file.extension.lowercase() == "mp4") MediaType.VIDEO else MediaType.IMAGE
            MediaItem(file, type, s.durationMs, s.position)
        }
    }

    private fun newStage(root: View, playlist: PlaylistManager, mediaDir: File) = Stage(
        context = this,
        root = root,
        playlist = playlist,
        bitmapLoader = { file -> loadScaledBitmap(file) },
        preloader = { file, done ->
            syncExecutor.execute {
                val bmp = loadScaledBitmap(file)
                if (bmp != null) mainHandler.post { done(bmp) }
            }
        },
        weatherPainter = { stage -> populateWeather(stage, latestWeather) },
    )

    private fun forEachStage(action: (Stage) -> Unit) {
        stages.forEach(action)
    }

    // ---------- Server sync ----------

    /**
     * Applies the device's configured display format as a locked activity orientation.
     * Reads the pref directly so it works both in onCreate (before SyncManager exists)
     * and after a sync. Portrait/phone → portrait, landscape → landscape, tablet →
     * unspecified (follows the panel). Changing it recreates the activity, which
     * re-inflates the matching format-specific layout and dimen resources.
     */
    private fun applyDisplayOrientation() {
        val format = getSharedPreferences(PREFS, MODE_PRIVATE).getString(KEY_FORMAT, "portrait")
        val orientation = when (format) {
            "landscape" -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            "tablet" -> ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED
            else -> ActivityInfo.SCREEN_ORIENTATION_PORTRAIT // portrait, phone
        }
        if (requestedOrientation != orientation) requestedOrientation = orientation
    }

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
            // Fetch the forecast when weather is enabled OR a weather interstitial runs
            // anywhere — in single mode that is the playlist, in split mode either zone.
            val tree = syncManager.getZoneTree()
            val hasWeatherSlide = if (tree == null) {
                syncManager.getWeatherSlides().isNotEmpty()
            } else {
                syncManager.leavesOf(tree).any { leaf -> leaf.slides.any { it.kind == "weather" } }
            }
            val weather = if (widgets.weatherEnabled || hasWeatherSlide) syncManager.fetchWeather() else null
            // Reload when media changed OR the slide structure (order/duration/weather/zones) changed.
            val sig = syncManager.playlistSignature()
            mainHandler.post {
                latestWeather = weather
                // A changed zone split needs different views, not just a different list.
                if (syncManager.zoneLayoutSignature() != zoneLayoutSig) {
                    AppLog.i(TAG, "zone layout changed -> recreate")
                    recreate()
                    return@post
                }
                if (changed || sig != lastStructSig) {
                    lastStructSig = sig
                    forEachStage { it.reload() }
                }
                applyWidgets(widgets)
                updatePairingOverlay()
                // A changed display format recreates the activity to load its layout.
                applyDisplayOrientation()
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
        if (show) {
            // Surface the configured server URL so a typo (the usual reason a device
            // never pairs) is visible right on the overlay, not hidden in a dialog.
            val url = syncManager.getServerUrl()
            pairingServerLabel.text = if (url.isNullOrBlank()) {
                getString(R.string.pairing_no_server)
            } else {
                getString(R.string.pairing_server_label, url)
            }
        }
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

    // ---------- Weather interstitial ----------

    /**
     * Builds the interstitial from the global layout config: background + scrim, and the
     * enabled elements (city, 3-day forecast, analog clock, free texts) each placed by its
     * grid cell. Falls back to sensible defaults when no config is present.
     */
    private fun populateWeather(stage: Stage, w: SyncManager.WeatherInfo?) {
        val cfg = syncManager.getWeatherLayout()
        val dm = resources.displayMetrics
        fun dp(v: Float) = (v * dm.density).toInt()

        // Background (downloaded pool image) + readability scrim.
        val bg = syncManager.getWeatherBackgroundFile()
        stage.wxBg.setImageBitmap(bg?.let { BitmapFactory.decodeFile(it.absolutePath) })
        stage.wxScrim.alpha = (cfg?.optInt("scrim", 20) ?: 20).coerceIn(0, 100) / 100f

        val wxLayer = stage.wxLayer
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

    // ---------- Touch handling ----------

    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        if (ev.action == MotionEvent.ACTION_DOWN) {
            val cornerPx = CORNER_DP * resources.displayMetrics.density
            val inCorner = ev.x >= (window.decorView.width - cornerPx) && ev.y <= cornerPx
            if (inCorner) recordTap()
        }
        // While the pairing overlay is up, let touches reach its controls (the
        // "Einstellungen / Wartung" button) so the operator is never stuck. Otherwise
        // consume everything so the kiosk slideshow can't be interacted with.
        // (AlertDialog windows are separate Windows and unaffected either way.)
        if (::pairingOverlay.isInitialized && pairingOverlay.visibility == View.VISIBLE) {
            return super.dispatchTouchEvent(ev)
        }
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
                    3 -> forEachStage { it.reload() }
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
        mainHandler.removeCallbacksAndMessages(null)
        syncExecutor.shutdownNow()
        forEachStage { it.release() }
    }
}
