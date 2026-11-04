<script setup lang="ts">
/**
 * タスク管理。科目ごとの「やるべきこと」を一覧し、追加・編集・停止/再開・並び替え・削除する。
 * 日別・週別の計画と消化はトップページの「タスク」タブ。
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import HelpTip from '@/components/HelpTip.vue'
import TaskEditModal from '@/components/TaskEditModal.vue'
import { appConfirm } from '@/lib/dialog'
import { hexA } from '@/lib/design'
import { fmtAmount, fmtRate, weekdaysLabel } from '@/lib/tasks'
import { useTaskStore } from '@/stores/task'
import { useUiStore } from '@/stores/ui'
import type { StudyTask, StudyTaskInput } from '@/types'

const tasks = useTaskStore()
const ui = useUiStore()
const router = useRouter()

const loading = ref(false)
onMounted(async () => {
  loading.value = true
  try {
    await tasks.fetchTasks()
  } catch {
    ui.notify('タスクの取得に失敗しました')
  } finally {
    loading.value = false
  }
})

function colorOf(t: StudyTask) {
  return ui.colorOf(t.colorSoft, t.colorVivid)
}

/** 日別の表示の例（週あたりは曜日数で割った値。割り切れないときは範囲） */
function perDay(t: StudyTask): string {
  if (t.period === 'day') return fmtAmount(t.amount, t.unit)
  const n = t.weekdays && t.weekdays.length ? t.weekdays.length : 7
  const lo = Math.floor(t.amount / n)
  const hi = Math.ceil(t.amount / n)
  return fmtAmount(lo === hi ? String(lo) : `${lo}〜${hi}`, t.unit)
}

function statusOf(t: StudyTask): { label: string; cls: string } {
  if (!t.isActive) return { label: '停止中', cls: 'off' }
  const today = new Date()
  const ts = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
  if (t.startOn > ts) return { label: `${t.startOn.slice(5).replace('-', '/')} から`, cls: 'soon' }
  if (t.endOn && t.endOn < ts) return { label: '期間終了', cls: 'off' }
  return { label: '進行中', cls: 'on' }
}

// ---- 科目ごとのグループ（科目の並び順。科目なしは最後） ----
interface Group {
  key: string
  subjectId: number | null
  name: string
  color: string
  rows: StudyTask[]
}
const groups = computed<Group[]>(() => {
  const order = new Map<number, number>(tasks.subjects.map((s, i) => [s.id, i]))
  const rank = (key: string) => (key === 'none' ? 9999 : (order.get(Number(key)) ?? 9998))
  const map = new Map<string, Group>()
  for (const t of tasks.tasks) {
    const key = t.subjectId === null ? 'none' : String(t.subjectId)
    let g = map.get(key)
    if (!g) {
      g = { key, subjectId: t.subjectId, name: t.subjectName ?? 'その他', color: colorOf(t), rows: [] }
      map.set(key, g)
    }
    g.rows.push(t)
  }
  return [...map.values()].sort((a, b) => rank(a.key) - rank(b.key))
})
const activeCount = computed(() => tasks.tasks.filter((t) => t.isActive).length)

// ---- 追加・編集 ----
const edit = ref<{ task: StudyTask | null; subjectId: number | null } | null>(null)
const saving = ref(false)
function openNew(subjectId: number | null = null) {
  edit.value = { task: null, subjectId }
}
function openEdit(t: StudyTask) {
  edit.value = { task: t, subjectId: t.subjectId }
}
async function save(payload: StudyTaskInput) {
  if (!edit.value) return
  saving.value = true
  try {
    if (edit.value.task) {
      await tasks.updateTask(edit.value.task.id, payload)
      ui.notify('タスクを保存しました')
    } else {
      await tasks.createTask(payload)
      ui.notify('タスクを追加しました')
    }
    edit.value = null
  } catch {
    ui.notify('タスクの保存に失敗しました')
  } finally {
    saving.value = false
  }
}

