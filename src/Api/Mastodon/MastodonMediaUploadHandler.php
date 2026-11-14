<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Api\MediaUploadInput;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

use function trim;

/**
 * `POST {mastodon.prefix}/media`
 *
 * Accepts the same `file` field as the core upload route. Mastodon clients call
 * the optional description `description`; `alt_text` is accepted as a fallback
 * for clients that already used the core name. `focus` is ignored because the
 * core has no crop metadata.
 */
final class MastodonMediaUploadHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$input = MediaUploadInput::fromRequest($request, ['description', 'alt_text']);
		$idempotencyKey = trim((string) $request->header('idempotency-key'));

		return $this->respond(fn (): array => $this->mastodon->uploadMedia(
			$userId,
			$input->bytes,
			$input->altText,
			$idempotencyKey === '' ? null : $idempotencyKey,
			$input->declaredMimeType,
		));
	}
}
