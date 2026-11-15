<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * The account state a relay or AppView reports.
 *
 * Unknown upstream states are mapped to `Suspended` by the adapter rather than
 * guessed as active: an unreadable revocation must never restore content.
 */
enum AtprotoAccountStatus: string
{
	case Active = 'active';

	case Deactivated = 'deactivated';

	case Suspended = 'suspended';

	case Deleted = 'deleted';

	case Takedown = 'takedown';

	public function isAvailable(): bool
	{
		return $this === self::Active;
	}
}
