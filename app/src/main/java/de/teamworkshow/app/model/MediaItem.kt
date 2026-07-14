package de.teamworkshow.app.model

import java.io.File

enum class MediaType { IMAGE, VIDEO, WEATHER, NEWS }

/** Server-provided ordering/timing for a slide, keyed by media file name. */
data class SlideMeta(val position: Int, val durationMs: Long)

/** A file-less message slide: it carries its own text instead of a media file. */
data class NewsSlide(
    val title: String,
    val body: String,
    val position: Int,
    val durationMs: Long,
)

data class MediaItem(
    val file: File,
    val type: MediaType,
    /** Planned display duration for images; 0 means "use the default". Videos play to their end. */
    val durationMs: Long = 0L,
    /** Server-defined order; items without a position sort last, then by name. */
    val position: Int = Int.MAX_VALUE,
    /** Set only for [MediaType.NEWS] — the message this slide shows. */
    val news: NewsSlide? = null,
)
