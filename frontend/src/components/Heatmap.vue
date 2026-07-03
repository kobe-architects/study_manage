<script setup lang="ts">
import { computed } from 'vue'
import { iso } from '@/lib/design'

const props = defineProps<{ counts: Record<string, number>; cell?: number }>()

const palette = ['#eef0f3', '#cfe6d8', '#9ed4b3', '#5cbb84', '#2e9d62']
const size = computed(() => props.cell ?? 11)

const columns = computed(() => {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const start = new Date(today)
  start.setDate(start.getDate() - (15 * 7 + today.getDay()))
  const cols: { bg: string; title: string }[][] = []
  for (let w = 0; w < 16; w++) {
    const col: { bg: string; title: string }[] = []
    for (let d = 0; d < 7; d++) {
      const day = new Date(start)
      day.setDate(day.getDate() + w * 7 + d)
      const k = iso(day)
      const c = props.counts[k] || 0
      const lvl = c === 0 ? 0 : c <= 1 ? 1 : c <= 2 ? 2 : c <= 4 ? 3 : 4
      const future = day > today
      col.push({ bg: future ? 'transparent' : palette[lvl], title: `${day.getMonth() + 1}/${day.getDate()} ・ ${c}件` })
    }
    cols.push(col)
  }
  return cols
})
</script>

<template>
  <div>
    <div style="display: flex; gap: 3px; overflow-x: auto; padding-bottom: 2px">
      <div v-for="(col, ci) in columns" :key="ci" style="display: flex; flex-direction: column; gap: 3px">
        <div
          v-for="(c, di) in col"
          :key="di"
          :title="c.title"
          :style="{ width: size + 'px', height: size + 'px', borderRadius: '3px', background: c.bg }"
        ></div>
      </div>
    </div>
    <div class="legend">
      少
      <div v-for="(p, i) in palette" :key="i" :style="{ width: '10px', height: '10px', borderRadius: '2px', background: p }"></div>
      多
    </div>
  </div>
</template>

<style scoped>
.legend {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  margin-top: 9px;
  font-size: 10px;
  color: var(--faint);
}
</style>
