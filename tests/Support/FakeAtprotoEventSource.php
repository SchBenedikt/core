<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventSource;
use AlohaSocial\Core\Domain\Atproto\AtprotoSourceStatus;
use Throwable;

/**
 * A scripted source: each pull consumes the next page and records its cursor.
 */
final class FakeAtprotoEventSource implements AtprotoEventSource
{
	/** @var list<array{cursor: ?string, limit: int}> */
	public array $pulls = [];

	public ?Throwable $failure = null;

	public AtprotoSourceStatus $sourceStatus;

	/**
	 * @param list<AtprotoEventPage> $pages
	 */
	public function __construct(
		private readonly string $sourceName,
		private array $pages,
	) {
		$this->sourceStatus = AtprotoSourceStatus::live($sourceName);
	}

	public function name(): string
	{
		return $this->sourceName;
	}

	public function pull(?string $cursor, int $limit): AtprotoEventPage
	{
		$this->pulls[] = ['cursor' => $cursor, 'limit' => $limit];
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return array_shift($this->pages) ?? new AtprotoEventPage([], null);
	}

	public function status(): AtprotoSourceStatus
	{
		return $this->sourceStatus;
	}

	/**
	 * @param list<AtprotoEventPage> $pages
	 */
	public function append(array $pages): void
	{
		foreach ($pages as $page) {
			$this->pages[] = $page;
		}
	}
}
