<script setup lang="ts">
defineProps<{ paths: string[]; size?: number }>()
</script>

<template>
  <svg
    :width="size ?? 19"
    :height="size ?? 19"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path v-for="(d, i) in paths" :key="i" :d="d" />
  </svg>
</template>
