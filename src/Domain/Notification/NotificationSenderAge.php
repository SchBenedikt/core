<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Optional account-age lookup for the `new` sender category.
 *
 * The host owns account creation dates; the core only asks for a number of
 * days. Unknown or remote accounts answer null and are then classified as
 * strangers unless a follow relationship says otherwise.
 */
interface NotificationSenderAge
{
	public function ageInDays(string $actorId): ?int;
}
