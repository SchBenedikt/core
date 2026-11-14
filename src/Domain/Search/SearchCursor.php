<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;

use function base64_decode;
use function base64_encode;
use function hash;
use function hash_equals;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function time;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Opaque keyset cursor for one search query.
 *
 * It stores the query hash so a cursor cannot be replayed against another
 * query, and a creation time so an old cursor is rejected instead of showing
 * a silently different page. Ordering is `(published_at, id)` descending.
 */
final readonly class SearchCursor
{
	public const MAX_AGE_SECONDS = 3600;

	public function __construct(
		public string $queryHash,
		public DateTimeImmutable $publishedAt,
		public string $id,
		public int $createdAt,
		public int $rank = 0,
	) {
		if ($queryHash === '' || $id === '') {
			throw new InvalidSearchCursorException('The search cursor is incomplete.');
		}
	}

	public static function after(SearchRecord $record, string $query, ?int $now = null): self
	{
		return new self(
			queryHash: self::hashQuery($query),
			publishedAt: $record->publishedAt,
			id: $record->id,
			createdAt: $now ?? time(),
			rank: $record->rank($query),
		);
	}

	/**
	 * @throws InvalidSearchCursorException when the token is malformed, expired or for another query
	 */
	public static function decode(?string $token, string $query, ?int $now = null): ?self
	{
		$token = $token === null ? '' : trim($token);
		if ($token === '') {
			return null;
		}

		$encoded = strtr($token, '-_', '+/');
		$encoded .= str_repeat('=', (4 - (strlen($encoded) % 4)) % 4);
		$json = base64_decode($encoded, true);
		if ($json === false) {
			throw new InvalidSearchCursorException('The search cursor is not valid base64url.');
		}

		try {
			$data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new InvalidSearchCursorException('The search cursor is not valid JSON.');
		}

		if (!is_array($data)
			|| !is_string($data['q'] ?? null)
			|| !is_string($data['p'] ?? null)
			|| !is_string($data['i'] ?? null)
			|| !is_int($data['t'] ?? null)
			|| !is_int($data['r'] ?? null)
		) {
			throw new InvalidSearchCursorException('The search cursor has no usable position.');
		}

		$now ??= time();
		if ($data['t'] > $now || $now - $data['t'] > self::MAX_AGE_SECONDS) {
			throw new InvalidSearchCursorException('The search cursor has expired.');
		}

		if (!hash_equals(self::hashQuery($query), $data['q'])) {
			throw new InvalidSearchCursorException('The search cursor belongs to another query.');
		}

		try {
			$publishedAt = Time::parse($data['p']);
		} catch (\InvalidArgumentException) {
			throw new InvalidSearchCursorException('The search cursor has an invalid timestamp.');
		}

		return new self($data['q'], $publishedAt, $data['i'], $data['t'], $data['r']);
	}

	public function encode(): string
	{
		$json = json_encode([
			'q' => $this->queryHash,
			'p' => Time::format($this->publishedAt),
			'i' => $this->id,
			't' => $this->createdAt,
			'r' => $this->rank,
		], JSON_THROW_ON_ERROR);

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public static function hashQuery(string $query): string
	{
		return hash('sha256', mb_strtolower(trim($query)));
	}
}
