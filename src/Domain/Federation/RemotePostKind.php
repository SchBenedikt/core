<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * A remote post is either the object itself or a boost/Announce of another
 * object. The kind is stored explicitly so the home timeline never mistakes an
 * Announce for a standalone post.
 */
enum RemotePostKind: string
{
	case Post = 'post';

	case Boost = 'boost';
}
