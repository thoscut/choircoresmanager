// Publication rights status of a score and its labels

import { t } from '@/utils/l10n'

export type RightsStatus = 'free' | 'unclear' | 'restricted'

export const RIGHTS_STATUS_VALUES: RightsStatus[] = ['free', 'unclear', 'restricted']

/**
 * Translated labels for each rights status, e.g. for AG Grid's refData
 */
export function rightsStatusLabels(): Record<RightsStatus, string> {
	return {
		free: t('Free (public domain)'),
		unclear: t('Unclear'),
		restricted: t('Restricted'),
	}
}
