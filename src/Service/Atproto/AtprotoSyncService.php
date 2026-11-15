<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoBlobUrlBuilder;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventSource;
use AlohaSocial\Core\Domain\Atproto\AtprotoFeedPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoLabel;
use AlohaSocial\Core\Domain\Atproto\AtprotoModerationPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoSourceStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoSyncCheckpoint;
use AlohaSocial\Core\Domain\Atproto\AtprotoSyncResult;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

use function array_slice;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function max;
use function min;
use function rtrim;
use function str_repeat;
use function strcmp;
use function strlen;
use function strtr;

/**
 * Imports normalized AT Protocol events into a durable, queryable store.
 *
 * The transport is a host port. One page is pulled outside the transaction,
 * then all records and the checkpoint are committed together, so a crash
 * repeats a page instead of losing it. Sequence gaps mark the checkpoint for
 * bounded reconciliation; reads use only cached records and label their
 * freshness from the last sync outcome.
 */
final class AtprotoSyncService
{
	public const DEFAULT_LIMIT = 50;

	public const MAX_LIMIT = 100;

	public const DEFAULT_BACKFILL_PAGES = 10;

	public const MAX_BACKFILL_PAGES = 10;

	public const DEFAULT_BACKFILL_DAYS = 30;

	public const MAX_BACKFILL_DAYS = 3650;

	public function __construct(
		private readonly AtprotoEventSource $source,
		private readonly AtprotoInboundRepository $store,
		private readonly AtprotoModerationPolicy $moderation,
		private readonly Clock $clock,
		private readonly ?TransactionRunner $transactions = null,
		private readonly ?AtprotoBlobUrlBuilder $blobs = null,
	) {
	}

	/**
	 * Pulls and applies one bounded page.
	 *
	 * @throws AtprotoSyncException when the source is unavailable, a source
	 *                              name does not match or the store fails
	 */
	public function sync(int $limit = self::DEFAULT_LIMIT): AtprotoSyncResult
	{
		return $this->runPage($this->limit($limit), null);
	}

	/**
	 * Pulls up to ten pages and skips posts older than the retention window.
	 *
	 * The bound is part of the contract: a relay that never ends cannot turn
	 * a backfill into an unbounded crawl. Account events and tombstones are
	 * still applied so a revocation is not lost because its timestamp is old.
	 */
	public function backfill(
		int $pages = self::DEFAULT_BACKFILL_PAGES,
		int $limit = self::DEFAULT_LIMIT,
		int $days = self::DEFAULT_BACKFILL_DAYS,
	): AtprotoSyncResult {
		$pages = max(1, min(self::MAX_BACKFILL_PAGES, $pages));
		$limit = $this->limit($limit);
		$days = max(1, min(self::MAX_BACKFILL_DAYS, $days));
		$cutoff = $this->clock->now()->modify('-' . $days . ' days');

		$applied = 0;
		$skipped = 0;
		$conflicted = 0;
		$deleted = 0;
		$done = 0;
		$reachedEnd = false;
		$result = null;

		for ($page = 0; $page < $pages; ++$page) {
			$result = $this->runPage($limit, $cutoff);
			++$done;
			$applied += $result->applied;
			$skipped += $result->skipped;
			$conflicted += $result->conflicted;
			$deleted += $result->deleted;
			$reachedEnd = $result->reachedEnd;
			if ($reachedEnd) {
				break;
			}
		}

		if ($result === null) {
			throw new AtprotoSyncException(
				AtprotoSyncException::SOURCE_UNAVAILABLE,
				'The AT Protocol source returned no page.',
			);
		}

		$checkpoint = $result->checkpoint;
		if ($reachedEnd) {
			$checkpoint = $checkpoint->withReconcileRequired(false, Time::toSecondPrecision($this->clock->now()));
			$this->store->saveCheckpoint($checkpoint);
		}

		return new AtprotoSyncResult(
			$result->source,
			$applied,
			$skipped,
			$conflicted,
			$deleted,
			$done,
			$reachedEnd,
			$checkpoint,
			$this->statusFor($checkpoint),
		);
	}

	public function status(): AtprotoSourceStatus
	{
		$stored = $this->statusFor($this->store->checkpoint($this->source->name()));

		try {
			$live = $this->source->status();
		} catch (Throwable) {
			return $stored->stale
				? $stored
				: AtprotoSourceStatus::stale(
					$this->source->name(),
					Time::toSecondPrecision($this->clock->now()),
					'The AT Protocol source status could not be read.',
				);
		}

		return $live->isFresh() ? $stored : $live;
	}

