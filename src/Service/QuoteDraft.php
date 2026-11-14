<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\QuoteState;

/**
 * The validated quote decision for a post that is about to be written.
 *
 * A draft is prepared before the post row exists, so an unquotable target is
 * refused before anything is stored. {@see QuoteService::commit()} then
 * issues the synchronous grant or queues the remote request.
 */
final readonly class QuoteDraft
{
	public function __construct(
		public string $quotePostId,
		public string $quotedObjectId,
		public QuoteState $state,
		public ?string $authorizationId = null,
		public ?string $requestId = null,
		public ?string $localQuotedPostId = null,
		public ?string $quotedActorId = null,
		public ?string $remoteTargetActorId = null,
	) {
	}
}
