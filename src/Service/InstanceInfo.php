<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\LocalActorCount;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\RemoteDomainCount;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\PostCount;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Platform\Config\Config;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function is_array;
use function json_decode;
use function max;
use function str_starts_with;
use function trim;

/**
 * What this instance tells the world about itself.
 *
 * Read from configuration on every call rather than cached at boot, so an
 * administrator renaming the instance does not need a restart. The optional
 * count capabilities are checked through their narrow interfaces: a host that
 * cannot cheaply count its accounts, posts or remote domains gets zero for
 * that field instead of an expensive scan or an invented number.
 */
final class InstanceInfo
{
	public const CONFIG_APP = 'social';

	public const CONFIG_NAME = 'instance.name';

	public const CONFIG_DESCRIPTION = 'instance.description';

	public const CONFIG_VERSION = 'instance.version';

	public const CONFIG_CONTACT_EMAIL = 'instance.contact_email';

	public const CONFIG_CONTACT_ACCOUNT = 'instance.contact_account';

	public const CONFIG_REGISTRATIONS = 'instance.registrations';

	public const CONFIG_APPROVAL_REQUIRED = 'instance.approval_required';

	public const CONFIG_INVITES_ENABLED = 'instance.invites_enabled';

	public const CONFIG_LANGUAGES = 'instance.languages';

	public const CONFIG_THUMBNAIL = 'instance.thumbnail';

	public function __construct(
		private readonly Config $config,
		private readonly ?LocalActorDirectory $localActors = null,
		private readonly ?PostRepository $posts = null,
		private readonly ?RemotePostRepository $remotePosts = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describe(): array
	{
		return [
			'name' => $this->config->get(self::CONFIG_APP, self::CONFIG_NAME, 'Aloha Social'),
			'description' => $this->config->get(self::CONFIG_APP, self::CONFIG_DESCRIPTION, ''),
			'version' => $this->config->get(self::CONFIG_APP, self::CONFIG_VERSION, '0.1.0'),
			'api' => ApiVersion::CURRENT,
			'federation' => $this->config->getBool(self::CONFIG_APP, 'federation.enabled', true),
			'email' => $this->contactEmail(),
			'contact_account' => $this->contactAccount(),
			'registrations' => $this->config->getBool(self::CONFIG_APP, self::CONFIG_REGISTRATIONS, false),
			'approval_required' => $this->config->getBool(self::CONFIG_APP, self::CONFIG_APPROVAL_REQUIRED, true),
			'invites_enabled' => $this->config->getBool(self::CONFIG_APP, self::CONFIG_INVITES_ENABLED, false),
			'languages' => $this->languages(),
			'thumbnail' => trim((string) $this->config->get(self::CONFIG_APP, self::CONFIG_THUMBNAIL, '')),
			'stats' => $this->stats(),
		];
	}

	/**
	 * Local counts used by the public instance response.
	 *
	 * These are deliberately optional capabilities. The base directory and
	 * post/remote repositories do not have to expose a count, and a missing
	 * capability stays visibly zero instead of doing an unbounded scan.
	 *
	 * @return array{user_count: int, status_count: int, domain_count: int}
	 */
	public function stats(): array
	{
		return [
			'user_count' => $this->localActors instanceof LocalActorCount
				? max(0, $this->localActors->localActorCount())
				: 0,
			'status_count' => $this->posts instanceof PostCount
				? max(0, $this->posts->countLive())
				: 0,
			'domain_count' => $this->remotePosts instanceof RemoteDomainCount
				? max(0, $this->remotePosts->remoteDomainCount())
				: 0,
		];
	}

	private function contactEmail(): string
	{
		$email = $this->config->get(self::CONFIG_APP, self::CONFIG_CONTACT_EMAIL);
		if ($email === null) {
			// Keep the older, shorter key useful for hosts that already store
			// an address under it; the documented key is the explicit one.
			$email = $this->config->get(self::CONFIG_APP, 'instance.email');
		}

		return trim((string) $email);
	}

	private function contactAccount(): string
	{
		$userId = trim((string) $this->config->get(self::CONFIG_APP, self::CONFIG_CONTACT_ACCOUNT, ''));
		if ($userId === '') {
			return '';
		}

		// A directory is the only local source of truth for account ids. A
		// stale config value must not make either public endpoint advertise an
		// account that cannot be opened; without a directory the host owns it.
		if ($this->localActors !== null && !$this->localActors->has($userId)) {
			return '';
		}

		return $userId;
	}

	/**
	 * @return list<string>
	 */
	private function languages(): array
	{
		$raw = trim((string) $this->config->get(self::CONFIG_APP, self::CONFIG_LANGUAGES, ''));
		if ($raw === '') {
			return [];
		}

		if (str_starts_with($raw, '[')) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				return array_values(array_unique(array_filter(
					array_map(static fn (mixed $value): string => trim((string) $value), $decoded),
					static fn (string $value): bool => $value !== '',
				)));
			}
		}

		return array_values(array_unique(array_filter(
			array_map(static fn (string $value): string => trim($value), explode(',', $raw)),
			static fn (string $value): bool => $value !== '',
		)));
	}
}