	/**
	 * Reads cached posts for one author without touching the network.
	 */
	public function feed(string $did, int $limit = 20, ?string $cursor = null): AtprotoFeedPage
	{
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$checkpoint = $this->store->checkpoint($this->source->name());
		$status = $this->statusFor($checkpoint);

		[$beforePublishedAt, $beforeUri] = $cursor === null || $cursor === ''
			? [null, null]
			: $this->decodeCursor($cursor);

		$rows = $this->store->listRecords(
			$this->source->name(),
			$did,
			AtprotoPostProjection::COLLECTION,
			$limit + 1,
			$beforePublishedAt,
			$beforeUri,
		);

		$hasMore = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		$account = $this->store->account($this->source->name(), $did);
		$posts = [];
		$last = $rows === [] ? null : $rows[count($rows) - 1];

		foreach ($rows as $row) {
			$post = AtprotoPostProjection::fromRecord($row, $this->blobs, $account?->handle);
			if ($post !== null) {
				$posts[] = $post;
			}
		}

		return new AtprotoFeedPage(
			$posts,
			$hasMore && $last !== null ? $this->encodeCursor($last) : null,
			$status,
			$checkpoint?->lastEventAt,
		);
	}

	/**
	 * @throws AtprotoSyncException
	 */
	private function runPage(int $limit, ?DateTimeImmutable $notBefore): AtprotoSyncResult
	{
		$name = $this->source->name();
		$checkpoint = $this->store->checkpoint($name)
			?? AtprotoSyncCheckpoint::initial($name, Time::toSecondPrecision($this->clock->now()));

		try {
			$page = $this->source->pull($checkpoint->cursor, $limit);
		} catch (Throwable $exception) {
			$this->markFailed($checkpoint, $exception);

			throw new AtprotoSyncException(
				AtprotoSyncException::SOURCE_UNAVAILABLE,
				'The AT Protocol source could not be read.',
				$exception,
			);
		}

		try {
			return $this->transactional(fn (): AtprotoSyncResult => $this->applyPage($page, $checkpoint, $notBefore));
		} catch (AtprotoSyncException $exception) {
			$this->markFailed($checkpoint, $exception);

			throw $exception;
		} catch (Throwable $exception) {
			$this->markFailed($checkpoint, $exception);

			throw new AtprotoSyncException(
				AtprotoSyncException::STORE_FAILED,
				'The AT Protocol page could not be stored.',
				$exception,
			);
		}
	}

	private function applyPage(
		AtprotoEventPage $page,
		AtprotoSyncCheckpoint $checkpoint,
		?DateTimeImmutable $notBefore,
	): AtprotoSyncResult {
		$applied = 0;
		$skipped = 0;
		$conflicted = 0;
		$deleted = 0;
		$lastSequence = $checkpoint->lastSequence;
		$lastEventAt = $checkpoint->lastEventAt;
		$gap = $page->gapDetected;
		$name = $this->source->name();

		foreach ($page->events as $event) {
			if ($event->source !== $name) {
				throw new AtprotoSyncException(
					AtprotoSyncException::SOURCE_MISMATCH,
					'An event claims a different AT Protocol source.',
				);
			}

			if ($event->sequence !== null && $lastSequence !== null) {
				if ($event->sequence < $lastSequence) {
					++$skipped;
					continue;
				}

				if ($event->sequence > $lastSequence + 1) {
					$gap = true;
				}
			}

			$outcome = $this->applyEvent($event, $notBefore);
			$applied += $outcome['applied'];
			$skipped += $outcome['skipped'];
			$conflicted += $outcome['conflicted'];
			$deleted += $outcome['deleted'];

			if ($event->sequence !== null) {
				$lastSequence = $lastSequence === null ? $event->sequence : max($lastSequence, $event->sequence);
			}

			if ($lastEventAt === null || $event->receivedAt > $lastEventAt) {
				$lastEventAt = $event->receivedAt;
			}
		}

		$checkpoint = $checkpoint->advanced(
			$page->cursor,
			$lastSequence,
			$lastEventAt,
			Time::toSecondPrecision($this->clock->now()),
			$checkpoint->reconcileRequired || $gap,
		);
		$this->store->saveCheckpoint($checkpoint);

		return new AtprotoSyncResult(
			$name,
			$applied,
			$skipped,
			$conflicted,
			$deleted,
			1,
			!$page->hasMore,
			$checkpoint,
			$this->statusFor($checkpoint),
		);
	}

