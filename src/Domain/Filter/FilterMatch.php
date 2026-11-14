<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

/**
 * One rule that matched, with the evidence a client needs to explain it.
 *
 * The keyword is returned because a `warn` surface must say *why* it redacted
 * the content, without the reader having to fetch and re-run the rule.
 */
final readonly class FilterMatch
{
	public function __construct(
		public string $ruleId,
		public string $ruleName,
		public FilterAction $action,
		public string $keyword,
		public FilterContext $context,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'filter_id' => $this->ruleId,
			'name' => $this->ruleName,
			'action' => $this->action->value,
			'keyword' => $this->keyword,
			'context' => $this->context->value,
		];
	}
}
