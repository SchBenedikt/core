<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/** Host policy for deciding whether a local account may interact with a remote AT post. */
interface AtprotoInteractionTargetPolicy
{
	/** Must reject a locally blocked actor and any target the viewer cannot interact with. */
	public function allows(string $localUserId, AtprotoPostProjection $target): bool;
}
