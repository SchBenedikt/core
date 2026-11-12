<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\MediaService;

use function strlen;

/**
 * `GET /api/v1/media/{id}[?variant=thumbnail]`
 *
 * The id is unguessable and the route is public so a remote server can fetch
 * an image referenced by a public or unlisted post. Visibility is enforced on
 * the post route, not by requiring a session on the binary itself.
 */
final class ShowMediaHandler implements Handler
{
	public function __construct(
		private readonly ?MediaService $media,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->media === null) {
			throw new HttpException(503, 'media_not_configured', 'Media storage is not configured.');
		}

		$id = (string) $request->attribute('id', '');
		$attachment = $this->media->find($id);
		if ($attachment === null) {
			throw HttpException::notFound('Media not found.');
		}

		$variant = $request->queryParam('variant');
		$thumbnail = match ($variant) {
			null, '', 'original' => false,
			'thumbnail' => true,
			default => throw new HttpException(400, 'invalid_media_variant', 'Unknown media variant.'),
		};

		try {
			$bytes = $this->media->read($attachment, $thumbnail);
		} catch (StorageException) {
			throw HttpException::notFound('Media not found.');
		}

		return new Response(200, [
			'Content-Type' => $attachment->mimeType,
			'Content-Length' => (string) strlen($bytes),
			'Cache-Control' => 'public, max-age=31536000, immutable',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Disposition' => 'inline; filename="' . $attachment->id . '.' . $attachment->extension() . '"',
		], $bytes);
	}
}
