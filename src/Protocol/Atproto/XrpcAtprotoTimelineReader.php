<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use JsonException;

use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function rawurlencode;
use function rtrim;

/**
 * Reads `app.bsky.feed.getTimeline` from an AppView.
 *
 * The account's PDS is the default AppView (it proxies the read), so the same
 * bearer/DPoP transport applies. A failed request is an exception rather than
 * an empty page: an empty page would tell the host "no posts" when the truth
 * is "we could not ask".
 */
final class XrpcAtprotoTimelineReader implements AtprotoTimelineReader
{
	public const ENDPOINT = '/xrpc/app.bsky.feed.getTimeline';

	public const MAX_LIMIT = 100;

	private readonly AtprotoPdsRequest $requests;

	public function __construct(
		HttpClient $http,
		?DpopResourceProofProvider $dpop = null,
	) {
		$this->requests = new AtprotoPdsRequest($http, $dpop);
	}

	public function getTimeline(
		AtprotoAccount $account,
		int $limit = AtprotoTimelineReader::DEFAULT_LIMIT,
		?string $cursor = null,
	): AtprotoTimelinePage {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$url = rtrim($account->pdsUrl, '/') . self::ENDPOINT . '?limit=' . $limit;
		if ($cursor !== null && $cursor !== '') {
			$url .= '&cursor=' . rawurlencode($cursor);
		}

		try {
			$response = $this->requests->send('GET', $url, $account, '', [
				'Accept' => 'application/json',
			]);
		} catch (HttpClientException) {
			throw new AtprotoTimelineException(
				AtprotoTimelineException::UNAVAILABLE,
				'The AppView could not be reached.',
			);
		}

		if (!$response->isSuccessful()) {
			throw new AtprotoTimelineException(
				AtprotoTimelineException::UNAVAILABLE,
				'The AppView refused the timeline request.',
			);
		}

		return $this->page($response);
	}

	private function page(HttpResponse $response): AtprotoTimelinePage
	{
		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new AtprotoTimelineException(
				AtprotoTimelineException::INVALID_RESPONSE,
				'The AppView returned no readable timeline.',
			);
		}

		$feed = is_array($decoded) ? ($decoded['feed'] ?? null) : null;
		if (!is_array($feed)) {
			throw new AtprotoTimelineException(
				AtprotoTimelineException::INVALID_RESPONSE,
				'The AppView returned no timeline feed.',
			);
		}

		$items = [];
		foreach ($feed as $entry) {
			$post = is_array($entry) ? ($entry['post'] ?? null) : null;
			if (!is_array($post)) {
				continue;
			}

			$item = AtprotoTimelineItem::fromView($post);
			if ($item !== null) {
				$items[] = $item;
			}
		}

		$cursor = $decoded['cursor'] ?? null;

		return new AtprotoTimelinePage($items, is_string($cursor) && $cursor !== '' ? $cursor : null);
	}
}
