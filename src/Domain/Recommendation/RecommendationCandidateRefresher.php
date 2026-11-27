<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

/**
 * Revalidates stored candidates against the live source objects.
 *
 * A recommendation snapshot is a ranked list, not an authorization grant:
 * between the snapshot write and a later cursor page the underlying post can
 * be deleted, archived, edited or have its audience narrowed. Before a cached
 * page is presented, the candidates are resolved back to their canonical
 * records in bounded reads — never a remote network call — and anything that
 * no longer qualifies is dropped. A candidate missing from the result is
 * treated as no longer presentable.
 */
interface RecommendationCandidateRefresher
{
	/**
	 * @param list<RecommendationCandidate> $candidates
	 *
	 * @return array<string, RecommendationCandidate> live candidates keyed by
	 * candidate id; ids absent from the map must not be presented
	 */
	public function refresh(string $viewerId, array $candidates, DateTimeImmutable $now): array;
}
