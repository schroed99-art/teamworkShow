package com.example.teamworkshow.model

import java.io.File

enum class MediaType { IMAGE, VIDEO }

data class MediaItem(
    val file: File,
    val type: MediaType
)
