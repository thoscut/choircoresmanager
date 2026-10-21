import type { ColDef } from 'ag-grid-community'

import { formatDateStr } from '../composables/useDateFormatting.ts'
import { t } from './l10n.ts'

export type InventoryStatus = 'active' | 'retired' | 'missing' | 'lent'

export const INVENTORY_STATUSES: InventoryStatus[] = ['active', 'retired', 'missing', 'lent']

/**
 * Get the translated label of an inventory status
 * @param status - The inventory status or null
 * @return Translated label, empty string for null
 */
export function inventoryStatusLabel(status: string | null | undefined): string {
	switch (status) {
		case 'active':
			return t('Active')
		case 'retired':
			return t('Retired')
		case 'missing':
			return t('Missing')
		case 'lent':
			return t('Lent')
		default:
			return ''
	}
}

/**
 * Get the translated label of a derived repertoire class
 * @param repertoireClass - The repertoire class
 * @return Translated label
 */
export function repertoireClassLabel(repertoireClass: string | null | undefined): string {
	switch (repertoireClass) {
		case 'current':
			return t('Current')
		case 'dormant':
			return t('Dormant')
		case 'former':
			return t('Former')
		default:
			return t('Not performed')
	}
}

/**
 * Split a signature like "5-22" or "10-3a" into comparable parts.
 * Numeric parts are compared numerically, everything else as text.
 * @param signature - The signature to split
 */
function signatureParts(signature: string): (number | string)[] {
	return signature
		.split(/[-./\s]+/)
		.filter((part) => part !== '')
		.flatMap((part) => part.match(/\d+|\D+/g) ?? [])
		.map((part) => (/^\d+$/.test(part) ? Number(part) : part.toLowerCase()))
}

/**
 * Compare two signatures in natural order, so that "5-9" sorts before "5-10" and "10-1"
 * @param valueA - First signature
 * @param valueB - Second signature
 */
export function compareSignatures(valueA: unknown, valueB: unknown): number {
	const a = valueA === null || valueA === undefined || valueA === '' ? null : String(valueA)
	const b = valueB === null || valueB === undefined || valueB === '' ? null : String(valueB)
	if (a === null || b === null) {
		// empty signatures go last
		return a === b ? 0 : (a === null ? 1 : -1)
	}

	const partsA = signatureParts(a)
	const partsB = signatureParts(b)
	for (let i = 0; i < Math.min(partsA.length, partsB.length); i++) {
		const pa = partsA[i]
		const pb = partsB[i]
		if (pa === pb) {
			continue
		}
		if (typeof pa === 'number' && typeof pb === 'number') {
			return pa - pb
		}
		return String(pa).localeCompare(String(pb))
	}
	return partsA.length - partsB.length
}

/**
 * Create the column definitions for the repertoire fields of a score
 * @return Column definitions for ag-grid
 */
export function createRepertoireColumns(): ColDef[] {
	return [
		{
			field: 'signature',
			headerName: t('Signature'),
			comparator: compareSignatures,
		},
		{
			field: 'repertoireClass',
			headerName: t('Repertoire'),
			editable: false,
			headerTooltip: t('Derived from the performances in setlists'),
			refData: {
				current: repertoireClassLabel('current'),
				dormant: repertoireClassLabel('dormant'),
				former: repertoireClassLabel('former'),
				none: repertoireClassLabel('none'),
			},
		},
		{
			field: 'lastPerformed',
			headerName: t('Last performed'),
			editable: false,
			filter: 'agDateColumnFilter',
			valueGetter: (params) => {
				const value = (params.data as { lastPerformed?: string | null } | undefined)?.lastPerformed
				return value ? new Date(value) : null
			},
			valueFormatter: (params) => {
				const value = params.value as Date | null
				if (!value) {
					return ''
				}
				const pad = (n: number) => String(n).padStart(2, '0')
				return formatDateStr(`${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`)
			},
		},
		{
			field: 'inventoryStatus',
			headerName: t('Inventory'),
			cellEditor: 'agSelectCellEditor',
			cellEditorParams: { values: ['', ...INVENTORY_STATUSES] },
			refData: {
				'': '',
				active: inventoryStatusLabel('active'),
				retired: inventoryStatusLabel('retired'),
				missing: inventoryStatusLabel('missing'),
				lent: inventoryStatusLabel('lent'),
			},
		},
		{ field: 'lyricist', headerName: t('Lyricist') },
		{ field: 'voicing', headerName: t('Voicing') },
		{ field: 'language', headerName: t('Language') },
	]
}
