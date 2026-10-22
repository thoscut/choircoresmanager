import type {
	CatalogueExport,
	CatalogueImportResult,
	CatalogueValue,
} from '@/api/generated/openapi/data-contracts'

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiClients } from '@/api/client'
import { useScoresStore } from '@/stores/scoresStore'

/**
 * Pinia store for the work catalogue (Werkverzeichnis): CSV import and export,
 * and the catalogue values of a score that are not stored in a field.
 */
export const useCatalogueStore = defineStore('catalogue', () => {
	const scoresStore = useScoresStore()

	/** Catalogue values per score ID, loaded on demand */
	const valuesByScore = ref<Map<number, CatalogueValue[]>>(new Map())

	/**
	 * Analyse a catalogue file without saving anything (dry run)
	 *
	 * @param csv - The content of the CSV file
	 * @return Counts and changes per row
	 * @throws { Error } if API call fails or the file cannot be read
	 */
	async function previewImport(csv: string): Promise<CatalogueImportResult> {
		const response = await apiClients.default.catalogueApiImportCatalogue({ csv, dryRun: true })
		return response.data.ocs.data
	}

	/**
	 * Import a catalogue file and reload the scores
	 *
	 * @param csv - The content of the CSV file
	 * @return Counts and changes per row as applied
	 * @throws { Error } if API call fails or the file cannot be read
	 */
	async function applyImport(csv: string): Promise<CatalogueImportResult> {
		const response = await apiClients.default.catalogueApiImportCatalogue({ csv, dryRun: false })
		valuesByScore.value = new Map()
		await scoresStore.reload()
		return response.data.ocs.data
	}

	/**
	 * Export all scores in the catalogue format
	 *
	 * @return File name and CSV content
	 * @throws { Error } if API call fails
	 */
	async function exportCatalogue(): Promise<CatalogueExport> {
		const response = await apiClients.default.catalogueApiExportCatalogue()
		return response.data.ocs.data
	}

	/**
	 * Get the catalogue values of a score that are not stored in a field
	 *
	 * @param scoreId - The score ID
	 * @param force - Reload even if the values are cached
	 * @return Column names and values in catalogue order
	 * @throws { Error } if API call fails
	 */
	async function getScoreValues(scoreId: number, force = false): Promise<CatalogueValue[]> {
		const cached = valuesByScore.value.get(scoreId)
		if (cached && !force) {
			return cached
		}
		const response = await apiClients.default.catalogueApiGetScoreCatalogueValues(scoreId)
		const values = response.data.ocs.data
		valuesByScore.value.set(scoreId, values)
		return values
	}

	return {
		previewImport,
		applyImport,
		exportCatalogue,
		getScoreValues,
	}
})
