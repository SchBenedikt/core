<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Platform\Media\MediaProbe;
use AlohaSocial\Core\Platform\Media\ProbedMedia;
use finfo;

use function getimagesizefromstring;
use function is_array;
use function ord;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

use const FILEINFO_MIME_TYPE;

/**
 * A dependency-free probe for the formats the core accepts.
 *
 * MIME detection comes from libmagic, so a PHP script renamed to `.png` is
 * never treated as an image. Dimensions and durations are read from the
 * container; when a container has no cheap parser (WebM, Ogg), the upload is
 * still accepted with unknown dimensions instead of inventing values.
 */
final class NativeMediaProbe implements MediaProbe
{
	private const MAX_DURATION_SECONDS = 14_400;

	public function probe(string $bytes): ?ProbedMedia
	{
		if ($bytes === '') {
			return null;
		}

		$mimeType = $this->mimeType($bytes);
		if ($mimeType === null) {
			return null;
		}

		$kind = MediaKind::fromMimeType($mimeType);
		if ($kind === null) {
			return null;
		}

		if ($kind === MediaKind::Image) {
			$info = @getimagesizefromstring($bytes);
			if (!is_array($info) || !isset($info[0], $info[1])) {
				return null;
			}

			return new ProbedMedia(
				mimeType: $mimeType,
				kind: $kind,
				width: (int) $info[0],
				height: (int) $info[1],
				animated: $mimeType === 'image/gif' && $this->gifAnimated($bytes),
			);
		}

		$duration = match (true) {
			$mimeType === 'audio/mpeg' => $this->mp3Duration($bytes),
			$mimeType === 'audio/wav' => $this->wavDuration($bytes),
			$mimeType === 'video/mp4', $mimeType === 'audio/mp4' => $this->mp4Duration($bytes),
			default => 0,
		};

		$width = 0;
		$height = 0;
		if ($kind === MediaKind::Video && ($mimeType === 'video/mp4')) {
			[$width, $height] = $this->mp4Dimensions($bytes);
		}

		return new ProbedMedia(
			mimeType: $mimeType,
			kind: $kind,
			width: $width,
			height: $height,
			durationSeconds: max(0, min(self::MAX_DURATION_SECONDS, $duration)),
		);
	}

	/**
	 * A GIF is animated only when it has more than one image descriptor. A
	 * still GIF has to stay an `image`, not become `gifv`, because clients
	 * choose autoplay from that distinction.
	 */
	private function gifAnimated(string $bytes): bool
	{
		$length = strlen($bytes);
		if ($length < 13 || substr($bytes, 0, 6) !== 'GIF87a' && substr($bytes, 0, 6) !== 'GIF89a') {
			return false;
		}

		$packed = ord($bytes[10]);
		$offset = 13;
		if (($packed & 0x80) !== 0) {
			$offset += 3 * (2 ** (($packed & 0x07) + 1));
		}

		$frames = 0;
		while ($offset < $length) {
			$block = ord($bytes[$offset]);

			if ($block === 0x3B) {
				return false;
			}

			if ($block === 0x21) {
				$offset += 2;
				$offset = $this->skipSubBlocks($bytes, $offset);
				if ($offset < 0) {
					return false;
				}

				continue;
			}

			if ($block !== 0x2C || $offset + 10 > $length) {
				return false;
			}

			++$frames;
			if ($frames > 1) {
				return true;
			}

			$flags = ord($bytes[$offset + 9]);
			$offset += 10;
			if (($flags & 0x80) !== 0) {
				$offset += 3 * (2 ** (($flags & 0x07) + 1));
			}

			// LZW minimum code size, then the image sub-blocks.
			$offset = $this->skipSubBlocks($bytes, $offset + 1);
			if ($offset < 0) {
				return false;
			}
		}

		return false;
	}

	/**
	 * @return int the offset after the block terminator, or -1 when truncated
	 */
	private function skipSubBlocks(string $bytes, int $offset): int
	{
		$length = strlen($bytes);
		while ($offset < $length) {
			$size = ord($bytes[$offset]);
			++$offset;
			if ($size === 0) {
				return $offset;
			}

			$offset += $size;
		}

		return -1;
	}

	private function mimeType(string $bytes): ?string
	{
		$detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		if (!is_string($detected)) {
			return null;
		}

		$detected = strtolower(trim($detected));
		$detected = match ($detected) {
			'image/jpg', 'image/pjpeg' => 'image/jpeg',
			'audio/mp3' => 'audio/mpeg',
			'audio/x-wav', 'audio/wave' => 'audio/wav',
			'application/ogg' => 'audio/ogg',
			default => $detected,
		};

		if ($detected === 'application/mp4') {
			return 'audio/mp4';
		}

		return $detected;
	}

