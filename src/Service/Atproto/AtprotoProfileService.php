<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlob;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobUploader;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\AtprotoProfile;
use AlohaSocial\Core\Protocol\Atproto\AtprotoProfileRecord;

use function in_array;

/**
 * Writes the local profile to the connected account's repository.
 *
 * The host owns the local profile and calls this when it decides to publish
 * it. Avatar and banner go through the same blob uploader as post media; both
 * are validated before the first upload, so one unsupported image leaves the
 * existing profile record untouched.
 */
final class AtprotoProfileService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'atproto.publish';

	public const MAX_IMAGE_BYTES = 1_000_000;

	private const IMAGE_TYPES = ['image/jpeg', 'image/png'];

	public function __construct(
		private readonly Config $config,
		private readonly ?AtprotoAccountResolver $accounts,
		private readonly ?AtprotoPublisher $publisher,
		private readonly ?AtprotoDeleter $deleter,
		private readonly ?AtprotoBlobUploader $uploader,
		private readonly Clock $clock,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, false);
	}

	/**
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoProfileRejectedException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 * @throws \AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException
	 */
	public function publish(string $localUserId, AtprotoProfile $profile): string
	{
		$this->assertEnabled();

		if ($this->publisher === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId);
		$this->validate($profile);

		return $this->publisher->publish(
			$account,
			AtprotoProfileRecord::RKEY,
			AtprotoProfileRecord::fromProfile(
				$profile,
				$this->upload($account, $profile->avatar),
				$this->upload($account, $profile->banner),
				$this->clock->now(),
			),
			AtprotoProfileRecord::COLLECTION,
		);
	}

	/**
	 * Removes the profile record; the account itself and its posts stay.
	 *
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function remove(string $localUserId): void
	{
		$this->assertEnabled();

		if ($this->deleter === null) {
			throw new AtprotoNotConfiguredException();
		}

		$this->deleter->delete(
			$this->account($localUserId),
			AtprotoProfileRecord::RKEY,
			AtprotoProfileRecord::COLLECTION,
		);
	}

	private function assertEnabled(): void
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}
	}

	private function account(string $localUserId): AtprotoAccount
	{
		$account = $this->accounts?->resolve($localUserId);
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		return $account;
	}

	/**
	 * Both images are checked before the first upload, so a refused banner can
	 * not leave an orphaned avatar upload and a changed record behind.
	 */
	private function validate(AtprotoProfile $profile): void
	{
		foreach ([$profile->avatar, $profile->banner] as $media) {
			if ($media === null) {
				continue;
			}

			if (!in_array($media->mimeType, self::IMAGE_TYPES, true)) {
				throw new AtprotoProfileRejectedException(
					AtprotoProfileRejectedException::MEDIA_UNSUPPORTED,
				);
			}

			if ($media->size() > self::MAX_IMAGE_BYTES) {
				throw new AtprotoProfileRejectedException(
					AtprotoProfileRejectedException::MEDIA_TOO_LARGE,
				);
			}
		}
	}

	/**
	 * @throws \AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException
	 */
	private function upload(AtprotoAccount $account, ?AtprotoMedia $media): ?AtprotoBlob
	{
		if ($media === null) {
			return null;
		}

		if ($this->uploader === null) {
			throw new AtprotoNotConfiguredException();
		}

		return $this->uploader->upload($account, $media);
	}
}
