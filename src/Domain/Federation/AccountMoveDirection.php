<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Whether an account move arrived from a peer or started on this instance.
 *
 * Inbound moves migrate the local follow graph; outbound moves publish the
 * Move and expose `movedTo` in the local actor document.
 */
enum AccountMoveDirection: string
{
	case Inbound = 'inbound';
	case Outbound = 'outbound';
}