async function toggleActive(t: StudyTask) {
  try {
    await tasks.updateTask(t.id, { isActive: !t.isActive })
    ui.notify(t.isActive ? 'タスクを停止しました（計画に出なくなります）' : 'タスクを再開しました')
  } catch {
    ui.notify('更新に失敗しました')
  }
}

async function remove(t: StudyTask) {
  if (!(await appConfirm(`「${t.title}」を削除しますか？\n記録した消化量も消えます。`, { danger: true, okText: '削除' }))) return
  try {
    await tasks.removeTask(t.id)
    ui.notify('タスクを削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}

/** 同じ科目の中で上下に動かす（全体の並び順を送る） */
async function move(g: Group, t: StudyTask, dir: -1 | 1) {
  const i = g.rows.findIndex((x) => x.id === t.id)
  const j = i + dir
  if (i < 0 || j < 0 || j >= g.rows.length) return
  const other = g.rows[j]!
  const ids = tasks.tasks.map((x) => x.id)
  const a = ids.indexOf(t.id)
  const b = ids.indexOf(other.id)
  if (a < 0 || b < 0) return
  ids[a] = other.id
  ids[b] = t.id
  try {
    await tasks.reorder(ids)
  } catch {
    ui.notify('並び替えに失敗しました')
  }
}
</script>

<template>
  <div>
    <div class="tv-head">
      <div>
        <div class="tv-title">タスク管理<HelpTip text="科目ごとの「やるべきこと」を 1 日あたり／1 週あたりの量で登録します。登録したタスクはトップページの「タスク」タブに日別・週別の計画として表示され、そこで消化量を記録します。停止したタスクは計画に出ません。" /></div>
        <div class="tv-sub">{{ tasks.tasks.length }} 件（進行中 {{ activeCount }} 件）</div>
      </div>
      <div class="tv-actions">
        <button class="tv-btn-out" @click="router.push({ name: 'home' })">計画を見る ›</button>
        <button class="tv-btn-dark" @click="openNew()">＋ タスク追加</button>
      </div>
    </div>

    <div v-if="loading && !tasks.loaded" class="card tv-empty">読み込み中…</div>
    <div v-else-if="!tasks.tasks.length" class="card tv-empty">
      タスクがありません。<br />「タスク追加」から、例えば「英単語 LEAP basic 140 words / 週」「英文法 テキスト 1単元 / 日」のように登録すると、<br />トップページの「タスク」タブに日別（20 words・1単元）・週別の計画が表示されます。
    </div>

    <div v-else class="tv-groups">
      <div v-for="g in groups" :key="g.key" class="card tv-group">
        <div class="tv-g-head" :style="{ borderLeftColor: g.color }">
          <span class="tv-dot" :style="{ background: g.color }"></span>
          <span class="tv-g-name">{{ g.name }}</span>
          <span class="tv-g-cnt">{{ g.rows.length }} 件</span>
          <button class="tv-g-add" @click="openNew(g.subjectId)">＋ 追加</button>
        </div>
        <div v-for="(t, i) in g.rows" :key="t.id" class="tv-row" :class="{ off: !t.isActive }">
          <div class="tv-order">
            <button class="tv-ord-btn" :disabled="i === 0" title="上へ" @click="move(g, t, -1)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6" /></svg>
            </button>
            <button class="tv-ord-btn" :disabled="i === g.rows.length - 1" title="下へ" @click="move(g, t, 1)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
            </button>
          </div>
          <div class="tv-main">
            <div class="tv-line1">
              <span class="tv-name">{{ t.title }}</span>
              <span class="tv-rate" :style="{ color: g.color, background: hexA(g.color, 0.12) }">{{ fmtRate(t) }}</span>
              <span v-if="t.endOn === t.startOn" class="tv-status soon">{{ t.startOn.slice(5).replace('-', '/') }} だけ</span>
              <span class="tv-status" :class="statusOf(t).cls">{{ statusOf(t).label }}</span>
            </div>
            <div class="tv-line2">
              <span>日別 <b>{{ perDay(t) }}</b></span>
              <span>{{ weekdaysLabel(t.weekdays) }}</span>
              <span>{{ t.startOn.replace(/-/g, '/') }}{{ t.endOn ? ` 〜 ${t.endOn.replace(/-/g, '/')}` : ' 〜' }}</span>
              <span v-if="t.note" class="tv-note">{{ t.note }}</span>
            </div>
          </div>
          <div class="tv-btns">
            <button class="tv-sm" @click="openEdit(t)">編集</button>
            <button class="tv-sm" @click="toggleActive(t)">{{ t.isActive ? '停止' : '再開' }}</button>
            <button class="tv-sm danger" @click="remove(t)">削除</button>
          </div>
        </div>
      </div>
    </div>

    <Transition name="ui-modal">
      <TaskEditModal
        v-if="edit"
        :task="edit.task"
        :subjects="tasks.subjects"
        :default-subject-id="edit.subjectId"
        :saving="saving"
        @save="save"
        @close="edit = null"
      />
    </Transition>
  </div>
</template>

<style scoped>
.tv-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.tv-title {
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}
.tv-sub {
  font-size: 12px;
  color: var(--faint);
  margin-top: 2px;
}
.tv-actions {
  display: flex;
  gap: 8px;
}
.tv-btn-dark {
  padding: 9px 15px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tv-btn-out {
  padding: 9px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: var(--mut);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tv-empty {
  padding: 36px 20px;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
  line-height: 1.8;
}
.tv-groups {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.tv-group {
  padding: 4px 0 4px;
  overflow: hidden;
}
.tv-g-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px 8px;
  border-left: 4px solid transparent;
}
.tv-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tv-g-name {
  font-size: 14px;
  font-weight: 700;
}
.tv-g-cnt {
  font-size: 11px;
  color: var(--faint);
}
.tv-g-add {
  margin-left: auto;
  padding: 4px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  color: var(--mut);
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}
.tv-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px 10px 10px;
  border-top: 1px solid #f2f3f5;
}
.tv-row.off {
  background: #fafbfc;
}
.tv-row.off .tv-name {
  color: #9aa1ab;
}
.tv-order {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex-shrink: 0;
}
.tv-ord-btn {
  width: 24px;
  height: 20px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--faint);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tv-ord-btn:hover:not(:disabled) {
  background: #f0f1f3;
  color: var(--ink);
}
.tv-ord-btn:disabled {
  opacity: 0.25;
  cursor: default;
}
.tv-main {
  flex: 1;
  min-width: 0;
}
.tv-line1 {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.tv-name {
  font-size: 14px;
  font-weight: 700;
}
.tv-rate {
  font-size: 11.5px;
  font-weight: 700;
  border-radius: 99px;
  padding: 1px 8px;
  white-space: nowrap;
}
.tv-status {
  font-size: 10.5px;
  font-weight: 700;
  border-radius: 99px;
  padding: 1px 7px;
  white-space: nowrap;
}
.tv-status.on {
  color: #2e9d62;
  background: #eef7f0;
}
.tv-status.off {
  color: #6b7280;
  background: #eef0f3;
}
.tv-status.soon {
  color: #3b50cc;
  background: #eef0fb;
}
.tv-line2 {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 11.5px;
  color: var(--faint);
  margin-top: 3px;
}
.tv-line2 b {
  color: var(--mut);
  font-weight: 700;
}
.tv-note {
  color: var(--mut);
  white-space: pre-wrap;
  word-break: break-word;
}
.tv-btns {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}
.tv-sm {
  padding: 6px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  color: var(--ink);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tv-sm.danger {
  color: #d92d20;
}
@media (max-width: 600px) {
  .tv-row {
    flex-wrap: wrap;
    padding: 10px 12px 10px 8px;
  }
  .tv-main {
    flex-basis: calc(100% - 50px);
  }
  .tv-btns {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
