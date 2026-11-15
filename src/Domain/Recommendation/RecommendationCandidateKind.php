<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * Where a candidate came from. The kind is part of the public candidate id so
 * a local post and a cached remote activity cannot collide.
 */
enum RecommendationCandidateKind: string
{
	case Local = 'local';

	case Remote = 'remote';
}
