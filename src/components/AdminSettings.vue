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
		:name="t('Public page')"
		:description="t('Show all scores marked as free (public domain) on a page that can be opened without logging in. Visitors can view and play the MusicXML files of these scores. No other scores or internal details are shown.')">
		<NcCheckboxRadioSwitch
			v-model="publicPageEnabled"
			type="switch"
			:loading="publicPageLoading"
			@update:modelValue="onPublicPageChanged">
			{{ t('Enable public page') }}
		</NcCheckboxRadioSwitch>
		<p v-if="publicPageEnabled" class="admin-settings__public-link">
			{{ t('Public address:') }}
			<a :href="publicPageUrl" target="_blank" rel="noopener noreferrer">{{ publicPageUrl }}</a>
		</p>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword, isPasswordConfirmationRequired, PwdConfirmationMode } from '@nextcloud/password-confirmation'
import { ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import { apiClients } from '@/api/client'
import { t } from '@/utils/l10n'

// Reactive data
const allowedGroups = ref<string[]>(loadState('orchestrascoresmanager', 'allowed_groups'))
const loading = ref(false)
const publicPageEnabled = ref<boolean>(loadState('orchestrascoresmanager', 'public_page_enabled', false))
const publicPageUrl = loadState<string>('orchestrascoresmanager', 'public_page_url', '')
const publicPageLoading = ref(false)

/**
 * Enable or disable the public page
 *
 * @param enabled - Whether the public page should be enabled
 */
async function onPublicPageChanged(enabled: boolean) {
	publicPageLoading.value = true
	try {
		if (isPasswordConfirmationRequired(PwdConfirmationMode.Lax)) {
			await confirmPassword()
		}
		const response = await apiClients.admin.adminPostPublicPage({ publicPageEnabled: enabled })
		publicPageEnabled.value = response.data.ocs.data.publicPageEnabled
		showSuccess(t('Settings updated successfully.'))
	} catch (err) {
		console.error('Failed to update settings:', err)
		publicPageEnabled.value = !enabled
		showError(t('Failed to update settings.'))
	} finally {
		publicPageLoading.value = false
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

.admin-settings__public-link {
	margin-top: var(--default-grid-baseline);

	a {
		text-decoration: underline;
	}
}
</style>
