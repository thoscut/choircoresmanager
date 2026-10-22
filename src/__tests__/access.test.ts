// @vitest-environment jsdom
import type { Meta } from '../types.ts'

import { afterEach, describe, expect, it } from 'vitest'
import { dueTarget } from '../dueTargets.ts'
import { canSeeProposals, isAdmin, meta } from '../store.ts'

/**
 * Meta data of a user with the given roles.
 *
 * @param admin - Nextcloud admin
 * @param roles - roles in the music archive
 */
function user(admin: boolean, roles: string[]): Meta {
	return {
		uid: 'u',
		admin,
		roles,
		roleLabels: {},
		fields: { work: {}, version: {}, copy: {} },
		licenseUses: {},
		personFields: {},
		contributionRoles: {},
		archive: { id: 0, path: '' },
	} as unknown as Meta
}

afterEach(() => {
	meta.value = null
})

describe('proposals inbox', () => {
	it('is not offered to an admin without a role (the server refuses it)', () => {
		meta.value = user(true, [])
		expect(isAdmin.value).toBe(true)
		expect(canSeeProposals.value).toBe(false)
	})

	it('is offered to the roles that may read proposals, also to admins with such a role', () => {
		for (const role of ['librarian', 'contributor', 'reader']) {
			meta.value = user(false, [role])
			expect(canSeeProposals.value, role).toBe(true)
		}
		meta.value = user(true, ['reader'])
		expect(canSeeProposals.value).toBe(true)
	})

	it('is not offered to roles without proposals', () => {
		for (const role of ['operations', 'conductor']) {
			meta.value = user(false, [role])
			expect(canSeeProposals.value, role).toBe(false)
		}
	})

	it('is where the start page card leads (hidden together with the inbox)', () => {
		expect(dueTarget('proposals').view).toBe('proposals')
	})
})
