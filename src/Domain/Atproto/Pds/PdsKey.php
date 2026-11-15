<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

final readonly class PdsKey
{
	public const ROLE_SIGNING = 'signing';

	public const ROLE_ROTATION = 'rotation';

	public function __construct(
		public string $id,
		public string $localUserId,
		public string $did,
		public string $role,
		public string $sealedPrivateKey,
		public string $publicKeyPem,
		public string $publicKeyMultibase,
		public bool $active,
		public string $createdAt,
		public ?string $retiredAt = null,
	) {
		if (!in_array($role, [self::ROLE_SIGNING, self::ROLE_ROTATION], true)) {
			throw new \InvalidArgumentException('A PDS key role must be signing or rotation.');
		}

		if ($id === '' || $sealedPrivateKey === '' || $publicKeyMultibase === '') {
			throw new \InvalidArgumentException('A PDS key needs an id, sealed private key and public multibase.');
		}
	}
}
