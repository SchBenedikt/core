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
use AlohaSocial\Core\Service\MediaAccess;
use AlohaSocial\Core\Service\MediaService;

use function strlen;

/**
 * `GET /api/v1/media/{id}[?variant=thumbnail]`
 *
 * The id is unguessable, but that is not authorization: the lookup asks
 * {@see MediaAccess} whether the requester may read the media of the post(s)
 * that link it. Public and unlisted media stay anonymously fetchable so a peer
 * can render a published note; followers-only and direct media need the same
 * relationship the post route requires. A refusal is the same `404` as an
 * unknown id, so the answer does not confirm that the attachment exists, and
 * a restricted read is never handed to a shared cache.
 */
final class ShowMediaHandler implements Handler
{
	public function __construct(
		private readonly ?MediaService $media,
		private readonly ?MediaAccess $access = null,
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

		// Without a policy the route cannot tell a published attachment from
		// a private one, so it refuses instead of serving bytes unchecked.
		$decision = $this->access?->evaluate($attachment, $request->userId);
		if ($decision === null || !$decision->allowed) {
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
			'Cache-Control' => $decision->publiclyCacheable
				? 'public, max-age=31536000, immutable'
				: 'private, no-store',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Disposition' => 'inline; filename="' . $attachment->id . '.' . $attachment->extension() . '"',
		], $bytes);
	}
}
