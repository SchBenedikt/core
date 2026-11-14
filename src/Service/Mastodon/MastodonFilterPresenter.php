<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\FilterRule;
use AlohaSocial\Core\Support\Time;

/**
 * The Mastodon v2 filter shape.
 *
 * Keywords have no own storage identity in the core, so the projection derives
 * a stable id from the rule and the position. It is only an identifier for the
 * client; updates replace the whole list.
 */
final class MastodonFilterPresenter
{
	/**
	 * @return array<string, mixed>
	 */
	public function present(FilterRule $rule): array
	{
		$keywords = [];
		foreach ($rule->keywords as $index => $keyword) {
			$keywords[] = $this->keyword($rule, $keyword, $index);
		}

		return [
			'id' => $rule->id,
			'title' => $rule->name,
			'context' => array_map(
				static fn (FilterContext $context): string => $context->value,
				$rule->contexts,
			),
			'expires_at' => $rule->expiresAt === null ? null : Time::format($rule->expiresAt),
			'filter_action' => $rule->action->value,
			'keywords' => $keywords,
			'statuses' => [],
		];
	}

	/**
	 * @param list<FilterRule> $rules
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentMany(array $rules): array
	{
		return array_map(fn (FilterRule $rule): array => $this->present($rule), $rules);
	}

	/**
	 * @return array{id: string, keyword: string, whole_word: bool}
	 */
	private function keyword(FilterRule $rule, FilterKeyword $keyword, int $index): array
	{
		return [
			'id' => $rule->id . ':' . $index,
			'keyword' => $keyword->text,
			'whole_word' => $keyword->wholeWord,
		];
	}
}
