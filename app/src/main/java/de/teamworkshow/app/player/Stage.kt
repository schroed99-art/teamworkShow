package de.teamworkshow.app.player

import android.animation.ObjectAnimator
import android.content.Context
import android.graphics.Bitmap
import android.net.Uri
import android.view.View
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.ProgressBar
import android.widget.TextView
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
        newsTitle.text = news.title
        newsTitle.visibility = if (news.title.isBlank()) View.GONE else View.VISIBLE
        newsBody.text = news.body
        newsBody.visibility = if (news.body.isBlank()) View.GONE else View.VISIBLE

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
