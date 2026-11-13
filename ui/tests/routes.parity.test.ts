/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { execFileSync } from 'node:child_process';
import { coreRoutes } from '../src/index.js';

interface ManifestRoute {
	name: string;
	method: string;
	path: string;
	requires_auth: boolean;
}

interface Manifest {
	routes: ManifestRoute[];
}

function manifest(): Manifest {
	const output = execFileSync('php', ['../bin/export-routes'], {
		cwd: process.cwd(),
		encoding: 'utf8',
	});

	return JSON.parse(output) as Manifest;
}

describe('route parity', () => {
	it('uses the manifest path for the public timeline', () => {
		const route = manifest().routes.find((candidate) => candidate.name === 'timelines.public');

		expect(route).toBeDefined();
		expect(route?.method).toBe('GET');
		expect(route?.path).toBe(coreRoutes.publicTimeline);
		expect(route?.requires_auth).toBe(false);
	});

	it('uses the manifest path for a single post', () => {
		const route = manifest().routes.find((candidate) => candidate.name === 'posts.get');

		expect(route).toBeDefined();
		expect(route?.method).toBe('GET');
		expect(route?.path).toBe(coreRoutes.postPattern);
	});

	it('uses the manifest paths for profiles, follows and notifications', () => {
		const routes = manifest().routes;
		const expected: Array<[string, string, string]> = [
			['accounts.show', 'GET', coreRoutes.accountPattern],
			['accounts.follow', 'POST', coreRoutes.accountFollowPattern],
			['accounts.unfollow', 'DELETE', coreRoutes.accountFollowPattern],
			['notifications.list', 'GET', coreRoutes.notifications],
			['notifications.unread_count', 'GET', coreRoutes.notificationsUnreadCount],
			['notifications.dismiss', 'POST', coreRoutes.notificationDismissPattern],
			['notifications.clear', 'POST', coreRoutes.notificationsClear],
			['posts.create', 'POST', coreRoutes.posts],
		];

		for (const [name, method, path] of expected) {
			const route = routes.find((candidate) => candidate.name === name);
			expect(route, name).toBeDefined();
			expect(route?.method, name).toBe(method);
			expect(route?.path, name).toBe(path);
		}
	});
});
