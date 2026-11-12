<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Network\HostResolver;

use function max;
use function min;

/**
 * Fetches one remote image with the federation guard rails.
 *
 * URL, DNS, address, redirect and body-limit checks live in RemoteFetcher and
 * are shared with thread/context fetching. This class only supplies the media
 * switch, media limits and the stable `remote_media_*` reasons.
 */
final class RemoteMediaFetcher
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'media.remote_fetch';

	public const CONFIG_MAX_BYTES = 'media.remote_max_bytes';

	public const CONFIG_TIMEOUT_SECONDS = 'media.remote_timeout_seconds';

	public const CONFIG_MAX_REDIRECTS = 'media.remote_max_redirects';

	public const DEFAULT_TIMEOUT_SECONDS = 10;

	public const MAX_TIMEOUT_SECONDS = 30;

	public const DEFAULT_MAX_REDIRECTS = 3;

	public const MAX_REDIRECTS = 5;

	private const ACCEPT = 'image/jpeg, image/png, image/webp';

	private readonly RemoteFetcher $fetcher;

	public function __construct(
		BoundedHttpClient $http,
		HostResolver $resolver,
		private readonly Config $config,
	) {
		$this->fetcher = new RemoteFetcher($http, $resolver);
	}

	public function enabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, true);
	}

	/**
	 * @throws RemoteMediaException with a stable reason
	 */
	public function fetch(string $url): RemoteMediaResult
	{
		if (!$this->enabled()) {
			throw new RemoteMediaException(
				'remote_media_disabled',
				'Remote media fetching is disabled.',
			);
		}

		try {
			$result = $this->fetcher->fetch(
				$url,
				self::ACCEPT,
				$this->timeoutSeconds(),
				$this->maxBytes(),
				$this->maxRedirects(),
			);
		} catch (RemoteFetchException $exception) {
			throw new RemoteMediaException(
				'remote_media_' . $exception->reason,
				$exception->getMessage(),
			);
		}

		return new RemoteMediaResult($result->body, $result->contentType);
	}

	private function maxBytes(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_BYTES,
			Attachment::MAX_BYTES,
		);

		return max(1, min(Attachment::MAX_BYTES, $configured));
	}

	private function timeoutSeconds(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_TIMEOUT_SECONDS,
			self::DEFAULT_TIMEOUT_SECONDS,
		);

		return max(1, min(self::MAX_TIMEOUT_SECONDS, $configured));
	}

	private function maxRedirects(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_REDIRECTS,
			self::DEFAULT_MAX_REDIRECTS,
		);

		return max(0, min(self::MAX_REDIRECTS, $configured));
	}
}
