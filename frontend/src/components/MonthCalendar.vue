<script setup lang="ts">
import { computed, ref } from 'vue'
import { iso } from '@/lib/design'
import type { CalendarEvent } from '@/types'

const props = defineProps<{ events: CalendarEvent[]; examDate: string | null }>()
const emit = defineEmits<{ dayClick: [date: string, title: string] }>()

const today = new Date()
today.setHours(0, 0, 0, 0)
const month = ref(new Date(today.getFullYear(), today.getMonth(), 1))

const weekDays = ['日', '月', '火', '水', '木', '金', '土']

const eventMap = computed(() => {
  const m: Record<string, string> = {}
  props.events.forEach((e) => (m[e.date] = e.title))
  return m
})

const label = computed(() => `${month.value.getFullYear()}年${month.value.getMonth() + 1}月`)

const weeks = computed(() => {
  const cm = month.value
  const startW = new Date(cm.getFullYear(), cm.getMonth(), 1).getDay()
  const dim = new Date(cm.getFullYear(), cm.getMonth() + 1, 0).getDate()
  const cells: {
    day: string
    inM: boolean
    bg: string
    color: string
    evName: string
    evColor: string
    show: boolean
    iso: string
  }[] = []
  for (let i = 0; i < 42; i++) {
    const dn = i - startW + 1
    const inM = dn >= 1 && dn <= dim
    const d = new Date(cm.getFullYear(), cm.getMonth(), dn)
    const di = iso(d)
    const isToday = inM && di === iso(today)
    const evx = inM ? eventMap.value[di] : null
    const isExam = inM && di === props.examDate
    const dow = d.getDay()
    cells.push({
      day: inM ? String(dn) : '',
      inM,
      iso: di,
      bg: isToday ? '#1c2024' : evx ? '#fbeef4' : isExam ? '#eaeefb' : 'transparent',
      color: !inM ? 'transparent' : isToday ? '#fff' : dow === 0 ? '#cf5563' : dow === 6 ? '#4b73c4' : '#3a4250',
      evName: evx || (isExam ? '受験日' : ''),
      evColor: isExam ? '#3b50cc' : '#cf4486',
      show: !!(evx || isExam),
    })
  }
  const w: (typeof cells)[] = []
  for (let i = 0; i < 6; i++) {
    const wk = cells.slice(i * 7, i * 7 + 7)
    if (wk.some((c) => c.inM)) w.push(wk)
  }
  return w
})

function move(delta: number) {
  const d = new Date(month.value)
  d.setMonth(d.getMonth() + delta)
  month.value = d
}
function click(c: { inM: boolean; iso: string; evName: string }) {
  if (!c.inM) return
  emit('dayClick', c.iso, eventMap.value[c.iso] || '')
}
</script>

<template>
  <div class="card" style="padding: 16px 18px">
    <div class="cal-head">
      <button class="nav-sq" @click="move(-1)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 6l-6 6 6 6" /></svg>
      </button>
      <span class="dm" style="font-size: 13.5px; font-weight: 700">{{ label }}</span>
      <button class="nav-sq" @click="move(1)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 6l6 6-6 6" /></svg>
      </button>
    </div>
    <div class="dow-row">
      <div v-for="wd in weekDays" :key="wd" class="dow">{{ wd }}</div>
    </div>
    <div v-for="(wk, wi) in weeks" :key="wi" class="cal-row">
      <button v-for="(c, ci) in wk" :key="ci" class="cal-cell" :style="{ background: c.bg }" @click="click(c)">
        <span class="cal-day" :style="{ color: c.color }">{{ c.day }}</span>
        <span v-if="c.show" class="cal-ev" :style="{ background: c.evColor }">{{ c.evName }}</span>
      </button>
    </div>
    <div style="font-size: 10.5px; color: var(--faint); margin-top: 8px; line-height: 1.5">
      日付をタップして模試などの予定を登録できます
    </div>
  </div>
</template>

<style scoped>
.cal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.nav-sq {
  border: none;
  background: #f2f3f5;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  cursor: pointer;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
}
.dow-row {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  margin-bottom: 3px;
}
.dow {
  text-align: center;
  font-size: 10px;
  color: var(--faint);
  padding: 2px 0;
}
.cal-row {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  margin-bottom: 2px;
}
.cal-cell {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 2px;
  min-height: 48px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  padding: 4px 3px 3px;
  overflow: hidden;
}
.cal-day {
  font-size: 11px;
  font-weight: 600;
  font-family: 'DM Sans', sans-serif;
  text-align: center;
  line-height: 1.1;
}
.cal-ev {
  margin-top: auto;
  font-size: 8.5px;
  line-height: 1.3;
  font-weight: 600;
  color: #fff;
  border-radius: 3px;
  padding: 1px 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
