<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Search\InvalidSearchCursorException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Search\SearchService;
use InvalidArgumentException;

use function in_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * `GET /api/v1/search?q=&type=&limit=&cursor=`
 *
 * The neutral search shape groups indexed accounts and posts, adds hashtag
 * trends, and reports whether a remote part of the lookup was partial. An
 * optional token applies the viewer's blocks and mutes; the route remains
 * usable anonymously for public discovery.
 */
final class SearchHandler implements Handler
{
	private const TYPES = ['accounts', 'statuses', 'hashtags'];

	public function __construct(
		private readonly SearchService $search,
	) {
	}

	public function handle(Request $request): Response
	{
		$q = $request->queryParam('q');
		if (!is_string($q) || trim($q) === '') {
			throw HttpException::validationFailed(['q' => 'q is required.']);
		}

		$type = $request->queryParam('type');
		if ($type !== null) {
			$type = strtolower(trim($type));
			if ($type !== '' && !in_array($type, self::TYPES, true)) {
				throw HttpException::validationFailed([
					'type' => 'type must be one of accounts, statuses or hashtags.',
				]);
			}
			if ($type === '') {
				$type = null;
			}
		}

		$cursor = $request->queryParam('cursor');

		try {
			return Response::json($this->search->search(
				$request->userId,
				$q,
				$type,
				$request->queryInt('limit', SearchService::DEFAULT_LIMIT, 1, SearchService::MAX_LIMIT),
				is_string($cursor) ? $cursor : null,
			));
		} catch (InvalidSearchCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		} catch (InvalidArgumentException $exception) {
			throw HttpException::validationFailed(['q' => $exception->getMessage()]);
		}
	}
}
