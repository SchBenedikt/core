<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * The repository operation of a normalized record event.
 */
enum AtprotoRecordOperation: string
{
	case Create = 'create';

	case Update = 'update';

	case Delete = 'delete';
}
