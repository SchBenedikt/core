<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The per-user block list as a use case.
 *
 * A block is idempotent by (local user, actor): repeating it returns the
 * existing row instead of creating a duplicate. It is private to the local
 * user; another account may still follow and see the same actor.
 */
final class BlockService
{
	/** Matches the indexed column in {@see \AlohaSocial\Core\Domain\Federation\BlockSchema}. */
	public const MAX_ACTOR_LENGTH = Block::MAX_ACTOR_LENGTH;

	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 100;

	public function __construct(
		private readonly BlockRepository $blocks,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	public function block(BlockActor $command): BlockResult
	{
		$localUserId = trim($command->localUserId);
		$actorId = trim($command->actorId);

		$errors = [];

		if ($localUserId === '') {
			$errors['user'] = 'A block needs a local user.';
		}

		if (!$this->isAbsoluteHttpUrl($actorId)) {
			$errors['actor'] = 'actor must be an absolute http(s) URL.';
		} elseif (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			$errors['actor'] = 'actor may be at most ' . self::MAX_ACTOR_LENGTH . ' characters long.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$existing = $this->blocks->findByActor($localUserId, $actorId);
		if ($existing !== null) {
			return new BlockResult($existing, false);
		}

		$block = new Block(
			id: $this->ids->generate(),
			localUserId: $localUserId,
			actorId: $actorId,
			blockedAt: Time::toSecondPrecision($this->clock->now()),
		);

		try {
			$this->blocks->insert($block);
		} catch (DatabaseException $exception) {
			// Two requests may race between the lookup and the insert. The
			// unique index is the authority; answer with the winner instead
			// of turning an idempotent block into a 500.
			$winner = $this->blocks->findByActor($localUserId, $actorId);
			if ($winner !== null) {
				return new BlockResult($winner, false);
			}

			throw $exception;
		}

		return new BlockResult($block, true);
	}

	/**
	 * @return bool whether the block existed and belonged to the caller
	 */
	public function unblock(string $localUserId, string $id): bool
	{
		return $this->blocks->remove(trim($id), trim($localUserId));
	}

	/**
	 * @return list<Block>
	 *
	 * @throws InvalidCursorException
	 */
	public function blocks(string $localUserId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		$clamped = max(1, min(self::MAX_LIMIT, $limit));

		return $this->blocks->blocks($localUserId, $clamped, $this->cursor($before));
	}

	public function count(string $localUserId): int
	{
		return $this->blocks->count($localUserId);
	}

	public function isBlocked(string $localUserId, string $actorId): bool
	{
		return $this->blocks->findByActor(trim($localUserId), trim($actorId)) !== null;
	}

	/**
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array
	{
		return $this->blocks->actorIds(trim($localUserId));
	}

	/**
	 * @throws InvalidCursorException
	 */
	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		try {
			return Time::parse(trim($before));
		} catch (InvalidArgumentException) {
			throw new InvalidCursorException($before);
		}
	}

	/**
	 * Only absolute http(s) URLs are accepted: a bare handle is not an actor
	 * identity, and a block that does not match the stored actor id would
	 * silently fail to hide anything.
	 */
	private function isAbsoluteHttpUrl(string $value): bool
	{
		if ($value === '' || trim($value) !== $value) {
			return false;
		}

		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);
		$host = parse_url($value, PHP_URL_HOST);

		return is_string($scheme)
			&& in_array(strtolower($scheme), ['http', 'https'], true)
			&& is_string($host)
			&& $host !== '';
	}
}
