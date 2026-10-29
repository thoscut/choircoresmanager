/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: CC0-1.0
 */

module.exports = {
	extends: [
		// Vue 3 + TypeScript (also inside <script setup lang="ts">)
		'@nextcloud/eslint-config/vue3',
		// Accessibility of templates (WCAG 2.2 AA is the goal)
		'plugin:vuejs-accessibility/recommended',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
	overrides: [
		{
			// Unit tests use the test runner, a development dependency
			files: ['src/**/*.test.ts'],
			rules: {
				'n/no-unpublished-import': 'off',
			},
		},
	],
}
