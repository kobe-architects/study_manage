<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { daysBetween, hexA, parseDate, pct, TYPE_BADGE } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { Goal, GoalItemDetail } from '@/types'

defineOptions({ name: 'GoalCard' })
const props = defineProps<{ goal: Goal; isSub?: boolean; nested?: boolean }>()
const emit = defineEmits<{ link: [Goal]; addSub: [Goal]; remove: [Goal]; edit: [Goal] }>()

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

const color = computed(() => ui.colorOf(props.goal.colorSoft, props.goal.colorVivid))
const daysLeft = computed(() => Math.max(0, daysBetween(today, parseDate(props.goal.deadline))))
const deadlineLabel = computed(() => {
  const d = parseDate(props.goal.deadline)
  return `${d.getFullYear()}.${d.getMonth() + 1}.${d.getDate()}`
})
const percent = computed(() => pct(props.goal.done, props.goal.target))
const remaining = computed(() => Math.max(0, props.goal.target - props.goal.done))

// ---- 紐づけデータの展開 + 学習済みトグル ----
const expanded = ref(false)
const items = ref<GoalItemDetail[]>([])
const loading = ref(false)
async function toggleExpand() {
  expanded.value = !expanded.value
  if (expanded.value && !items.value.length) await reload()
}
async function reload() {
  loading.value = true
  try {
    items.value = await study.fetchGoalItems(props.goal.id)
  } finally {
    loading.value = false
  }
}
const busy = reactive<Record<number, boolean>>({})
async function toggleStudied(it: GoalItemDetail) {
  busy[it.id] = true
  try {
    await study.setGoalItemStudied(props.goal.id, it.id, !it.studied)
    await reload()
  } catch {
    ui.notify('学習済みの更新に失敗しました')
  } finally {
    busy[it.id] = false
  }
}

async function setAchieved(value: boolean) {
  const next = props.goal.achieved === value ? null : value
  await study.setGoalAchieved(props.goal.id, next)
  ui.notify(next === null ? '達成状態を未記録に戻しました' : next ? '達成として記録しました' : '未達成として記録しました')
}
</script>

