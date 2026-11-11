<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

/**
 * Maps a job type to its handler.
 *
 * A type nobody registered is an error, not a silent no-op: a job that can
 * never succeed must end up in the dead-letter count where an operator sees
 * it.
 */
final readonly class JobHandlerRegistry
{
	/**
	 * @param array<string, JobHandler> $handlers
	 */
	public function __construct(
		private array $handlers = [],
	) {
	}

	public function with(string $type, JobHandler $handler): self
	{
		$handlers = $this->handlers;
		$handlers[$type] = $handler;

		return new self($handlers);
	}

	public function has(string $type): bool
	{
		return isset($this->handlers[$type]);
	}

	/**
	 * @return list<string>
	 */
	public function types(): array
	{
		$types = array_keys($this->handlers);
		sort($types);

		return $types;
	}

	public function handle(Job $job): void
	{
		$handler = $this->handlers[$job->type] ?? null;
		if ($handler === null) {
			throw new UnknownJobTypeException($job->type);
		}

		$handler->handle($job);
	}
}
