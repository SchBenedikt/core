<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * The version of the local API and its base path.
 *
 * Constants rather than literals in each route and handler: the version shows
 * up in the manifest, in the instance description and in every path, and
 * those must never disagree.
 */
final class ApiVersion
{
	public const CURRENT = 'v1';

	public const BASE_PATH = '/api/' . self::CURRENT;

	private function __construct()
	{
	}
}
