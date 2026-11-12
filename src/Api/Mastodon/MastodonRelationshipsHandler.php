<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function trim;

/**
 * `GET {mastodon.prefix}/accounts/relationships?id[]=…`
 *
 * Mastodon clients use both `id` and `id[]`; both are accepted. Duplicate ids
 * are removed so a repeated query cannot multiply the result.
 */
final class MastodonRelationshipsHandler extends MastodonHandler
{
	private const MAX_IDS = 100;

	public function handle(Request $request): Response
	{
		$raw = $request->query['id'] ?? null;
		if ($raw === null) {
			throw HttpException::validationFailed(['id' => 'id is required.']);
		}

		$values = is_array($raw) ? $raw : [$raw];
		$ids = [];
		foreach ($values as $value) {
			if (!is_string($value) || trim($value) === '') {
				throw HttpException::validationFailed(['id' => 'id must be a non-empty string.']);
			}

			$id = trim($value);
			if (!in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		if ($ids === [] || count($ids) > self::MAX_IDS) {
			throw HttpException::validationFailed([
				'id' => 'Provide between 1 and ' . self::MAX_IDS . ' account ids.',
			]);
		}

		return $this->respond(fn (): array => $this->mastodon->relationships(
			(string) $request->userId,
			$ids,
		));
	}
}
