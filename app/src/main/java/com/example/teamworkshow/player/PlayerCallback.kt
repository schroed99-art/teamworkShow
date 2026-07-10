package com.example.teamworkshow.player

import com.example.teamworkshow.model.MediaItem

interface PlayerCallback {
    fun showImage(item: MediaItem)
    fun showVideo(item: MediaItem)
    fun showEmpty()

    /**
     * Called when a slide starts.
     * @param durationMs planned duration for images, or 0 for videos (unknown until playback).
     * @param next the item that will play next, for preloading.
     */
    fun onSlideStarted(durationMs: Long, next: MediaItem?)
}
