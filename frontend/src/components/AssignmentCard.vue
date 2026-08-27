<script setup lang="ts">
import { computed, ref } from 'vue'
import { daysBetween, parseDate, pct, TYPE_BADGE } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { Assignment, GoalItemDetail } from '@/types'

const props = defineProps<{
  assignment: Assignment
  readonly?: boolean // 生徒側は閲覧のみ
}>()
const emit = defineEmits<{ edit: [Assignment]; remove: [Assignment] }>()

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

const daysLeft = computed(() => daysBetween(today, parseDate(props.assignment.dueOn)))
const dueLabel = computed(() => {
  const d = parseDate(props.assignment.dueOn)
  return `${d.getFullYear()}.${d.getMonth() + 1}.${d.getDate()}`
})
const percent = computed(() => pct(props.assignment.done, props.assignment.target))
const remaining = computed(() => Math.max(0, props.assignment.target - props.assignment.done))
const barColor = computed(() => (props.assignment.overdue ? '#e0533d' : '#2e4a8f'))

// ---- 対象データの展開 ----
const expanded = ref(false)
const items = ref<GoalItemDetail[]>([])
const loading = ref(false)
async function toggleExpand() {
  expanded.value = !expanded.value
  if (expanded.value && !items.value.length) {
    loading.value = true
    try {
      items.value = await study.fetchAssignmentItems(props.assignment.id)
    } finally {
      loading.value = false
    }
  }
}

// 達成/未達成の記録（tutor のみ）
async function setAchieved(value: boolean) {
  const next = props.assignment.achieved === value ? null : value
  try {
    await study.updateAssignment(props.assignment.id, { achieved: next })
    ui.notify(next === null ? '達成状態を未記録に戻しました' : next ? '達成として記録しました' : '未達成として記録しました')
  } catch {
    ui.notify('更新に失敗しました')
  }
}
</script>

