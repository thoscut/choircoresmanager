import type { ColDef, ValueGetterParams } from 'ag-grid-community'
import type { Score } from '@/api/generated/openapi/data-contracts'

import { formatDateStr } from '../composables/useDateFormatting.ts'
import { t } from './l10n.ts'

export type InventoryStatus = Score['inventoryStatus']
export type RepertoireClass = Score['repertoireClass']

export const INVENTORY_STATUSES: InventoryStatus[] = ['active', 'retired', 'missing', 'lent']
export const REPERTOIRE_CLASSES: RepertoireClass[] = ['current', 'dormant', 'earlier', 'not_documented']

/**
 * Get the translated label of an inventory status
 * @param status - The inventory status
 * @return Translated label, the raw value for unknown statuses
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
			return status ?? ''
	}
}

/**
 * Get the translated label of a repertoire class
 * @param repertoireClass - The repertoire class
 * @return Translated label, the raw value for unknown classes
 */
export function repertoireClassLabel(repertoireClass: string | null | undefined): string {
	switch (repertoireClass) {
		case 'current':
			return t('Current')
		case 'dormant':
			return t('Dormant')
		case 'earlier':
			return t('Earlier')
		case 'not_documented':
			return t('Not documented')
		default:
			return repertoireClass ?? ''
	}
}

/**
 * Create a comparator that sorts values by their position in a fixed list
 * @param order - The values in sort order
 */
function compareByOrder(order: readonly string[]): (valueA: unknown, valueB: unknown) => number {
	return (valueA, valueB) => {
		const indexA = order.indexOf(String(valueA))
		const indexB = order.indexOf(String(valueB))
		return (indexA === -1 ? order.length : indexA) - (indexB === -1 ? order.length : indexB)
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
 * Parse a date string (Y-m-d) as a local date, avoiding timezone shifts
 * @param value - The date string
 * @return The date at local midnight, or null
 */
function parseLocalDate(value: string | null | undefined): Date | null {
	if (!value) {
		return null
	}
	const [year, month, day] = value.split('-').map(Number)
	return new Date(year, month - 1, day)
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
			field: 'inventoryStatus',
			headerName: t('Inventory status'),
			cellEditor: 'agSelectCellEditor',
			cellEditorParams: { values: INVENTORY_STATUSES },
			refData: Object.fromEntries(INVENTORY_STATUSES.map((status) => [status, inventoryStatusLabel(status)])),
			filterValueGetter: (params: ValueGetterParams) => inventoryStatusLabel((params.data as Score | undefined)?.inventoryStatus),
			comparator: compareByOrder(INVENTORY_STATUSES),
		},
		{
			field: 'repertoireClass',
			headerName: t('Repertoire'),
			editable: false,
			refData: Object.fromEntries(REPERTOIRE_CLASSES.map((repertoireClass) => [repertoireClass, repertoireClassLabel(repertoireClass)])),
			filterValueGetter: (params: ValueGetterParams) => repertoireClassLabel((params.data as Score | undefined)?.repertoireClass),
			comparator: compareByOrder(REPERTOIRE_CLASSES),
		},
		{
			field: 'lastPerformed',
			headerName: t('Last performed'),
			editable: false,
			filter: 'agDateColumnFilter',
			valueGetter: (params) => parseLocalDate((params.data as Score | undefined)?.lastPerformed),
			valueFormatter: (params) => {
				const value = (params.data as Score | undefined)?.lastPerformed
				return value ? formatDateStr(value) : ''
			},
		},
		{ field: 'lyricist', headerName: t('Lyricist') },
		{ field: 'voicing', headerName: t('Voicing') },
		{ field: 'language', headerName: t('Language') },
	]
}
