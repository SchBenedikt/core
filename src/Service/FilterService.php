<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\FilterMatch;
use AlohaSocial\Core\Domain\Filter\FilterNotFoundException;
use AlohaSocial\Core\Domain\Filter\FilterRule;
use AlohaSocial\Core\Domain\Filter\FilterRuleRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_map;
use function class_exists;
use function is_array;
use function mb_stripos;
use function mb_strtolower;
use function preg_match;
use function preg_quote;
use function trim;

/**
 * Per-reader keyword filters.
 *
 * The matching follows the reader's intent rather than a byte comparison:
 * both sides are compatibility-normalized and case-folded, and whole-word
 * matching uses letter/number boundaries. A `hide` rule wins over a `warn`
 * rule in the same context; within one action the newest rule wins, so a
 * reader can add a broader rule later without editing the older one.
 *
 * Filtering runs after visibility and block decisions and before projection.
 * It never changes stored state or another reader's view.
 */
final class FilterService
{
	public function __construct(
		private readonly FilterRuleRepository $rules,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	/**
	 * @return list<FilterRule>
	 */
	public function forUser(string $userId): array
	{
		return $this->rules->forUser($userId);
	}

	/**
	 * @throws FilterNotFoundException
	 */
	public function findOwned(string $userId, string $id): FilterRule
	{
		$rule = $this->rules->find($id);
		if ($rule === null || $rule->userId !== $userId) {
			throw new FilterNotFoundException($id);
		}

		return $rule;
	}

	/**
	 * The rules that still apply in one context, newest first.
	 *
	 * @return list<FilterRule>
	 */
	public function activeFor(string $userId, FilterContext $context): array
	{
		$now = $this->now();
		$active = [];
		foreach ($this->rules->forUser($userId) as $rule) {
			if ($rule->isExpired($now) || !$rule->appliesTo($context)) {
				continue;
			}

			$active[] = $rule;
		}

		return $active;
	}

	/**
	 * @param list<FilterContext> $contexts
	 * @param list<FilterKeyword> $keywords
	 *
	 * @throws ValidationException
	 */
	public function create(
		string $userId,
		string $name,
		FilterAction $action,
		array $contexts,
		array $keywords,
		?DateTimeImmutable $expiresAt = null,
	): FilterRule {
		$name = $this->validateName($name);
		$this->validateContexts($contexts);
		$this->validateKeywords($keywords);

		if ($expiresAt !== null && $expiresAt <= $this->now()) {
			throw new ValidationException(['expires_at' => 'A filter expiry must be in the future.']);
		}

		if ($this->rules->countForUser($userId) >= FilterRule::MAX_RULES_PER_USER) {
			throw new ValidationException(['name' => 'This account has reached its filter limit.']);
		}

		$rule = new FilterRule(
			id: $this->ids->generate(),
			userId: $userId,
			name: $name,
			action: $action,
			contexts: $contexts,
			keywords: $keywords,
			createdAt: $this->now(),
			expiresAt: $expiresAt === null ? null : Time::toSecondPrecision($expiresAt),
		);

		$this->rules->insert($rule);

		return $rule;
	}

	/**
	 * Replaces the rule's editable fields. The owner and creation time stay.
	 *
	 * @param list<FilterContext> $contexts
	 * @param list<FilterKeyword> $keywords
	 *
	 * @throws FilterNotFoundException
	 * @throws ValidationException
	 */
	public function update(
		string $userId,
		string $id,
		string $name,
		FilterAction $action,
		array $contexts,
		array $keywords,
		?DateTimeImmutable $expiresAt = null,
	): FilterRule {
		$existing = $this->findOwned($userId, $id);
		$name = $this->validateName($name);
		$this->validateContexts($contexts);
		$this->validateKeywords($keywords);

		$expiresAt = $expiresAt === null ? null : Time::toSecondPrecision($expiresAt);
		if ($expiresAt !== null && $expiresAt <= $existing->createdAt) {
			throw new ValidationException(['expires_at' => 'A filter expiry must be after its creation.']);
		}

		$updated = new FilterRule(
			id: $existing->id,
			userId: $existing->userId,
			name: $name,
			action: $action,
			contexts: $contexts,
			keywords: $keywords,
			createdAt: $existing->createdAt,
			expiresAt: $expiresAt,
		);

		$this->rules->update($updated);

		return $updated;
	}

	/**
	 * @throws FilterNotFoundException
	 */
	public function delete(string $userId, string $id): void
	{
		if (!$this->rules->delete($id, $userId)) {
			throw new FilterNotFoundException($id);
		}
	}

	/**
	 * The first rule that matches any of the texts, honouring hide-over-warn.
	 *
	 * @param list<FilterRule> $rules
	 * @param list<string>     $texts
	 */
	public function matchTexts(array $rules, FilterContext $context, array $texts): ?FilterMatch
	{
		$normalizedTexts = [];
		foreach ($texts as $text) {
			if (!is_string($text)) {
				continue;
			}

			$normalized = self::normalize($text);
			if ($normalized !== '') {
				$normalizedTexts[] = $normalized;
			}
		}

		if ($normalizedTexts === []) {
			return null;
		}

		foreach ([FilterAction::Hide, FilterAction::Warn] as $action) {
			foreach ($rules as $rule) {
				if ($rule->action !== $action || !$rule->appliesTo($context)) {
					continue;
				}

				foreach ($rule->keywords as $keyword) {
					foreach ($normalizedTexts as $text) {
						if ($this->keywordMatches($keyword, $text)) {
							return new FilterMatch(
								$rule->id,
								$rule->name,
								$rule->action,
								$keyword->text,
								$context,
							);
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * @param list<string> $altTexts
	 */
	public function matchPost(
		string $userId,
		FilterContext $context,
		Post $post,
		array $altTexts = [],
	): ?FilterMatch {
		$rules = $this->activeFor($userId, $context);
		if ($rules === []) {
			return null;
		}

		$texts = [$post->content, $post->contentWarning ?? ''];
		foreach ($post->poll?->options ?? [] as $option) {
			$texts[] = $option->title;
		}

		foreach ($altTexts as $altText) {
			$texts[] = $altText;
		}

		return $this->matchTexts($rules, $context, $texts);
	}

	public function matchEntry(string $userId, FilterContext $context, TimelineEntry $entry): ?FilterMatch
	{
		$rules = $this->activeFor($userId, $context);
		if ($rules === []) {
			return null;
		}

		return $this->matchTexts($rules, $context, [$entry->content, $entry->contentWarning ?? '']);
	}

	/**
	 * The `filtered` element a warned object carries.
	 *
	 * @return array<string, mixed>
	 */
	public function filterBlock(FilterMatch $match): array
	{
		return $match->toArray();
	}

	/**
	 * Removes the content a warned object must not show before the reader asks
	 * for it. Metadata stays so the client can render the warning and the
	 * reader can still decide to reveal the object.
	 *
	 * @param array<string, mixed> $presented
	 *
	 * @return array<string, mixed>
	 */
	public function redact(array $presented): array
	{
		$presented['content'] = '';
		$presented['content_warning'] = null;
		$presented['poll'] = null;

		if (isset($presented['attachments']) && is_array($presented['attachments'])) {
			$attachments = [];
			foreach ($presented['attachments'] as $attachment) {
				if (is_array($attachment)) {
					$attachment['alt_text'] = null;
				}

				$attachments[] = $attachment;
			}

			$presented['attachments'] = $attachments;
		}

		return $presented;
	}

	/**
	 * The comparison form of any text: compatibility-normalized and
	 * case-folded. Kept static so callers and the keyword use one rule.
	 */
	public static function normalize(string $text): string
	{
		if (class_exists(\Normalizer::class)) {
			$normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
			if (is_string($normalized)) {
				$text = $normalized;
			}
		}

		return trim(mb_strtolower($text, 'UTF-8'));
	}

	private function keywordMatches(FilterKeyword $keyword, string $normalizedText): bool
	{
		$needle = $keyword->normalized();
		if ($needle === '') {
			return false;
		}

		if (!$keyword->wholeWord) {
			return mb_stripos($normalizedText, $needle) !== false;
		}

		return preg_match(
			'/(?<![\p{L}\p{N}_])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}_])/u',
			$normalizedText,
		) === 1;
	}

	/**
	 * @throws ValidationException
	 */
	private function validateName(string $name): string
	{
		$name = trim($name);
		if ($name === '') {
			throw new ValidationException(['name' => 'A filter needs a name.']);
		}

		if (mb_strlen($name) > FilterRule::MAX_NAME_LENGTH) {
			throw new ValidationException(['name' => 'The filter name is too long.']);
		}

		return $name;
	}

	/**
	 * @param list<FilterContext> $contexts
	 *
	 * @throws ValidationException
	 */
	private function validateContexts(array $contexts): void
	{
		if ($contexts === []) {
			throw new ValidationException(['contexts' => 'A filter needs at least one context.']);
		}

		$seen = [];
		foreach ($contexts as $context) {
			if (!$context instanceof FilterContext) {
				throw new ValidationException(['contexts' => 'Unknown filter context.']);
			}

			if (isset($seen[$context->value])) {
				throw new ValidationException(['contexts' => 'A filter cannot repeat a context.']);
			}

			$seen[$context->value] = true;
		}
	}

	/**
	 * @param list<FilterKeyword> $keywords
	 *
	 * @throws ValidationException
	 */
	private function validateKeywords(array $keywords): void
	{
		if ($keywords === []) {
			throw new ValidationException(['keywords' => 'A filter needs at least one keyword.']);
		}

		if (count($keywords) > FilterRule::MAX_KEYWORDS) {
			throw new ValidationException(['keywords' => 'A filter has too many keywords.']);
		}

		$seen = [];
		foreach ($keywords as $keyword) {
			if (!$keyword instanceof FilterKeyword) {
				throw new ValidationException(['keywords' => 'Unknown filter keyword.']);
			}

			$normalized = $keyword->normalized();
			if (isset($seen[$normalized])) {
				throw new ValidationException(['keywords' => 'A filter cannot repeat a keyword.']);
			}

			$seen[$normalized] = true;
		}
	}

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision($this->clock->now());
	}
}
