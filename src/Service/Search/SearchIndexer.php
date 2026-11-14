<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Search;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Search\SearchIndex;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Domain\Search\TrendRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Keeps the discovery index and trend rows in step with local post writes.
 *
 * Indexing is best effort: the post row is already committed, so a search
 * outage must not undo a publication. The indexer also refuses non-public
 * posts, which is the first line of defence behind {@see SearchRecord}.
 */
final class SearchIndexer
{
	public function __construct(
		private readonly SearchIndex $index,
		private readonly TrendRepository $trends,
		private readonly Clock $clock,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	private readonly LoggerInterface $logger;

	public function indexPost(Post $post, SearchSource $source = SearchSource::Local): void
	{
		try {
			if ($post->isDeleted() || $post->visibility !== Visibility::Public) {
				$this->removePost($post->id, $post->authorId);

				return;
			}

			$record = SearchRecord::create(
				id: 'post:' . $post->id,
				kind: SearchKind::Post,
				source: $source,
				content: $post->content,
				publishedAt: $post->publishedAt,
				cachedAt: $this->clock->now(),
				authorId: $post->authorId,
				hashtags: Hashtags::extract($post->content),
			);

			$this->index->upsert($record);
			$this->trends->record($record);
		} catch (Throwable $exception) {
			$this->logger->error('A local post could not be indexed.', [
				'post_id' => $post->id,
				'exception' => $exception,
			]);
		}
	}

	public function indexRecord(SearchRecord $record): void
	{
		try {
			$this->index->upsert($record);
			$this->trends->record($record);
		} catch (Throwable $exception) {
			$this->logger->error('A search record could not be indexed.', [
				'entry_id' => $record->id,
				'exception' => $exception,
			]);
		}
	}

	public function removePost(string $postId, ?string $authorId = null): void
	{
		try {
			$this->index->remove('post:' . $postId);
			$this->trends->remove('post:' . $postId);
		} catch (Throwable $exception) {
			$this->logger->error('A local post could not be removed from the index.', [
				'post_id' => $postId,
				'author_id' => $authorId,
				'exception' => $exception,
			]);
		}
	}
}