	/**
	 * @return array{applied: int, skipped: int, conflicted: int, deleted: int}
	 */
	private function applyEvent(AtprotoEvent $event, ?DateTimeImmutable $notBefore): array
	{
		if ($event->kind === AtprotoEventKind::Account) {
			return $this->applyAccount($event);
		}

		return $this->applyRecord($event, $notBefore);
	}

	/**
	 * @return array{applied: int, skipped: int, conflicted: int, deleted: int}
	 */
	private function applyAccount(AtprotoEvent $event): array
	{
		$status = $event->accountStatus
			?? ($event->active === true ? AtprotoAccountStatus::Active : AtprotoAccountStatus::Deleted);

		$existingAccount = $this->store->account($event->source, $event->did);
		$handle = $event->handle ?? $existingAccount?->handle;

		if ($handle !== null) {
			$existingHandle = $this->store->accountByHandle($event->source, $handle);
			if ($existingHandle !== null && $existingHandle->did !== $event->did) {
				throw new AtprotoSyncException(
					AtprotoSyncException::IDENTITY_CONFLICT,
					'A handle is already bound to another AT Protocol DID.',
				);
			}
		}

		$this->store->saveAccount(new AtprotoRemoteAccount(
			$event->source,
			$event->did,
			$handle,
			$status,
			$status->isAvailable(),
			Time::toSecondPrecision($event->receivedAt),
		));

		$deleted = $status->isAvailable() ? 0 : $this->store->deleteRecords($event->source, $event->did);

		return ['applied' => 1, 'skipped' => 0, 'conflicted' => 0, 'deleted' => $deleted];
	}

