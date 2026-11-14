<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use function filter_var;
use function in_array;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * The policy backed by the stored moderation actions.
 *
 * A domain action applies to an actor URL by host; an exact actor action
 * applies to a local account id or a remote actor URL. Subdomains are not
 * implied by a domain block: blocking `example.com` does not block
 * `sub.example.com`, because a moderator can name either one explicitly.
 */
final class StoredModerationPolicy implements ModerationPolicy
{
	public function __construct(
		private readonly ModerationActionRepository $actions,
	) {
	}

	public function allowsActor(string $actorId): bool
	{
		$actorId = trim($actorId);
		if ($actorId === '') {
			return true;
		}

		if ($this->actions->isActorRestricted($actorId)) {
			return false;
		}

		$domain = self::domainOf($actorId);

		return $domain === null || $this->allowsDomain($domain);
	}

	public function allowsDomain(string $domain): bool
	{
		$domain = self::normalizeDomain($domain);

		return $domain === null || !$this->actions->isDomainBlocked($domain);
	}

	public static function domainOf(string $actorId): ?string
	{
		$host = parse_url(trim($actorId), PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return null;
		}

		return self::normalizeDomain($host);
	}

	/**
	 * Accepts a bare host or an http(s) URL and returns the lower-case host
	 * without brackets, a port or a trailing dot.
	 */
	public static function normalizeDomain(string $value): ?string
	{
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		if (str_contains($value, '://')) {
			$host = parse_url($value, PHP_URL_HOST);
			if (!is_string($host)) {
				return null;
			}

			$value = $host;
		}

		$value = strtolower(trim($value));
		$value = rtrim($value, '.');
		if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
			$value = substr($value, 1, -1);
		}

		if ($value === '' || in_array($value, ['.', '..'], true)) {
			return null;
		}

		if (str_contains($value, ':')) {
			return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : $value;
		}

		return preg_match(
			'/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/',
			$value,
		) === 1 ? $value : null;
	}
}
