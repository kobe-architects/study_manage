import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify } from 'vuetify'

export default createVuetify({
  theme: {
    defaultTheme: 'studyLight',
    themes: {
      studyLight: {
        dark: false,
        colors: {
          background: '#f6f7f9',
          surface: '#ffffff',
          primary: '#3b50cc',
          secondary: '#1c2024',
          ink: '#1c2024',
          error: '#cf5563',
          success: '#2e9d62',
          warning: '#d98a2b',
        },
      },
    },
  },
  defaults: {
    VTextField: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VTextarea: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
  },
})