	/**
	 * @return array{applied: int, skipped: int, conflicted: int, deleted: int}
	 */
	private function applyRecord(AtprotoEvent $event, ?DateTimeImmutable $notBefore): array
	{
		$account = $this->store->account($event->source, $event->did);
		if ($account !== null && !$account->active) {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		if (!$this->moderation->allowsAuthor($event->did)) {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		foreach ($event->labels as $label) {
			if (!$this->allowsLabel($label)) {
				return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
			}
		}

		$uri = $event->uri();
		if ($uri === null) {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		$existing = $this->store->record($event->source, $uri);
		$ordering = $this->compare($existing, $event);
		if ($ordering === 'older') {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		if ($ordering === 'conflict') {
			return ['applied' => 0, 'skipped' => 0, 'conflicted' => 1, 'deleted' => 0];
		}

		if ($ordering === 'duplicate') {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		if ($event->operation === AtprotoRecordOperation::Delete) {
			if ($existing?->deleted === true) {
				return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
			}

			$this->store->saveRecord(new AtprotoRemoteRecord(
				$event->source,
				$event->did,
				(string) $event->collection,
				(string) $event->rkey,
				$uri,
				$event->cid ?? $existing?->cid,
				$event->rev,
				$event->sequence,
				[],
				true,
				null,
				Time::toSecondPrecision($event->receivedAt),
				Time::toSecondPrecision($this->clock->now()),
			));

			return [
				'applied' => 1,
				'skipped' => 0,
				'conflicted' => 0,
				'deleted' => $existing === null ? 0 : 1,
			];
		}

		$candidate = new AtprotoRemoteRecord(
			$event->source,
			$event->did,
			(string) $event->collection,
			(string) $event->rkey,
			$uri,
			$event->cid,
			$event->rev,
			$event->sequence,
			$event->record,
			false,
			null,
			Time::toSecondPrecision($event->receivedAt),
			Time::toSecondPrecision($this->clock->now()),
		);

		$publishedAt = null;
		if ($candidate->isPost()) {
			$projection = AtprotoPostProjection::fromRecord($candidate, $this->blobs);
			if ($projection === null) {
				return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
			}

			$publishedAt = $projection->createdAt;
		}

		if ($notBefore !== null && $publishedAt !== null && $publishedAt < $notBefore) {
			return ['applied' => 0, 'skipped' => 1, 'conflicted' => 0, 'deleted' => 0];
		}

		$this->store->saveRecord(new AtprotoRemoteRecord(
			$candidate->source,
			$candidate->did,
			$candidate->collection,
			$candidate->rkey,
			$candidate->uri,
			$candidate->cid,
			$candidate->rev,
			$candidate->sequence,
			$candidate->record,
			false,
			$publishedAt,
			$candidate->receivedAt,
			$candidate->updatedAt,
		));

		return ['applied' => 1, 'skipped' => 0, 'conflicted' => 0, 'deleted' => 0];
	}

	private function allowsLabel(AtprotoLabel $label): bool
	{
		return $label->negated || $this->moderation->allowsLabel($label);
	}

	/**
	 * @return string `newer`, `older`, `duplicate` or `conflict`
	 */
	private function compare(?AtprotoRemoteRecord $existing, AtprotoEvent $event): string
	{
		if ($existing === null) {
			return 'newer';
		}

		if ($existing->sequence !== null && $event->sequence !== null) {
			if ($event->sequence < $existing->sequence) {
				return 'older';
			}

			if ($event->sequence === $existing->sequence) {
				return $this->sameVersion($existing, $event) ? 'duplicate' : 'conflict';
			}

			return 'newer';
		}

		if ($existing->rev !== null && $event->rev !== null) {
			$comparison = strcmp($event->rev, $existing->rev);
			if ($comparison < 0) {
				return 'older';
			}

			if ($comparison === 0) {
				return $this->sameVersion($existing, $event) ? 'duplicate' : 'conflict';
			}
		}

		return 'newer';
	}

	private function sameVersion(AtprotoRemoteRecord $existing, AtprotoEvent $event): bool
	{
		if ($event->operation === AtprotoRecordOperation::Delete && $existing->deleted) {
			return true;
		}

		return $existing->cid !== null && $event->cid !== null && $existing->cid === $event->cid;
	}

	private function statusFor(?AtprotoSyncCheckpoint $checkpoint): AtprotoSourceStatus
	{
		if ($checkpoint === null) {
			return AtprotoSourceStatus::live($this->source->name(), null);
		}

		if ($checkpoint->stale) {
			return AtprotoSourceStatus::stale(
				$checkpoint->source,
				$checkpoint->lastAttemptAt,
				$checkpoint->lastError,
			);
		}

		return AtprotoSourceStatus::live($checkpoint->source, $checkpoint->lastAttemptAt);
	}

	private function markFailed(AtprotoSyncCheckpoint $checkpoint, Throwable $exception): void
	{
		try {
			$this->store->saveCheckpoint($checkpoint->failed(
				Time::toSecondPrecision($this->clock->now()),
				$exception->getMessage(),
			));
		} catch (Throwable) {
			// The source failure is the useful error; a failed status write
			// must not hide it. The next successful transaction overwrites it.
		}
	}

	private function transactional(callable $work): mixed
	{
		return $this->transactions === null ? $work() : $this->transactions->transaction($work);
	}

	private function limit(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	private function encodeCursor(AtprotoRemoteRecord $record): string
	{
		if ($record->publishedAt === null) {
			throw new \LogicException('A timeline cursor needs a published record.');
		}

		$raw = Time::format($record->publishedAt) . '|' . $record->uri;

		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	/**
	 * @return array{0: DateTimeImmutable, 1: string}
	 */
	private function decodeCursor(string $cursor): array
	{
		$padding = strlen($cursor) % 4;
		$decoded = base64_decode(strtr($cursor, '-_', '+/') . ($padding === 0 ? '' : str_repeat('=', 4 - $padding)), true);
		if ($decoded === false) {
			throw new AtprotoSyncException(AtprotoSyncException::INVALID_CURSOR, 'The feed cursor is not valid.');
		}

		$parts = explode('|', $decoded, 2);
		if (count($parts) !== 2 || !AtprotoIdentifiers::isAtUri($parts[1])) {
			throw new AtprotoSyncException(AtprotoSyncException::INVALID_CURSOR, 'The feed cursor is not valid.');
		}

		try {
			return [Time::parse($parts[0]), $parts[1]];
		} catch (\InvalidArgumentException) {
			throw new AtprotoSyncException(AtprotoSyncException::INVALID_CURSOR, 'The feed cursor is not valid.');
		}
	}
}
