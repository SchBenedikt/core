/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import {
	createAlohaUi,
	FakeHostBridge,
	useHostBridge,
	type HostBridge,
} from '../src/index.js';

const BridgeReader = defineComponent({
	setup() {
		const bridge = useHostBridge();

		return { apiBase: bridge.apiBase };
	},
	template: '<p>{{ apiBase }}</p>',
});

describe('HostBridge plugin', () => {
	it('provides the same bridge through dependency injection', () => {
		const bridge = new FakeHostBridge({ apiBase: '/apps/social/federation' });
		const wrapper = mount(BridgeReader, {
			global: { plugins: [createAlohaUi({ bridge })] },
		});

		expect(wrapper.text()).toBe('/apps/social/federation');
	});

	it('fails fast when no bridge was provided', () => {
		const warning = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

		expect(() => {
			mount(BridgeReader);
		}).toThrow('No Aloha Social HostBridge was provided');

		warning.mockRestore();
	});

	it('rejects a missing bridge at plugin creation', () => {
		expect(() => createAlohaUi({ bridge: undefined as unknown as HostBridge })).toThrow(
			'createAlohaUi() needs a HostBridge.',
		);
	});
});

describe('FakeHostBridge', () => {
	it('records requests and answers by path without a network', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			capabilities: { media: true, atproto: false },
			responses: {
				'GET /api/v1/timelines/public': { status: 200, body: '{"posts":[]}' },
			},
		});

		const response = await bridge.request({
			method: 'GET',
			path: '/api/v1/timelines/public',
			query: { limit: '20', before: '2026-11-11T10:00:00Z' },
		});

		expect(response.status).toBe(200);
		expect(response.body).toBe('{"posts":[]}');
		expect(await bridge.currentUser()).toEqual({ id: 'alice', handle: 'alice' });
		expect(bridge.capabilities()).toEqual({ media: true, atproto: false });
		expect(bridge.calls).toHaveLength(1);
		expect(bridge.calls[0]?.query).toEqual({ limit: '20', before: '2026-11-11T10:00:00Z' });
	});

	it('records navigation, login and logout calls', async () => {
		const bridge = new FakeHostBridge();

		bridge.navigate('/@alice');
		bridge.loginUrl('/@alice');
		await bridge.logout();

		expect(bridge.navigations).toEqual(['/@alice']);
		expect(bridge.loginUrls).toEqual(['/@alice']);
		expect(bridge.logoutCalls).toBe(1);
	});

	it('fails loudly when a test forgot a response', async () => {
		const bridge = new FakeHostBridge();

		await expect(bridge.request({ method: 'GET', path: '/api/v1/missing' })).rejects.toThrow(
			'FakeHostBridge has no response for GET /api/v1/missing.',
		);
	});
});
