import { createApp } from 'vue'
import App from './App.vue'
import { installEscapeClose } from './escapeClose.ts'

import '@nextcloud/dialogs/style.css'

installEscapeClose()

const app = createApp(App)
app.mount('#notenarchiv')
