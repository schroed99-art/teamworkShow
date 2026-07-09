package com.example.teamworkshow

import android.app.AlertDialog
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Bundle
import android.text.InputType
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import android.widget.EditText
import android.widget.ImageView
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
import com.example.teamworkshow.player.PlayerCallback
import com.example.teamworkshow.player.SlideShowController
import com.example.teamworkshow.playlist.PlaylistManager
import java.io.File

class MainActivity : AppCompatActivity(), PlayerCallback {

    private lateinit var imageViewA: ImageView
    private lateinit var imageViewB: ImageView
    private lateinit var playerView: PlayerView
    private lateinit var emptyView: View

    private var frontImageView: ImageView? = null

    private lateinit var exoPlayer: ExoPlayer
    private lateinit var slideShowController: SlideShowController

    private val tapTimestamps = ArrayDeque<Long>()

    companion object {
        private const val CROSSFADE_MS = 300L
        private const val MAINTENANCE_PIN = "0000"
        private const val TAP_COUNT_REQUIRED = 5
        private const val TAP_WINDOW_MS = 2_000L
        private const val CORNER_DP = 150f
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

        setupExoPlayer()

        val mediaDir = File(getExternalFilesDir(null), "media").also { it.mkdirs() }
        val playlist = PlaylistManager(mediaDir)
        slideShowController = SlideShowController(playlist, this)
        slideShowController.start()
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
        val bitmap = loadScaledBitmap(item.file) ?: return

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
        emptyView.visibility = View.GONE
    }

    override fun showEmpty() {
        imageViewA.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        imageViewB.animate().alpha(0f).setDuration(CROSSFADE_MS).start()
        playerView.animate().alpha(0f).setDuration(CROSSFADE_MS).withEndAction {
            exoPlayer.stop()
        }.start()
        frontImageView = null
        emptyView.visibility = View.VISIBLE
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
            getString(R.string.maintenance_reload),
            getString(R.string.maintenance_exit)
        )
        AlertDialog.Builder(this)
            .setTitle(R.string.maintenance_title)
            .setItems(options) { _, which ->
                when (which) {
                    0 -> slideShowController.reload()
                    1 -> finishAndRemoveTask()
                }
            }
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

    override fun onDestroy() {
        super.onDestroy()
        slideShowController.stop()
        exoPlayer.release()
    }
}