<template>
  <div class="card as-card">
    <div class="row-between" style="gap: 10px; flex-wrap: wrap">
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0">
        <span class="as-tag">課題</span>
        <span
          v-if="assignment.achieved === null"
          :style="{ fontSize: '11.5px', fontWeight: 600, color: assignment.overdue ? '#e0533d' : daysLeft <= 3 ? '#e0533d' : '#9aa1ab' }"
        >{{ assignment.overdue ? `期限超過（${-daysLeft}日）` : `期限まで${daysLeft}日` }}</span>
        <span v-if="assignment.achieved === true" class="ach-badge ok">達成</span>
        <span v-else-if="assignment.achieved === false" class="ach-badge ng">未達成</span>
        <span v-if="assignment.createdByName" style="font-size: 11px; color: var(--faint)">担当: {{ assignment.createdByName }}</span>
      </div>
      <button v-if="!readonly" class="del-btn" title="削除" @click="emit('remove', assignment)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
      </button>
    </div>

    <div style="font-size: 15px; font-weight: 700; margin: 8px 0 4px; line-height: 1.4">{{ assignment.title }}</div>
    <div v-if="assignment.note" style="font-size: 12px; color: var(--mut); margin-bottom: 8px; white-space: pre-wrap; line-height: 1.6">{{ assignment.note }}</div>

    <div class="row-between" style="align-items: baseline; margin: 6px 0">
      <span style="font-size: 12px; color: var(--mut)">達成項目</span>
      <span class="dm" style="font-size: 13px; font-weight: 700">{{ assignment.done }} / {{ assignment.target }}<span :style="{ color: barColor, marginLeft: '6px' }">{{ percent }}%</span></span>
    </div>
    <div class="track"><div :style="{ height: '100%', width: percent + '%', background: barColor, borderRadius: '99px' }"></div></div>

    <div class="row-between" style="align-items: center; margin-top: 8px">
      <span style="font-size: 11.5px; color: var(--faint)">期限: {{ dueLabel }}</span>
      <span :style="{ fontSize: '11.5px', fontWeight: 700, color: remaining > 0 ? '#e0533d' : '#2e9d62' }">残り {{ remaining }} 項目</span>
    </div>

    <!-- 対象データの展開（閲覧） -->
    <div style="margin-top: 9px; border-top: 1px solid #f0f1f3; padding-top: 8px">
      <button class="expand-btn" @click="toggleExpand">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expanded ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }"><path d="M9 6l6 6-6 6" /></svg>
        対象データ {{ assignment.target }}件（未学習 {{ remaining }}）
      </button>
      <div v-if="expanded" style="margin-top: 6px">
        <div v-if="loading" style="font-size: 12px; color: var(--faint); padding: 6px 2px">読み込み中…</div>
        <div v-else style="max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px">
          <div v-for="it in items" :key="it.id" class="gi-row">
            <span v-if="it.type" class="gi-badge" :style="{ background: TYPE_BADGE[it.type].bg, color: TYPE_BADGE[it.type].fg }">{{ it.type }}</span>
            <span v-else class="gi-badge" style="background: #f1f2f4; color: #aeb4bd">—</span>
            <span class="gi-mark" :style="{ color: it.studied ? '#2e9d62' : '#cbd1d8' }">{{ it.studied ? '✓' : '○' }}</span>
            <span class="gi-title" :style="{ color: it.studied ? '#9aa1ab' : '#1c2024', textDecoration: it.studied ? 'line-through' : 'none' }"><span v-if="it.seqNo" style="color: #aeb4bd">{{ it.seqNo }}.</span> {{ it.title ?? it.sub ?? '（無題）' }}</span>
            <span class="gi-src">{{ it.bookTitle }}</span>
          </div>
          <div v-if="!items.length" style="font-size: 12px; color: var(--faint); padding: 6px 2px">対象データがありません</div>
        </div>
      </div>
    </div>

    <!-- 操作（tutor のみ） -->
    <div v-if="!readonly" class="actions">
      <button class="pill-btn" @click="emit('edit', assignment)">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
        編集
      </button>
      <div class="ach-toggle">
        <button class="ach-btn ok" :class="{ on: assignment.achieved === true }" @click="setAchieved(true)">達成</button>
        <button class="ach-btn ng" :class="{ on: assignment.achieved === false }" @click="setAchieved(false)">未達成</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.as-card {
  padding: 16px 18px;
}
.row-between {
  display: flex;
  justify-content: space-between;
}
.as-tag {
  font-size: 10.5px;
  font-weight: 700;
  color: #2e4a8f;
  background: #e8eefb;
  padding: 2px 8px;
  border-radius: 99px;
}
.ach-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 99px;
}
.ach-badge.ok {
  background: #eaf7ef;
  color: #1f7a45;
}
.ach-badge.ng {
  background: #fdeef0;
  color: #c0444f;
}
.track {
  height: 8px;
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.del-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 4px;
  color: #cbd1d8;
  flex-shrink: 0;
}
.expand-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: none;
  background: none;
  padding: 2px 0;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  color: #3b50cc;
}
.gi-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 4px;
  font-size: 12px;
  min-width: 0;
  border-radius: 6px;
}
.gi-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 99px;
  width: 44px;
  text-align: center;
}
.gi-mark {
  flex-shrink: 0;
  font-weight: 700;
  width: 14px;
  text-align: center;
}
.gi-title {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gi-src {
  flex-shrink: 0;
  max-width: 38%;
  font-size: 10.5px;
  color: #aeb4bd;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 12px;
}
.pill-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border: 1px solid #d7dcfb;
  border-radius: 8px;
  background: #f3f5ff;
  color: #3b50cc;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.ach-toggle {
  display: flex;
  gap: 6px;
  margin-left: auto;
}
.ach-btn {
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: #9aa1ab;
}
.ach-btn.ok.on {
  background: #eaf7ef;
  border-color: #9ed4b3;
  color: #1f7a45;
}
.ach-btn.ng.on {
  background: #fdeef0;
  border-color: #f0b8be;
  color: #c0444f;
}
</style>
