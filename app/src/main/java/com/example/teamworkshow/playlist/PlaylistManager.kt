package com.example.teamworkshow.playlist

import com.example.teamworkshow.model.MediaItem
import com.example.teamworkshow.model.MediaType
import com.example.teamworkshow.model.SlideMeta
import java.io.File

class PlaylistManager(private val mediaDir: File) {

    private var items: List<MediaItem> = emptyList()
    private var index = 0

    /**
     * Supplies server-provided ordering/timing (name -> [SlideMeta]) for the current media.
     * Defaults to empty, i.e. folder-scan behaviour (name sort, default durations).
     */
    var metaProvider: () -> Map<String, SlideMeta> = { emptyMap() }

    /**
     * Supplies file-less weather interstitial slides (kind='weather') with their
     * server-defined position/duration. Merged into the playlist and ordered by position.
     */
    var weatherProvider: () -> List<MediaItem> = { emptyList() }

    fun reload() {
        val meta = metaProvider()
        val fileItems = if (mediaDir.exists() && mediaDir.isDirectory) {
            mediaDir.listFiles()
                ?.filter { it.isFile && it.extension.lowercase() in SUPPORTED_EXTENSIONS }
                ?.map { file ->
                    val type = if (file.extension.lowercase() == "mp4") MediaType.VIDEO else MediaType.IMAGE
                    val m = meta[file.name]
                    MediaItem(
                        file = file,
                        type = type,
                        durationMs = m?.durationMs ?: 0L,
                        position = m?.position ?: Int.MAX_VALUE
                    )
                }
                ?: emptyList()
        } else {
            emptyList()
        }
        items = (fileItems + weatherProvider())
            .sortedWith(compareBy({ it.position }, { it.file.name.lowercase() }))
        index = 0
    }

    fun isEmpty(): Boolean = items.isEmpty()

    fun current(): MediaItem? = items.getOrNull(index)

    /** The item that will play next, without advancing (used for preloading). */
    fun peekNext(): MediaItem? {
        if (items.isEmpty()) return null
        return items[(index + 1) % items.size]
    }

    fun advance(): MediaItem? {
        if (items.isEmpty()) return null
        index = (index + 1) % items.size
        return items[index]
    }

    companion object {
        private val SUPPORTED_EXTENSIONS = setOf("jpg", "jpeg", "png", "webp", "mp4")
    }
}
