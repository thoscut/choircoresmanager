<template>
	<NcDialog
		:name="t('Share {title}', { title })"
		size="normal"
		@closing="emit('close')">
		<ShareManager :targetType="targetType" :targetId="targetId" />
	</NcDialog>
</template>

<script setup lang="ts">
import NcDialog from '@nextcloud/vue/components/NcDialog'
import ShareManager from '@/components/shares/ShareManager.vue'
import { t } from '@/utils/l10n'

interface Props {
	targetType: 'score' | 'folder_collection'
	targetId: number
	title: string
}

defineProps<Props>()

const emit = defineEmits<{
	close: []
}>()
</script>