	/**
	 * The duration of an ISO BMFF file (MP4/M4A) in seconds.
	 */
	private function mp4Duration(string $bytes): int
	{
		$moov = $this->boxPayload($bytes, 0, strlen($bytes), 'moov');
		if ($moov === null) {
			return 0;
		}

		$mvhd = $this->boxPayload($bytes, $moov[0], $moov[1], 'mvhd');
		if ($mvhd === null) {
			return 0;
		}

		[$start] = $mvhd;
		$version = ord($bytes[$start]);
		$timescaleOffset = $version === 1 ? 20 : 12;
		$durationOffset = $version === 1 ? 24 : 16;
		$durationBytes = $version === 1 ? 8 : 4;

		if ($start + $durationOffset + $durationBytes > strlen($bytes)) {
			return 0;
		}

		$timescale = unpack('N', substr($bytes, $start + $timescaleOffset, 4))[1] ?? 0;
		if ($timescale < 1) {
			return 0;
		}

		$duration = $version === 1
			? $this->uint64(substr($bytes, $start + $durationOffset, 8))
			: (unpack('N', substr($bytes, $start + $durationOffset, 4))[1] ?? 0);

		return (int) floor($duration / $timescale);
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function mp4Dimensions(string $bytes): array
	{
		$moov = $this->boxPayload($bytes, 0, strlen($bytes), 'moov');
		if ($moov === null) {
			return [0, 0];
		}

		$width = 0;
		$height = 0;
		$offset = $moov[0];
		while (($trak = $this->nextBox($bytes, $offset, $moov[1], 'trak')) !== null) {
			$offset = $trak[2];
			$tkhd = $this->boxPayload($bytes, $trak[0], $trak[1], 'tkhd');
			if ($tkhd === null) {
				continue;
			}

			$version = ord($bytes[$tkhd[0]]);
			$widthOffset = $version === 1 ? 92 : 76;
			if ($tkhd[0] + $widthOffset + 8 > strlen($bytes)) {
				continue;
			}

			$width = max($width, (int) (unpack('N', substr($bytes, $tkhd[0] + $widthOffset, 4))[1] >> 16));
			$height = max($height, (int) (unpack('N', substr($bytes, $tkhd[0] + $widthOffset + 4, 4))[1] >> 16));
		}

		return [$width, $height];
	}

	/**
	 * Scans the direct children of a range for one box type.
	 *
	 * @return array{0: int, 1: int}|null payload start and end
	 */
	private function boxPayload(string $bytes, int $start, int $end, string $type): ?array
	{
		$offset = $start;
		while (($box = $this->nextBox($bytes, $offset, $end, $type)) !== null) {
			return [$box[0], $box[1]];
		}

		return null;
	}

	/**
	 * @return array{0: int, 1: int, 2: int}|null payload start, payload end, next offset
	 */
	private function nextBox(string $bytes, int $offset, int $end, string $type): ?array
	{
		while ($offset + 8 <= $end) {
			$size = unpack('N', substr($bytes, $offset, 4))[1] ?? 0;
			$boxType = substr($bytes, $offset + 4, 4);
			$header = 8;

			if ($size === 1 && $offset + 16 <= $end) {
				$size = $this->uint64(substr($bytes, $offset + 8, 8));
				$header = 16;
			}

			if ($size < $header || $offset + $size > $end) {
				return null;
			}

			if ($boxType === $type) {
				return [$offset + $header, $offset + $size, $offset + $size];
			}

			$offset += $size;
		}

		return null;
	}

	private function uint64(string $bytes): int
	{
		$parts = unpack('N2', $bytes);
		if (!is_array($parts)) {
			return 0;
		}

		return (($parts[1] ?? 0) << 32) + ($parts[2] ?? 0);
	}

	private function mp3Duration(string $bytes): int
	{
		$length = strlen($bytes);
		$offset = 0;

		if (str_starts_with($bytes, 'ID3') && $length >= 10) {
			$size = ((ord($bytes[6]) & 0x7F) << 21)
				| ((ord($bytes[7]) & 0x7F) << 14)
				| ((ord($bytes[8]) & 0x7F) << 7)
				| (ord($bytes[9]) & 0x7F);
			$offset = 10 + $size;
		}

		$limit = min($length - 4, $offset + 4096);
		for ($i = $offset; $i < $limit; $i++) {
			if (ord($bytes[$i]) !== 0xFF || (ord($bytes[$i + 1]) & 0xE0) !== 0xE0) {
				continue;
			}

			$header = unpack('N', substr($bytes, $i, 4))[1] ?? 0;
			$version = ($header >> 19) & 0x3;
			$layer = ($header >> 17) & 0x3;
			$bitrateIndex = ($header >> 12) & 0xF;
			if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15) {
				continue;
			}

			// MPEG-1 Layer III, the overwhelmingly common case.
			$bitrates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
			$bitrate = ($bitrates[$bitrateIndex] ?? 0) * 1000;
			if ($bitrate < 1) {
				return 0;
			}

			return (int) floor((($length - $offset) * 8) / $bitrate);
		}

		return 0;
	}

	private function wavDuration(string $bytes): int
	{
		if (!str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WAVE') {
			return 0;
		}

		$offset = 12;
		$byteRate = 0;
		$dataSize = 0;
		$length = strlen($bytes);
		while ($offset + 8 <= $length) {
			$chunk = substr($bytes, $offset, 4);
			$size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;
			if ($chunk === 'fmt ' && $offset + 20 <= $length) {
				$byteRate = unpack('V', substr($bytes, $offset + 16, 4))[1] ?? 0;
			} elseif ($chunk === 'data') {
				$dataSize = $size;
				break;
			}

			$offset += 8 + $size + ($size % 2);
		}

		return $byteRate > 0 ? (int) floor($dataSize / $byteRate) : 0;
	}
}
