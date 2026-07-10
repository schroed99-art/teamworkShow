package de.teamworkshow.app.player

import android.os.Handler
import android.os.Looper
import de.teamworkshow.app.model.MediaType
import de.teamworkshow.app.playlist.PlaylistManager

class SlideShowController(
    private val playlist: PlaylistManager,
    private val callback: PlayerCallback
) {
    private val handler = Handler(Looper.getMainLooper())
    private var running = false

    companion object {
        const val IMAGE_DURATION_MS = 10_000L
    }

    fun start() {
        running = true
        playlist.reload()
        playNext()
    }

    fun reload() {
        handler.removeCallbacksAndMessages(null)
        playlist.reload()
        if (running) playNext()
    }

    fun stop() {
        running = false
        handler.removeCallbacksAndMessages(null)
    }

    /** Called by MainActivity when ExoPlayer reports STATE_ENDED. */
    fun onVideoDone() {
        if (!running) return
        playlist.advance()
        playNext()
    }

    /** Called by the image timer. */
    private fun onImageDone() {
        if (!running) return
        playlist.advance()
        playNext()
    }

    private fun playNext() {
        if (!running) return
        if (playlist.isEmpty()) {
            callback.showEmpty()
            return
        }
        val item = playlist.current() ?: return
        val next = playlist.peekNext()
        when (item.type) {
            MediaType.IMAGE -> {
                // Honor the server-defined duration; fall back to the default when unset.
                val duration = if (item.durationMs > 0) item.durationMs else IMAGE_DURATION_MS
                callback.showImage(item)
                callback.onSlideStarted(duration, next)
                handler.postDelayed(::onImageDone, duration)
            }
            MediaType.VIDEO -> {
                callback.showVideo(item)
                callback.onSlideStarted(0L, next)
                // advance is triggered via onVideoDone() from the ExoPlayer listener
            }
            MediaType.WEATHER -> {
                // File-less interstitial: timed exactly like an image slide.
                val duration = if (item.durationMs > 0) item.durationMs else IMAGE_DURATION_MS
                callback.showWeather(item)
                callback.onSlideStarted(duration, next)
                handler.postDelayed(::onImageDone, duration)
            }
        }
    }
}
