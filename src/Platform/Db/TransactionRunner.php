<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Db;

/**
 * Runs a unit of work in one database transaction.
 *
 * Services depend on this instead of the full {@see Connection}: a post write
 * needs a transaction boundary around several repositories, but it does not
 * need to execute SQL itself.
 *
 * A callable that returns normally commits when it is the outermost
 * transaction. An exception at any depth rolls the whole chain back.
 */
interface TransactionRunner
{
	public function transaction(callable $work): mixed;
}
