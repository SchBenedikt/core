<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use JsonException;
use PDOException;

use function array_map;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Keyword filters in SQL.
 *
 * Contexts and keywords are JSON columns: a rule is one atomic row, so an
 * update can never leave a rule with half of its keyword list. Order is kept
 * by the arrays themselves.
 */
final class PdoFilterRuleRepository implements FilterRuleRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(FilterRule $rule): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . FilterSchema::TABLE
				. ' (id, user_id, name, action, contexts, keywords, created_at, expires_at)'
				. ' VALUES (:id, :user_id, :name, :action, :contexts, :keywords, :created_at, :expires_at)',
				$this->parameters($rule),
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The filter could not be stored.', 0, $exception);
		}
	}

	public function update(FilterRule $rule): void
	{
		$parameters = $this->parameters($rule);
		// The creation time is immutable, so its bound value is not part of
		// this statement.
		unset($parameters['created_at']);

		$this->db->execute(
			'UPDATE ' . FilterSchema::TABLE
			. ' SET name = :name, action = :action, contexts = :contexts, keywords = :keywords,'
			. ' expires_at = :expires_at WHERE id = :id AND user_id = :user_id',
			$parameters,
		);
	}

	public function find(string $id): ?FilterRule
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . FilterSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function delete(string $id, string $userId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FilterSchema::TABLE . ' WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		) > 0;
	}

	public function forUser(string $userId): array
	{
		return array_map(
			fn (array $row): FilterRule => $this->hydrate($row),
			$this->db->fetchAll(
				'SELECT * FROM ' . FilterSchema::TABLE
				. ' WHERE user_id = :user_id ORDER BY created_at DESC, id DESC',
				['user_id' => $userId],
			),
		);
	}

	public function countForUser(string $userId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FilterSchema::TABLE . ' WHERE user_id = :user_id',
			['user_id' => $userId],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(FilterRule $rule): array
	{
		return [
			'id' => $rule->id,
			'user_id' => $rule->userId,
			'name' => $rule->name,
			'action' => $rule->action->value,
			'contexts' => $this->encode(array_map(
				static fn (FilterContext $context): string => $context->value,
				$rule->contexts,
			)),
			'keywords' => $this->encode(array_map(
				static fn (FilterKeyword $keyword): array => $keyword->toArray(),
				$rule->keywords,
			)),
			'created_at' => Time::format($rule->createdAt),
			'expires_at' => $rule->expiresAt === null ? null : Time::format($rule->expiresAt),
		];
	}

	private function encode(array $value): string
	{
		try {
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException) {
			return '[]';
		}
	}

	/**
	 * @return list<mixed>
	 */
	private function decode(string $encoded): array
	{
		try {
			$decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		return is_array($decoded) ? array_values($decoded) : [];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): FilterRule
	{
		$contexts = [];
		foreach ($this->decode((string) $row['contexts']) as $context) {
			if (is_string($context)) {
				$contexts[] = FilterContext::fromString($context);
			}
		}

		$keywords = [];
		foreach ($this->decode((string) $row['keywords']) as $keyword) {
			if (!is_array($keyword) || !isset($keyword['text']) || !is_string($keyword['text'])) {
				continue;
			}

			$keywords[] = new FilterKeyword($keyword['text'], (bool) ($keyword['whole_word'] ?? false));
		}

		return new FilterRule(
			id: (string) $row['id'],
			userId: (string) $row['user_id'],
			name: (string) $row['name'],
			action: FilterAction::fromString((string) $row['action']),
			contexts: $contexts,
			keywords: $keywords,
			createdAt: Time::parse((string) $row['created_at']),
			expiresAt: $row['expires_at'] === null ? null : Time::parse((string) $row['expires_at']),
		);
	}
}
