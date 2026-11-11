<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\InstanceInfo;

/**
 * The public description of this instance.
 */
final class InstanceHandler implements Handler
{
	public function __construct(
		private readonly InstanceInfo $instance,
	) {
	}

	public function handle(Request $request): Response
	{
		return Response::json(['instance' => $this->instance->describe()]);
	}
}
