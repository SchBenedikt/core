<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Removes location and free-form metadata from an image before it is stored.
 *
 * The bytes still decode to the same image; only metadata blocks (EXIF/XMP,
 * comments and PNG text chunks) are dropped. A host never has to remember to
 * strip them, and a re-upload of an already stripped file is unchanged.
 * SVG and HTML are not handled because they are never accepted as images.
 */
final class MediaMetadataStripper
{
	public function strip(string $bytes, string $mimeType): string
	{
		return match ($mimeType) {
			'image/jpeg' => $this->jpeg($bytes),
			'image/png' => $this->png($bytes),
			'image/webp' => $this->webp($bytes),
			default => $bytes,
		};
	}

	private function jpeg(string $bytes): string
	{
		$length = strlen($bytes);
		if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
			return $bytes;
		}

		$out = "\xFF\xD8";
		$offset = 2;
		while ($offset + 1 < $length) {
			if (ord($bytes[$offset]) !== 0xFF) {
				break;
			}

			$marker = ord($bytes[$offset + 1]);
			if ($marker === 0xFF) {
				$offset++;
				continue;
			}

			if ($marker === 0xDA) {
				$out .= substr($bytes, $offset);

				return $out;
			}

			// Standalone markers have no length field.
			if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
				$out .= substr($bytes, $offset, 2);
				$offset += 2;
				continue;
			}

			if ($offset + 4 > $length) {
				break;
			}

			$size = unpack('n', substr($bytes, $offset + 2, 2))[1] ?? 0;
			if ($size < 2 || $offset + 2 + $size > $length) {
				break;
			}

			// APP1 carries EXIF and XMP; COM is a free-form comment. APP2 ICC
			// profiles are colour data, not identifying metadata, so they stay.
			if ($marker !== 0xE1 && $marker !== 0xFE) {
				$out .= substr($bytes, $offset, 2 + $size);
			}

			$offset += 2 + $size;
		}

		return $out . substr($bytes, $offset);
	}

	private function png(string $bytes): string
	{
		$signature = "\x89PNG\r\n\x1A\n";
		if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== $signature) {
			return $bytes;
		}

		$out = $signature;
		$offset = 8;
		$length = strlen($bytes);
		while ($offset + 12 <= $length) {
			$size = unpack('N', substr($bytes, $offset, 4))[1] ?? 0;
			$type = substr($bytes, $offset + 4, 4);
			$end = $offset + 12 + $size;
			if ($end > $length) {
				return $bytes;
			}

			if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf'], true)) {
				$out .= substr($bytes, $offset, $end - $offset);
			}

			$offset = $end;
			if ($type === 'IEND') {
				break;
			}
		}

		return $out;
	}

	private function webp(string $bytes): string
	{
		$length = strlen($bytes);
		if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
			return $bytes;
		}

		$body = '';
		$offset = 12;
		while ($offset + 8 <= $length) {
			$type = substr($bytes, $offset, 4);
			$size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;
			$end = $offset + 8 + $size;
			if ($end > $length) {
				return $bytes;
			}

			if ($type !== 'EXIF' && $type !== 'XMP ') {
				$body .= substr($bytes, $offset, 8 + $size + ($size % 2));
			}

			$offset = $end + ($size % 2);
		}

		return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
	}
}
