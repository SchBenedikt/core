<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Platform\Config\Config;

/**
 * What this instance tells the world about itself.
 *
 * Read from configuration on every call rather than cached at boot, so an
 * administrator renaming the instance does not need a restart.
 */
final class InstanceInfo
{
	public const CONFIG_APP = 'social';

	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describe(): array
	{
		return [
			'name' => $this->config->get(self::CONFIG_APP, 'instance.name', 'Aloha Social'),
			'description' => $this->config->get(self::CONFIG_APP, 'instance.description', ''),
			'version' => $this->config->get(self::CONFIG_APP, 'instance.version', '0.1.0'),
			'api' => ApiVersion::CURRENT,
			'federation' => $this->config->getBool(self::CONFIG_APP, 'federation.enabled', true),
		];
	}
}
