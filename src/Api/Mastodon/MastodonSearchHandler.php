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
use AlohaSocial\Core\Service\Mastodon\MastodonService;

use function in_array;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * `GET {mastodon.prefix}/search`
 *
 * The endpoint follows Mastodon v2: `accounts`, `statuses` and `hashtags` in
 * one object. Status and hashtag search use local data. With `resolve=true`,
 * an exact remote account handle is fetched through the guarded WebFinger port.
 */
final class MastodonSearchHandler extends MastodonHandler
{
	/**
	 * @var list<string>
	 */
	private const TYPES = ['accounts', 'statuses', 'hashtags'];

	public function handle(Request $request): Response
	{
		$q = $request->query['q'] ?? null;
		if (!is_string($q) || trim($q) === '') {
			throw HttpException::validationFailed(['q' => 'q is required.']);
		}

		$type = $request->query['type'] ?? null;
		if ($type !== null && !is_string($type)) {
			throw HttpException::validationFailed(['type' => 'type must be one of accounts, statuses or hashtags.']);
		}
		if (is_string($type)) {
			$type = strtolower(trim($type));
			if ($type !== '' && !in_array($type, self::TYPES, true)) {
				throw HttpException::validationFailed([
					'type' => 'type must be one of accounts, statuses or hashtags.',
				]);
			}
		}

		$limit = $this->mastodon->clamp($request->queryInt(
			'limit',
			MastodonService::DEFAULT_LIMIT,
			1,
			MastodonService::MAX_LIMIT,
		));
		$offset = max(0, min(
			MastodonService::MAX_SEARCH_OFFSET,
			$request->queryInt('offset', 0, 0, PHP_INT_MAX),
		));

		return $this->respond(fn (): array => $this->mastodon->search(
			(string) $request->userId,
			$q,
			is_string($type) && $type !== '' ? $type : null,
			$limit,
			$offset,
			$this->queryBool($request, 'following'),
			$this->queryBool($request, 'resolve'),
		));
	}

	private function queryBool(Request $request, string $name): bool
	{
		$value = $request->queryParam($name);
		if ($value === null) {
			return false;
		}

		return is_array($request->query[$name] ?? null)
			? false
			: in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}
}
