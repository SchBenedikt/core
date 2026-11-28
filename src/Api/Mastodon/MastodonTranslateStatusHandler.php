<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;

use function is_string;
use function trim;

/**
 * `POST {mastodon.prefix}/statuses/{id}/translate`
 *
 * Glitch-soc clients send `target_lang`; a missing or empty value is a
 * validation error rather than a silent default.
 */
final class MastodonTranslateStatusHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$body = $request->payload();
			$target = is_string($body['target_lang'] ?? null) ? trim($body['target_lang']) : '';
			if ($target === '') {
				throw new MastodonRequestException(422, 'validation_error', 'target_lang is required.');
			}

			return $this->mastodon->translateStatus(
				(string) $request->attribute('id', ''),
				$target,
				$request->userId,
			);
		});
	}
}
