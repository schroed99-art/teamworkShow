package com.example.teamworkshow.playlist

import com.example.teamworkshow.model.MediaItem
import com.example.teamworkshow.model.MediaType
import java.io.File

class PlaylistManager(private val mediaDir: File) {

    private var items: List<MediaItem> = emptyList()
    private var index = 0

    fun reload() {
        items = if (mediaDir.exists() && mediaDir.isDirectory) {
            mediaDir.listFiles()
                ?.filter { it.isFile && it.extension.lowercase() in SUPPORTED_EXTENSIONS }
                ?.sortedBy { it.name.lowercase() }
                ?.map { file ->
                    val type = if (file.extension.lowercase() == "mp4") MediaType.VIDEO else MediaType.IMAGE
                    MediaItem(file, type)
                }
                ?: emptyList()
        } else {
            emptyList()
        }
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
