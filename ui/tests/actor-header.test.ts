/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
	ActorHeader,
	createAlohaUi,
	FakeHostBridge,
} from '../src/index.js';
import {
	account,
	accountResponse,
	errorResponse,
	relationship,
	relationshipResponse,
} from './support.js';

function mountHeader(bridge: FakeHostBridge, accountId = 'bob') {
	return mount(ActorHeader, {
		props: { accountId },
		global: { plugins: [createAlohaUi({ bridge })] },
	});
}

describe('ActorHeader', () => {
	it('loads a profile and toggles the follow edge', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/accounts/bob': accountResponse(account('bob', {
					followers_count: 3,
					following_count: 5,
				})),
				'POST /api/v1/accounts/bob/follow': relationshipResponse(relationship({ following: true })),
				'DELETE /api/v1/accounts/bob/follow': relationshipResponse(relationship({ following: false })),
			},
		});
		const wrapper = mountHeader(bridge);

		expect(wrapper.get('[role="status"]').text()).toContain('Loading profile');
		await flushPromises();

		expect(wrapper.text()).toContain('@bob');
		expect(wrapper.text()).toContain('3 followers');
		expect(wrapper.text()).toContain('5 following');
		expect(wrapper.get('button').text()).toBe('Follow');
		expect(wrapper.get('button').attributes('aria-pressed')).toBe('false');

		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(bridge.calls[1]).toMatchObject({
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
		});
		expect(wrapper.get('button').text()).toBe('Unfollow');
		expect(wrapper.get('button').attributes('aria-pressed')).toBe('true');

		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(bridge.calls[2]).toMatchObject({
			method: 'DELETE',
			path: '/api/v1/accounts/bob/follow',
		});
		expect(wrapper.get('button').text()).toBe('Follow');
	});

	it('does not show a follow control without a viewer relationship', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/accounts/bob': accountResponse(account('bob', { relationship: null })),
			},
		});
		const wrapper = mountHeader(bridge);
		await flushPromises();

		expect(wrapper.text()).toContain('@bob');
		expect(wrapper.find('button').exists()).toBe(false);
	});

	it('shows a not-found alert and retries', async () => {
		let missing = true;
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/accounts/ghost': () => (missing
					? errorResponse(404, 'not_found', 'Account not found.')
					: accountResponse(account('ghost'))),
			},
		});
		const wrapper = mountHeader(bridge, 'ghost');
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('Account not found.');
		expect(wrapper.find('h2').exists()).toBe(false);

		missing = false;
		await wrapper.get('[role="alert"] button').trigger('click');
		await flushPromises();

		expect(wrapper.text()).toContain('@ghost');
		expect(wrapper.find('[role="alert"]').exists()).toBe(false);
	});

	it('reports a follow failure without losing the profile', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/accounts/bob': accountResponse(account('bob')),
				'POST /api/v1/accounts/bob/follow': errorResponse(
					422,
					'validation_failed',
					'An account cannot follow itself.',
				),
			},
		});
		const wrapper = mountHeader(bridge);
		await flushPromises();

		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('cannot follow itself');
		expect(wrapper.text()).toContain('@bob');
		expect(wrapper.get('button').text()).toBe('Follow');
	});

	it('reloads when the account id changes', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/accounts/bob': accountResponse(account('bob')),
				'GET /api/v1/accounts/carol': accountResponse(account('carol')),
			},
		});
		const wrapper = mountHeader(bridge);
		await flushPromises();
		expect(wrapper.text()).toContain('@bob');

		await wrapper.setProps({ accountId: 'carol' });
		await flushPromises();

		expect(wrapper.text()).toContain('@carol');
		expect(wrapper.text()).not.toContain('@bob');
		expect(bridge.calls.map((call) => call.path)).toEqual([
			'/api/v1/accounts/bob',
			'/api/v1/accounts/carol',
		]);
	});
});
