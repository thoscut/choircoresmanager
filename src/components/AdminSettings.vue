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
		:description="t('A score counts as current or dormant repertoire if it was performed within the given number of years. Performances are taken from setlists that are not drafts.')">
		<form class="repertoire_thresholds" @submit.prevent="onThresholdsSubmit">
			<NcTextField
				v-model="currentYears"
				type="number"
				min="1"
				:label="t('Current repertoire (years)')" />
			<NcTextField
				v-model="dormantYears"
				type="number"
				min="1"
				:label="t('Dormant repertoire (years)')" />
			<NcButton type="submit" :disabled="thresholdsLoading || !thresholdsValid">
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
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { apiClients } from '@/api/client'
import { t } from '@/utils/l10n'

// Reactive data
const allowedGroups = ref<string[]>(loadState('orchestrascoresmanager', 'allowed_groups'))
const loading = ref(false)

const initialThresholds = loadState<{ currentYears: number, dormantYears: number }>(
	'orchestrascoresmanager',
	'repertoire_thresholds',
	{ currentYears: 3, dormantYears: 10 },
)
const currentYears = ref(String(initialThresholds.currentYears))
const dormantYears = ref(String(initialThresholds.dormantYears))
const thresholdsLoading = ref(false)
const thresholdsValid = computed(() => {
	const current = Number(currentYears.value)
	const dormant = Number(dormantYears.value)
	return Number.isInteger(current) && Number.isInteger(dormant) && current >= 1 && dormant >= current
})

// Save the repertoire class thresholds
async function onThresholdsSubmit() {
	if (!thresholdsValid.value) {
		showError(t('The dormant threshold must not be smaller than the current threshold.'))
		return
	}
	thresholdsLoading.value = true
	try {
		if (isPasswordConfirmationRequired(PwdConfirmationMode.Lax)) {
			await confirmPassword()
		}

		const response = await apiClients.admin.adminPostRepertoireThresholds({
			currentYears: Number(currentYears.value),
			dormantYears: Number(dormantYears.value),
		})
		currentYears.value = String(response.data.ocs.data.currentYears)
		dormantYears.value = String(response.data.ocs.data.dormantYears)
		showSuccess(t('Settings updated successfully.'))
	} catch (err) {
		console.error('Failed to update settings:', err)
		showError(t('Failed to update settings.'))
	} finally {
		thresholdsLoading.value = false
	}
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
.repertoire_thresholds {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 400px;
}
</style>
