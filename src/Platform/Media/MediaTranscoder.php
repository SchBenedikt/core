<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Media;

/**
 * Optional video transcoding, implemented by a host with ffmpeg.
 *
 * The core never shells out. When no transcoder is configured, an accepted
 * video is stored as uploaded: a playable MP4/WebM needs no rendition, and
 * refusing the upload merely because ffmpeg is absent would make the whole
 * media feature depend on a host tool. A transcoder that throws or returns
 * null makes the upload fail cleanly instead of publishing a broken rendition.
 */
interface MediaTranscoder
{
	public function transcode(string $bytes, string $mimeType): ?TranscodedMedia;
}
