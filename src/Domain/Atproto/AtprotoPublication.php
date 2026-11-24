<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The record one local post became on a remote PDS.
 *
 * The row is written only after the PDS accepted the record. Its uniqueness
 * by (local user, DID, post) lets retries converge without conflating two
 * linked identities that published the same local post.
 */
final readonly class AtprotoPublication
{
	public string $accountDid;

	public function __construct(
		public string $localUserId,
		public string $postId,
		public string $rkey,
		public string $uri,
		public DateTimeImmutable $publishedAt,
		public ?string $pdsUrl = null,
		public ?string $cid = null,
		?string $accountDid = null,
	) {
		$accountDid ??= preg_match('~^at://([^/]+)/~', $uri, $matches) === 1 ? $matches[1] : '';
		$this->accountDid = $accountDid;
		if ($localUserId === '' || $postId === '' || $rkey === '' || preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $accountDid) !== 1) {
			throw new InvalidArgumentException('An AT Protocol publication needs an account, a post and a record key.');
		}

		if (!str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol publication needs a valid AT URI.');
		}

		if ($cid !== null && ($cid === '' || trim($cid) !== $cid || strlen($cid) > 256)) {
			throw new InvalidArgumentException('An AT Protocol publication needs a valid CID when one is available.');
		}

		if ($pdsUrl !== null) {
			$parts = parse_url($pdsUrl);
			if ($parts === false
				|| !isset($parts['scheme'], $parts['host'])
				|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
				|| (string) $parts['host'] === ''
				|| isset($parts['user'])
				|| isset($parts['pass'])
				|| isset($parts['query'])
				|| isset($parts['fragment'])
			) {
				throw new InvalidArgumentException('An AT Protocol publication needs a valid PDS URL.');
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return [
			'post_id' => $this->postId,
			'account_did' => $this->accountDid,
			'rkey' => $this->rkey,
			'uri' => $this->uri,
			'pds_url' => $this->pdsUrl ?? '',
			'cid' => $this->cid ?? '',
			'published_at' => Time::format($this->publishedAt),
		];
	}
}
