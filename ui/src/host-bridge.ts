/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { UiCapabilities, UiRequest, UiResponse, UiUser } from './types.js';

/**
 * The one boundary between the shared UI and its host.
 *
 * Implementations live in the host repositories. The bridge owns the API
 * prefix, credentials, CSRF policy and navigation; components only see core
 * paths and the raw response.
 */
export interface HostBridge {
	/** Prefix under which the core HTTP contract is served, without a trailing slash. */
	readonly apiBase: string;

	currentUser(): Promise<UiUser | null>;

	/** Resolve a core path through the host's auth/CSRF/origin policy. */
	request(request: UiRequest): Promise<UiResponse>;

	loginUrl(returnTo: string): string;

	logout(): Promise<void>;

	navigate(path: string): void;

	capabilities(): UiCapabilities;
}
