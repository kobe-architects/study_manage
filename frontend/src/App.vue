<script setup lang="ts">
import { useUiStore } from '@/stores/ui'
const ui = useUiStore()
</script>

<template>
  <v-app>
    <router-view />
    <!-- グローバルトースト -->
    <v-snackbar v-model="ui.toastShow" location="bottom" timeout="-1" color="secondary" rounded="lg">
      <div style="display: flex; align-items: center; gap: 9px">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#7dd88f" stroke-width="2.2">
          <path d="M20 6L9 17l-5-5"></path>
        </svg>
        {{ ui.toast }}
      </div>
    </v-snackbar>
  </v-app>
</template>
