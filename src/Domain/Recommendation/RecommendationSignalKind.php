<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * An explicit interaction a host may report after the reader opted in.
 *
 * The core does not collect browser dwell time or infer behavior on its own.
 */
enum RecommendationSignalKind: string
{
	case Impression = 'impression';

	case Click = 'click';

	case Like = 'like';

	case Reply = 'reply';

	case Repost = 'repost';

	case Bookmark = 'bookmark';
}
