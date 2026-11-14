<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * What the policy does with an event from one sender category.
 *
 * `allow` records and shows the event, `hold` stores it for a sender request,
 * and `drop` stores it only as policy audit. None of the three changes the
 * follow, favourite, boost or post that produced it.
 */
enum NotificationPolicyAction: string
{
	case Allow = 'allow';
	case Hold = 'hold';
	case Drop = 'drop';
}
