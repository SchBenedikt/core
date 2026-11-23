<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A refused remote-poll operation with a stable reason and HTTP status.
 */
final class RemotePollException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		public readonly int $status,
		string $message,
	) {
		parent::__construct($message);
	}

	public static function notFound(): self
	{
		return new self('not_found', 404, 'Poll not found.');
	}

	public static function ended(): self
	{
		return new self('poll_ended', 422, 'This poll has ended.');
	}

	public static function alreadyVoted(): self
	{
		return new self('already_voted', 422, 'You have already voted in this poll.');
	}

	public static function invalidChoice(string $message = 'One of the choices is not part of this poll.'): self
	{
		return new self('invalid_choice', 422, $message);
	}

	public static function deliveryUnavailable(): self
	{
		return new self('delivery_unavailable', 503, 'This instance cannot reach the poll author.');
	}

	public static function blockedActor(): self
	{
		return new self('blocked_actor', 403, 'This remote actor has blocked your account.');
	}
}
