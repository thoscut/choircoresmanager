/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Files of the repository read as text by the unit tests (Vite's "?raw").
declare module '*.md?raw' {
	const text: string
	export default text
}

declare module '*.xml?raw' {
	const text: string
	export default text
}

declare module '*.php?raw' {
	const text: string
	export default text
}

// Vite's glob import: the files of the repository that match, by path.
interface ImportMeta {
	glob(pattern: string | string[], options?: { query?: string, import?: string, eager?: boolean }): Record<string, unknown>
}