<template>
  <div :class="[nested ? 'goal-card nested-card' : 'card goal-card', { sub: isSub }]" :style="{ borderLeft: isSub ? `3px solid ${color}` : undefined }">
    <div class="row-between" style="gap: 10px; flex-wrap: wrap">
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0">
        <span v-if="isSub" class="sub-tag">中間目標</span>
        <span :style="{ fontSize: '11px', fontWeight: 600, color, background: hexA(color, 0.12), padding: '2px 8px', borderRadius: '99px' }">{{ goal.rangeLabel }}</span>
        <span :style="{ fontSize: '11.5px', color: daysLeft <= 7 ? '#e0533d' : '#9aa1ab', fontWeight: 600 }">期限まで{{ daysLeft }}日</span>
        <span v-if="goal.achieved === true" class="ach-badge ok">達成</span>
        <span v-else-if="goal.achieved === false" class="ach-badge ng">未達成</span>
      </div>
      <button class="del-btn" title="削除" @click="emit('remove', goal)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
      </button>
    </div>

    <div :style="{ fontSize: isSub ? '14px' : '15px', fontWeight: 700, margin: '8px 0 10px', lineHeight: 1.4 }">{{ goal.title }}</div>

    <div class="row-between" style="align-items: baseline; margin-bottom: 6px">
      <span style="font-size: 12px; color: var(--mut)">達成項目</span>
      <span class="dm" style="font-size: 13px; font-weight: 700">{{ goal.done }} / {{ goal.target }}<span :style="{ color, marginLeft: '6px' }">{{ percent }}%</span></span>
    </div>
    <div class="track"><div :style="{ height: '100%', width: percent + '%', background: color, borderRadius: '99px' }"></div></div>

    <div class="row-between" style="align-items: center; margin-top: 8px">
      <span style="font-size: 11.5px; color: var(--faint)">期限: {{ deadlineLabel }}</span>
      <span :style="{ fontSize: '11.5px', fontWeight: 700, color: remaining > 0 ? '#e0533d' : '#2e9d62' }">残り {{ remaining }} 項目</span>
    </div>

    <!-- 紐づけデータ 展開（学習済みトグル） -->
    <div v-if="goal.linkedCount" style="margin-top: 9px; border-top: 1px solid #f0f1f3; padding-top: 8px">
      <button class="expand-btn" @click="toggleExpand">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expanded ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }"><path d="M9 6l6 6-6 6" /></svg>
        紐づけデータ {{ goal.linkedCount }}件（未学習 {{ remaining }}）<span style="color: var(--faint); font-weight: 500">・クリックで学習済み切替</span>
      </button>
      <div v-if="expanded" style="margin-top: 6px">
        <div v-if="loading" style="font-size: 12px; color: var(--faint); padding: 6px 2px">読み込み中…</div>
        <div v-else style="max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px">
          <button v-for="it in items" :key="it.id" class="gi-row" :disabled="busy[it.id]" @click="toggleStudied(it)">
            <span v-if="it.type" class="gi-badge" :style="{ background: TYPE_BADGE[it.type].bg, color: TYPE_BADGE[it.type].fg }">{{ it.type }}</span>
            <span v-else class="gi-badge" style="background: #f1f2f4; color: #aeb4bd">—</span>
            <span class="gi-mark" :style="{ color: it.studied ? '#2e9d62' : '#cbd1d8' }">{{ it.studied ? '✓' : '○' }}</span>
            <span class="gi-title" :style="{ color: it.studied ? '#9aa1ab' : '#1c2024', textDecoration: it.studied ? 'line-through' : 'none' }">{{ it.title ?? it.sub ?? '（無題）' }}</span>
            <span class="gi-src">{{ it.bookTitle }}</span>
          </button>
          <div v-if="!items.length" style="font-size: 12px; color: var(--faint); padding: 6px 2px">紐づけデータがありません</div>
        </div>
      </div>
    </div>

    <!-- 操作 -->
    <div class="actions">
      <button class="pill-btn" @click="emit('edit', goal)">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
        編集
      </button>
      <button class="pill-btn" @click="emit('link', goal)">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></svg>
        紐づけ変更
      </button>
      <button v-if="!isSub" class="pill-btn" @click="emit('addSub', goal)">＋ 中間目標</button>
      <div class="ach-toggle">
        <button class="ach-btn ok" :class="{ on: goal.achieved === true }" @click="setAchieved(true)">達成</button>
        <button class="ach-btn ng" :class="{ on: goal.achieved === false }" @click="setAchieved(false)">未達成</button>
      </div>
    </div>

    <!-- 中間目標（同じカード内に入れ子表示） -->
    <div v-if="!isSub && goal.subGoals?.length" class="subgoals">
      <div class="subgoals-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 6l6 6-6 6" /></svg>
        中間目標（{{ goal.subGoals.length }}）
      </div>
      <GoalCard
        v-for="s in goal.subGoals"
        :key="s.id"
        :goal="s"
        is-sub
        nested
        @link="(g) => emit('link', g)"
        @remove="(g) => emit('remove', g)"
        @edit="(g) => emit('edit', g)"
      />
    </div>
  </div>
</template>

<style scoped>
.goal-card {
  padding: 16px 18px;
}
.goal-card.sub {
  padding: 13px 15px;
  background: #fbfcfe;
}
.nested-card {
  border: 1px solid #eef0f3;
  border-radius: 12px;
}
.subgoals {
  margin-top: 12px;
  border-top: 1px dashed #e3e6ea;
  padding-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.subgoals-label {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #5849c0;
}
.row-between {
  display: flex;
  justify-content: space-between;
}
.sub-tag {
  font-size: 10.5px;
  font-weight: 700;
  color: #5849c0;
  background: #eeecfa;
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
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  border-radius: 6px;
}
.gi-row:hover:not(:disabled) {
  background: #f6f8fb;
}
.gi-row:disabled {
  opacity: 0.5;
  cursor: default;
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
