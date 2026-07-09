package com.example.teamworkshow.player

import com.example.teamworkshow.model.MediaItem

interface PlayerCallback {
    fun showImage(item: MediaItem)
    fun showVideo(item: MediaItem)
    fun showEmpty()
}
