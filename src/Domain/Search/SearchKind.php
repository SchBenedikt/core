<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

/**
 * The kinds a search index can store.
 *
 * The values match the neutral and Mastodon `type` parameters, so a response
 * can state what it contains without inventing a second vocabulary.
 */
enum SearchKind: string
{
	case Account = 'accounts';
	case Post = 'statuses';
	case Hashtag = 'hashtags';
}
