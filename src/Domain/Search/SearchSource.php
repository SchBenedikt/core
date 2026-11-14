<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

/**
 * Where an indexed result came from.
 *
 * A cached remote result is still remote, but its `cached_at` tells a client
 * how old the copy is. The core never presents a cached record as a live
 * remote answer.
 */
enum SearchSource: string
{
	case Local = 'local';
	case Remote = 'remote';
	case Cache = 'cache';
}
