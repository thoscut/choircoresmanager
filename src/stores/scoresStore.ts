import type { Score } from '@/api/generated/openapi/data-contracts'

import ncAxios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateOcsUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { apiClients } from '@/api/client'
import { useScoreBooksStore } from '@/stores/scoreBooksStore'
import { useScoreSidebarStore } from '@/stores/scoreSidebarStore'

/**
 * Pinia store for managing scores state.
 *
 * All CRUD operations are centralized here. Components should use store
 * methods instead of calling the API directly. This ensures consistent
 * state management and reactive updates across the application.
 */
export const useScoresStore = defineStore('scores', () => {
	const scores = ref<Score[]>([])
	const isLoaded = ref(false)
	const isLoading = ref(false)
	const scoreBooksStore = useScoreBooksStore()
	const scoreSidebarStore = useScoreSidebarStore()

	/**
	 * Get scores sorted by title
	 */
	const scoresSorted = computed(() => {
		return [...scores.value].sort((a, b) => a.title.localeCompare(b.title))
	})

	/**
	 * Initialize the store by loading data from initial state or API
	 */
	async function initialize(): Promise<void> {
		if (isLoaded.value || isLoading.value) {
			return
		}

		isLoading.value = true

		try {
			// Try to load from initial state first
			const initialData = loadState<Score[] | null>('orchestrascoresmanager', 'scores', null)
			if (initialData && initialData.length > 0) {
				scores.value = initialData
				isLoaded.value = true
				return
			}

			// Fallback to API if initial state is missing or empty
			const response = await apiClients.default.scoreApiGetScores()
			scores.value = response.data.ocs.data || []
			isLoaded.value = true
		} catch (error) {
			console.error('Failed to load scores:', error)
			scores.value = []
		} finally {
			isLoading.value = false
		}
	}

	/**
	 * Get a specific score by ID
	 *
	 * @param id - The score ID
	 */
	function getScoreById(id: number): Score | undefined {
		return scores.value.find((s) => s.id === id)
	}

	/**
	 * Create a new score via API and add it to the store
	 *
	 * @param title - The title of the new score
	 * @return The created score
	 * @throws { Error } if API call fails
	 */
	async function createScore(title: string): Promise<Score> {
		const response = await apiClients.default.scoreApiPostScore({ title })
		const created = response.data.ocs.data as Score
		scores.value.unshift(created)
		return created
	}

	/**
	 * Update a score field via API and update local state
	 *
	 * @param id - The score ID
	 * @param field - The field to update
	 * @param value - The new value
	 * @throws { Error } if API call fails (local state is reverted)
	 */
	async function updateScoreFieldApi(id: number, field: string, value: unknown): Promise<void> {
		const index = scores.value.findIndex((s) => s.id === id)
		if (index === -1) {
			return
		}

		const oldValue = scores.value[index][field as keyof Score]

		// Optimistically update local state
		scores.value[index] = { ...scores.value[index], [field]: value }

		try {
			const response = await apiClients.default.scoreApiPatchScore(id, { [field]: value })
			// The server may change dependent fields, e.g. clear the rights confirmation when the rights status changes
			replaceScore(response.data.ocs.data as Score)
		} catch (error) {
			// Revert on error
			scores.value[index] = { ...scores.value[index], [field]: oldValue }
			throw error
		}
	}

	/**
	 * Update the scoreBook assignment for a score (local state only)
	 *
	 * @param id - The score ID
	 * @param scoreBook - The new scoreBook object or null to remove
	 */
	function updateScoreBookAssignment(id: number, scoreBook: { id: number, index: number } | null): void {
		const index = scores.value.findIndex((s) => s.id === id)
		if (index !== -1) {
			scores.value[index] = { ...scores.value[index], scoreBook }
		}

		if (scoreBook) {
			scoreBooksStore.updateScoreBookScoreIndex(scoreBook.id, id, scoreBook.index)
		}
	}

	/**
	 * Update the scoreBook assignment for a score via API
	 *
	 * @param id - The score ID
	 * @param scoreBook - The new scoreBook object or null to remove
	 * @throws { Error } if API call fails
	 */
	async function updateScoreBookAssignmentApi(id: number, scoreBook: { id: number, index: number } | null): Promise<void> {
		await apiClients.default.scoreApiPatchScore(id, { scoreBook })
		updateScoreBookAssignment(id, scoreBook)
	}

	/**
	 * Update tags for a score via API
	 *
	 * @param id - The score ID
	 * @param tagIds - Array of tag IDs to set
	 * @throws { Error } if API call fails
	 */
	async function updateScoreTags(id: number, tagIds: number[]): Promise<void> {
		await apiClients.default.scoreApiPatchScore(id, { tagIds })
	}

	/**
	 * Replace a score in the store (and in the sidebar, if shown there) with an updated version from the API
	 *
	 * @param updated - The updated score
	 */
	function replaceScore(updated: Score): void {
		const index = scores.value.findIndex((s) => s.id === updated.id)
		if (index !== -1) {
			scores.value[index] = updated
		}
		scoreSidebarStore.updateSelectedScore(updated)
	}

	/**
	 * Link a MusicXML file from Nextcloud Files to a score and update local state
	 *
	 * @param id - The score ID
	 * @param fileId - The Nextcloud file id of the MusicXML file (.musicxml, .xml or .mxl)
	 * @return The updated score
	 * @throws { Error } if API call fails
	 */
	async function linkMusicXml(id: number, fileId: number): Promise<Score> {
		const response = await apiClients.default.musicXmlApiPostMusicXml(id, { fileId })
		const updated = response.data.ocs.data as Score
		replaceScore(updated)
		return updated
	}

	/**
	 * Remove the link to the MusicXML file of a score and update local state. The file stays in Files.
	 *
	 * @param id - The score ID
	 * @return The updated score
	 * @throws { Error } if API call fails
	 */
	async function unlinkMusicXml(id: number): Promise<Score> {
		const response = await apiClients.default.musicXmlApiDeleteMusicXml(id)
		const updated = response.data.ocs.data as Score
		replaceScore(updated)
		return updated
	}

	/**
	 * Download the content of the MusicXML file of a score
	 *
	 * @param id - The score ID
	 * @return The file content
	 * @throws { Error } if API call fails; an OCS error body is decoded so that its message can be shown
	 */
	async function fetchMusicXml(id: number): Promise<ArrayBuffer> {
		try {
			const response = await ncAxios.get(
				generateOcsUrl('/apps/orchestrascoresmanager/scores/{id}/musicxml', { id }),
				{ headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' }, responseType: 'arraybuffer' },
			)
			return response.data as ArrayBuffer
		} catch (error) {
			decodeArrayBufferErrorBody(error)
			throw error
		}
	}

	/**
	 * Replace the binary body of a failed request by the parsed JSON, if it is JSON
	 *
	 * @param error - The error thrown by axios
	 */
	function decodeArrayBufferErrorBody(error: unknown): void {
		const response = (error as { response?: { data?: unknown } }).response
		if (!response || !(response.data instanceof ArrayBuffer)) {
			return
		}
		try {
			response.data = JSON.parse(new TextDecoder().decode(response.data))
		} catch {
			// not JSON, keep the body as it is
		}
	}

	/**
	 * Delete a score via API and remove from store
	 *
	 * @param id - The score ID to remove
	 * @throws { Error } if API call fails
	 */
	async function deleteScore(id: number): Promise<void> {
		await apiClients.default.scoreApiDeleteScore(id)
		scores.value = scores.value.filter((s) => s.id !== id)
	}

	return {
		scores,
		scoresSorted,
		isLoaded,
		isLoading,
		initialize,
		getScoreById,
		createScore,
		updateScoreFieldApi,
		updateScoreBookAssignment,
		updateScoreBookAssignmentApi,
		updateScoreTags,
		replaceScore,
		linkMusicXml,
		unlinkMusicXml,
		fetchMusicXml,
		deleteScore,
	}
})
