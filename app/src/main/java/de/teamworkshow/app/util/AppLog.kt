package de.teamworkshow.app.util

import android.content.Context
import android.util.Log
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Lightweight file logger for the signage app. Mirrors [android.util.Log] to
 * logcat and appends timestamped lines to `<filesDir>/logs/app.log` with simple
 * size-based rotation (one `app.1.log` backup). The maintenance menu can export
 * the collected logs to a technician-chosen storage path via [exportTo].
 *
 * Safe before [init]: calls are no-ops on disk until a context is supplied.
 */
object AppLog {
    private const val TAG = "AppLog"
    private const val MAX_BYTES = 512 * 1024L
    private val fmt = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.GERMANY)

    @Volatile private var dir: File? = null

    fun init(context: Context) {
        dir = File(context.filesDir, "logs").apply { mkdirs() }
    }

    @Synchronized
    private fun append(level: String, tag: String, msg: String) {
        val d = dir ?: return
        try {
            val f = File(d, "app.log")
            if (f.exists() && f.length() > MAX_BYTES) {
                val bak = File(d, "app.1.log")
                bak.delete()
                f.renameTo(bak)
            }
            f.appendText("${fmt.format(Date())} $level/$tag: $msg\n")
        } catch (e: Exception) {
            Log.w(TAG, "log append failed: ${e.message}")
        }
    }

    fun d(tag: String, msg: String) { Log.d(tag, msg); append("D", tag, msg) }
    fun i(tag: String, msg: String) { Log.i(tag, msg); append("I", tag, msg) }
    fun w(tag: String, msg: String) { Log.w(tag, msg); append("W", tag, msg) }
    fun e(tag: String, msg: String) { Log.e(tag, msg); append("E", tag, msg) }

    /** Concatenates the rotated backup + current log into [dest]. Returns dest on success. */
    @Synchronized
    fun exportTo(dest: File): File? = try {
        val d = dir
        if (d == null) {
            null
        } else {
            dest.parentFile?.mkdirs()
            dest.outputStream().use { out ->
                listOf(File(d, "app.1.log"), File(d, "app.log")).forEach { part ->
                    if (part.exists()) part.inputStream().use { it.copyTo(out) }
                }
            }
            dest
        }
    } catch (e: Exception) {
        Log.w(TAG, "log export failed: ${e.message}")
        null
    }
}
