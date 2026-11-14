<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

/**
 * The outcome of a media read check.
 *
 * `publiclyCacheable` is separate from `allowed` on purpose: an authorized
 * reader of a followers-only image must get the bytes without a shared cache
 * or a proxy keeping a copy that outlives the permission.
 */
final readonly class MediaAccessDecision
{
	private function __construct(
		public bool $allowed,
		public bool $publiclyCacheable,
	) {
	}

	public static function permit(bool $publiclyCacheable): self
	{
		return new self(true, $publiclyCacheable);
	}

	public static function refuse(): self
	{
		return new self(false, false);
	}
}
