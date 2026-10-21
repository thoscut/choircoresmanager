<template>
	<NcSettingsSection :name="t('Group Permissions')">
		<div class="group_selection">
			<p class="admin-settings__description">
				{{ t('Select the groups that are allowed to edit the orchestra scores table.') }}
			</p>

			<NcSettingsSelectGroup
				:label="t('Allowed Groups')"
				:modelValue="allowedGroups"
				:placeholder="t('Select groups…')"
				:multiple="true"
				:loading="loading"
				@update:modelValue="onGroupsChanged" />
		</div>
	</NcSettingsSection>
	<NcSettingsSection
		:name="t('Repertoire')"
		:description="t('The repertoire class of a score is derived from its last documented performance (setlists and imported catalogue). A score is current if it was performed in the current year or the given number of years before, dormant within the second number of years, and earlier otherwise. Scores without a documented performance are shown as not documented.')">
		<form class="repertoire-thresholds" @submit.prevent="onThresholdsSubmit">
			<NcInputField
				v-model="currentYears"
				type="number"
				min="0"
				:max="MAX_REPERTOIRE_YEARS - 1"
				:label="t('Years back for current repertoire')"
				:error="thresholdsError !== ''" />
			<NcInputField
				v-model="dormantYears"
				type="number"
				min="1"
				:max="MAX_REPERTOIRE_YEARS"
				:label="t('Years back for dormant repertoire')"
				:error="thresholdsError !== ''"
				:helperText="thresholdsError" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="thresholdsLoading || thresholdsError !== ''">
				{{ t('Save') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword, isPasswordConfirmationRequired, PwdConfirmationMode } from '@nextcloud/password-confirmation'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import { apiClients } from '@/api/client'
import { tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'

interface RepertoireThresholds {
	currentYears: number
	dormantYears: number
}

const MAX_REPERTOIRE_YEARS = 100

// Reactive data
const allowedGroups = ref<string[]>(loadState('orchestrascoresmanager', 'allowed_groups'))
const loading = ref(false)

const initialThresholds = loadState<RepertoireThresholds>('orchestrascoresmanager', 'repertoire_thresholds', { currentYears: 3, dormantYears: 10 })
const currentYears = ref<string | number>(initialThresholds.currentYears)
const dormantYears = ref<string | number>(initialThresholds.dormantYears)
const thresholdsLoading = ref(false)

const thresholdsError = computed((): string => {
	const current = Number(currentYears.value)
	const dormant = Number(dormantYears.value)
	if (currentYears.value === '' || dormantYears.value === '' || !Number.isInteger(current) || !Number.isInteger(dormant)) {
		return t('Please enter whole numbers.')
	}
	if (current < 0 || current >= dormant || dormant > MAX_REPERTOIRE_YEARS) {
		return t('The first number must be at least 0 and smaller than the second, which must be at most {max}.', { max: MAX_REPERTOIRE_YEARS })
	}
	return ''
})

/**
 * Save the repertoire thresholds
 */
async function onThresholdsSubmit() {
	if (thresholdsError.value !== '') {
		return
	}
	thresholdsLoading.value = true
	await tryShowError(
		async () => {
			if (isPasswordConfirmationRequired(PwdConfirmationMode.Lax)) {
				await confirmPassword()
			}
			const response = await apiClients.admin.adminPostRepertoireThresholds({
				currentYears: Number(currentYears.value),
				dormantYears: Number(dormantYears.value),
			})
			currentYears.value = response.data.ocs.data.currentYears
			dormantYears.value = response.data.ocs.data.dormantYears
			showSuccess(t('Settings updated successfully.'))
		},
		t('Failed to update settings.') + ' ',
	)
	thresholdsLoading.value = false
}

// Handle group selection changes
async function onGroupsChanged(newGroups: string[]) {
	loading.value = true
	try {
		// Check if password confirmation is required
		if (isPasswordConfirmationRequired(PwdConfirmationMode.Lax)) {
			await confirmPassword()
		}

		const response = await apiClients.admin.adminPostEditGroups({ editGroups: newGroups })

		// Update local state
		allowedGroups.value = response.data.ocs.data.editGroups

		// Show success message
		showSuccess(t('Settings updated successfully.'))
	} catch (err) {
		console.error('Failed to update settings:', err)
		showError(t('Failed to update settings.'))
	} finally {
		loading.value = false
	}
}
</script>

<style lang="scss" scoped>
.group_selection {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline)
}

.repertoire-thresholds {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
	max-width: 400px;
}
</style>
