/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge } from '../host-bridge.js';
import type { UiCapabilities, UiRequest, UiResponse, UiUser } from '../types.js';

export interface FakeResponse {
	status?: number;
	headers?: Record<string, string>;
	body?: string;
}

export type FakeResponder = FakeResponse | ((request: UiRequest) => FakeResponse | Promise<FakeResponse>);

export interface FakeHostBridgeOptions {
	user?: UiUser | null;
	responses?: Record<string, FakeResponder>;
	capabilities?: UiCapabilities;
	apiBase?: string;
}

/**
 * A bridge for tests and component previews. It never performs a network
 * request and records every call so a test can assert the exact core path.
 *
 * A response can be keyed by `METHOD path?query` or by `METHOD path`, or be a
 * function when the answer depends on the cursor or another request field.
 */
export class FakeHostBridge implements HostBridge {
	readonly apiBase: string;

	readonly calls: UiRequest[] = [];

	readonly loginUrls: string[] = [];

	readonly navigations: string[] = [];

	logoutCalls = 0;

	private readonly user: UiUser | null;

	private readonly capabilitiesValue: UiCapabilities;

	private readonly responses: Record<string, FakeResponder>;

	constructor(options: FakeHostBridgeOptions = {}) {
		this.apiBase = options.apiBase ?? '/api/v1';
		this.user = options.user ?? null;
		this.capabilitiesValue = options.capabilities ?? { media: false, atproto: false };
		this.responses = { ...(options.responses ?? {}) };
	}

	async currentUser(): Promise<UiUser | null> {
		return this.user;
	}

	async request(request: UiRequest): Promise<UiResponse> {
		const recorded: UiRequest = {
			method: request.method,
			path: request.path,
		};
		if (request.query !== undefined) {
			recorded.query = { ...request.query };
		}
		if (request.body !== undefined) {
			recorded.body = request.body;
		}
		this.calls.push(recorded);

		const responder = this.responses[fakeRequestKey(request)]
			?? this.responses[`${request.method} ${request.path}`];

		if (responder === undefined) {
			throw new Error(`FakeHostBridge has no response for ${request.method} ${request.path}.`);
		}

		const response = typeof responder === 'function' ? await responder(request) : responder;

		return {
			status: response.status ?? 200,
			headers: response.headers ?? {},
			body: response.body ?? '',
		};
	}

	loginUrl(returnTo: string): string {
		this.loginUrls.push(returnTo);

		return `/login?return_to=${encodeURIComponent(returnTo)}`;
	}

	async logout(): Promise<void> {
		++this.logoutCalls;
	}

	navigate(path: string): void {
		this.navigations.push(path);
	}

	capabilities(): UiCapabilities {
		return { ...this.capabilitiesValue };
	}

	setResponse(key: string, responder: FakeResponder): void {
		this.responses[key] = responder;
	}
}

export function fakeRequestKey(request: UiRequest): string {
	const query = request.query === undefined
		? ''
		: new URLSearchParams(
			Object.entries(request.query).sort(([left], [right]) => left.localeCompare(right)),
		).toString();

	return `${request.method} ${request.path}${query === '' ? '' : `?${query}`}`;
}
