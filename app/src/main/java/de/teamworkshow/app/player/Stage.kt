package de.teamworkshow.app.player

import android.animation.ObjectAnimator
import android.content.Context
import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.util.TypedValue
import android.view.View
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.ProgressBar
import android.widget.TextView
import androidx.core.text.HtmlCompat
import androidx.core.widget.TextViewCompat
import androidx.media3.common.MediaItem as Media3Item
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import de.teamworkshow.app.R
import de.teamworkshow.app.model.MediaItem
import de.teamworkshow.app.model.MediaType
import de.teamworkshow.app.playlist.PlaylistManager
import java.io.File

/**
 * One independent slideshow surface: its own views, ExoPlayer, playlist and timing.
 *
 * The screen shows either one Stage (full screen) or two — a company zone and a
 * customer zone (Phase 5.3). Everything that is per-screen rather than per-zone —
 * the ticker, the pairing/download overlays, the weather *content* — stays in
 * MainActivity; a Stage only knows how to play a list of slides into its own views.
 *
 * [root] is the include's root view: the IDs inside zone_stage.xml repeat once per
 * zone, so every lookup here is scoped to that subtree and never to the Activity.
 */
class Stage(
    context: Context,
    val root: View,
    val playlist: PlaylistManager,
    /** Decodes an image, scaled for this screen. Called on the main thread. */
    private val bitmapLoader: (File) -> Bitmap?,
    /** Decodes the next image off the main thread and hands it back on it. */
    private val preloader: (File, (Bitmap) -> Unit) -> Unit,
    /** Fills this stage's weather layer from the global config + latest forecast. */
    private val weatherPainter: (Stage) -> Unit,
) : PlayerCallback {

    private val imageViewA: ImageView = root.findViewById(R.id.imageViewA)
    private val imageViewB: ImageView = root.findViewById(R.id.imageViewB)
    private val playerView: PlayerView = root.findViewById(R.id.playerView)
    private val emptyView: View = root.findViewById(R.id.emptyView)
    private val slideProgress: ProgressBar = root.findViewById(R.id.slideProgress)
    private val weatherView: View = root.findViewById(R.id.weatherView)
    private val newsView: View = root.findViewById(R.id.newsView)
    private val newsBg: ImageView = root.findViewById(R.id.newsBg)
    private val newsScrim: View = root.findViewById(R.id.newsScrim)
    private val newsTitle: TextView = root.findViewById(R.id.newsTitle)
    private val newsBody: TextView = root.findViewById(R.id.newsBody)

    // Exposed so MainActivity can paint the weather interstitial into this zone.
    val wxBg: ImageView = root.findViewById(R.id.wxBg)
    val wxScrim: View = root.findViewById(R.id.wxScrim)
    val wxLayer: FrameLayout = root.findViewById(R.id.wxLayer)

    private var frontImageView: ImageView? = null
    private var preloaded: Pair<File, Bitmap>? = null
    private var slideAnimator: ObjectAnimator? = null

    private val exoPlayer: ExoPlayer = ExoPlayer.Builder(context).build().apply {
        volume = 0f
        repeatMode = Player.REPEAT_MODE_OFF
    }

    private val controller = SlideShowController(playlist, this)

    init {
        playerView.player = exoPlayer
        playerView.useController = false
        exoPlayer.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(playbackState: Int) {
                if (playbackState == Player.STATE_ENDED) controller.onVideoDone()
            }
        })
    }

    fun start() = controller.start()
    fun reload() = controller.reload()

    fun release() {
        controller.stop()
        slideAnimator?.cancel()
        exoPlayer.release()
    }

    // ---------- PlayerCallback ----------

    /** Fades out both file-less overlays; each show* call re-raises the one it needs. */
    private fun hideOverlays() {
        weatherView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        newsView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
    }

    override fun showImage(item: MediaItem) {
        val bitmap = preloaded?.takeIf { it.first == item.file }?.second
            ?: bitmapLoader(item.file) ?: return
        preloaded = null

        val backView = if (frontImageView == imageViewA) imageViewB else imageViewA
        backView.setImageBitmap(bitmap)

        if (playerView.alpha > 0f) {
            playerView.animate().alpha(0f).setDuration(CROSSFADE_MS)
                .withEndAction { exoPlayer.stop() }.start()
        }

        backView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        frontImageView?.animate()?.alpha(0f)?.setDuration(CROSSFADE_MS)?.start()
        frontImageView = backView

        hideOverlays()
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
        hideOverlays()
        emptyView.visibility = View.GONE
    }

    override fun showWeather(item: MediaItem) {
        weatherPainter(this)

        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        frontImageView = null
        if (playerView.alpha > 0f) {
            playerView.animate().alpha(0f).setDuration(CROSSFADE_MS)
                .withEndAction { exoPlayer.stop() }.start()
        }

        newsView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        weatherView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        emptyView.visibility = View.GONE
    }

    /** File-less message slide: the text travels on the item itself. */
    override fun showNews(item: MediaItem) {
        val news = item.news ?: return
        // Title + body may carry simple HTML formatting (<b>, <i>, <br>, <font>…),
        // authored in the dashboard — render it rather than showing the raw tags.
        newsTitle.text = HtmlCompat.fromHtml(news.title, HtmlCompat.FROM_HTML_MODE_COMPACT)
        newsTitle.visibility = if (news.title.isBlank()) View.GONE else View.VISIBLE
        newsBody.text = HtmlCompat.fromHtml(news.body, HtmlCompat.FROM_HTML_MODE_COMPACT)
        newsBody.visibility = if (news.body.isBlank()) View.GONE else View.VISIBLE

        // Colour + font + size — the same knobs the Laufschrift offers. Size 0 keeps
        // the auto-sizing; a fixed size overrides it (title scales 1.5× the body).
        val titleColor = parseNewsColor(news.color, 0xFFFFFFFF.toInt())
        val bodyColor = parseNewsColor(news.color, 0xFFCBD5E1.toInt())
        applyNewsText(newsTitle, titleColor, newsTypeface(news.font, bold = true),
            if (news.size > 0) news.size * 1.5f else 0f, 14, 44)
        applyNewsText(newsBody, bodyColor, newsTypeface(news.font, bold = false),
            if (news.size > 0) news.size.toFloat() else 0f, 11, 26)

        // Optional background: item.file is the downloaded image (or a non-existent
        // placeholder). Decode it via the shared loader and dim it with the scrim.
        val bmp = if (news.bg.isNotBlank() && item.file.isFile) bitmapLoader(item.file) else null
        if (bmp != null) {
            newsBg.setImageBitmap(bmp)
            newsBg.visibility = View.VISIBLE
            newsScrim.visibility = View.VISIBLE
        } else {
            newsBg.setImageDrawable(null)
            newsBg.visibility = View.GONE
            newsScrim.visibility = View.GONE
        }

        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        frontImageView = null
        if (playerView.alpha > 0f) {
            playerView.animate().alpha(0f).setDuration(CROSSFADE_MS)
                .withEndAction { exoPlayer.stop() }.start()
        }

        weatherView.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        newsView.animate().alpha(1f).setDuration(CROSSFADE_MS).start()
        emptyView.visibility = View.GONE
    }

    /** Colour, typeface and (optional) fixed size for one news text view. */
    private fun applyNewsText(tv: TextView, color: Int, tf: Typeface, sizeSp: Float, autoMin: Int, autoMax: Int) {
        tv.setTextColor(color)
        tv.typeface = tf
        if (sizeSp > 0f) {
            TextViewCompat.setAutoSizeTextTypeWithDefaults(tv, TextViewCompat.AUTO_SIZE_TEXT_TYPE_NONE)
            tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, sizeSp)
        } else {
            TextViewCompat.setAutoSizeTextTypeUniformWithConfiguration(
                tv, autoMin, autoMax, 1, TypedValue.COMPLEX_UNIT_SP
            )
        }
    }

    /** Maps a Laufschrift-style font key to a system typeface. */
    private fun newsTypeface(font: String, bold: Boolean): Typeface {
        val family = when (font) {
            "serif" -> "serif"
            "monospace" -> "monospace"
            "sans-serif-condensed" -> "sans-serif-condensed"
            "sans-serif-light" -> "sans-serif-light"
            "sans-serif-medium" -> "sans-serif-medium"
            else -> "sans-serif"
        }
        return Typeface.create(family, if (bold) Typeface.BOLD else Typeface.NORMAL)
    }

    private fun parseNewsColor(hex: String, fallback: Int): Int =
        try {
            if (hex.isNotBlank()) Color.parseColor(hex) else fallback
        } catch (e: IllegalArgumentException) {
            fallback
        }

    override fun showEmpty() {
        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        playerView.animate().alpha(0f).setDuration(CROSSFADE_MS)
            .withEndAction { exoPlayer.stop() }.start()
        hideOverlays()
        frontImageView = null
        emptyView.visibility = View.VISIBLE
    }

    override fun onSlideStarted(durationMs: Long, next: MediaItem?) {
        // Discreet progress line for timed slides (a video's length is unknown here).
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
        if (next != null && next.type == MediaType.IMAGE) {
            val file = next.file
            preloader(file) { bmp -> preloaded = file to bmp }
        }
    }

    companion object {
        private const val CROSSFADE_MS = 300L
    }
}
