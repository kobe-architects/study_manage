<script setup lang="ts">
/**
 * ガントチャートの編集画面。上部に名前・表示期間・倍率、下にチャート本体（GanttBoard）。
 * バーのドラッグ結果はすぐ画面に反映してから保存する（失敗したら読み直して元に戻す）。
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import GanttBoard from '@/components/GanttBoard.vue'
import GanttTaskModal from '@/components/GanttTaskModal.vue'
import HelpTip from '@/components/HelpTip.vue'
import { ganttApi, type GanttTaskInput } from '@/api/gantt'
import { appConfirm } from '@/lib/dialog'
import { GANTT_ZOOMS, fmtYm, type GanttZoom } from '@/lib/gantt'
import { isTouch, viewportWidth } from '@/lib/native'
import { vSeg } from '@/lib/segSlide'
import { useUiStore } from '@/stores/ui'
import type { GanttChart, GanttTask } from '@/types'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const chartId = computed(() => Number(route.params.id))
const chart = ref<(GanttChart & { tasks: GanttTask[] }) | null>(null)
const loading = ref(true)
const board = ref<InstanceType<typeof GanttBoard> | null>(null)
const isMobile = computed(() => viewportWidth.value < 860)

async function load() {
  loading.value = true
  try {
    chart.value = await ganttApi.show(chartId.value)
  } catch {
    ui.notify('ガントチャートが見つかりません')
    router.replace({ name: 'gantt' })
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ---- 倍率（端末に記憶） ----
const ZOOM_KEY = 'sm_gantt_zoom'
const zoom = ref<GanttZoom>((localStorage.getItem(ZOOM_KEY) as GanttZoom | null) ?? 'quarter')
if (!GANTT_ZOOMS.some((z) => z.key === zoom.value)) zoom.value = 'quarter'
watch(zoom, (z) => localStorage.setItem(ZOOM_KEY, z))

// ---- 項目の追加・編集 ----
const taskModal = reactive<{ open: boolean; task: GanttTask | null; initialStart?: string }>({ open: false, task: null })
function openAdd(startOn?: string) {
  taskModal.task = null
  taskModal.initialStart = startOn
  taskModal.open = true
}
function openEdit(t: GanttTask) {
  taskModal.task = t
  taskModal.initialStart = undefined
  taskModal.open = true
}
async function saveTask(payload: GanttTaskInput & { title: string; startOn: string }) {
  if (!chart.value) return
  try {
    if (taskModal.task) {
      const updated = await ganttApi.updateTask(taskModal.task.id, payload)
      replaceTask(updated)
      ui.notify('項目を更新しました')
    } else {
      const created = await ganttApi.createTask(chart.value.id, payload)
      chart.value.tasks.push(created)
      chart.value.taskCount = chart.value.tasks.length
      ui.notify('項目を追加しました')
      // 追加した項目が見えるようにスクロール
      board.value?.scrollToDate(created.startOn)
    }
    taskModal.open = false
  } catch (e) {
    ui.notify(errorMessage(e, '保存に失敗しました'))
  }
}
async function deleteTask(id: number) {
  if (!chart.value) return
  const t = chart.value.tasks.find((x) => x.id === id)
  if (!t) return
  if (!(await appConfirm(`「${t.title}」を削除しますか？`, { danger: true, okText: '削除' }))) return
  try {
    await ganttApi.removeTask(id)
    chart.value.tasks = chart.value.tasks.filter((x) => x.id !== id)
    chart.value.taskCount = chart.value.tasks.length
    taskModal.open = false
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}
function replaceTask(t: GanttTask) {
  if (!chart.value) return
  const i = chart.value.tasks.findIndex((x) => x.id === t.id)
  if (i >= 0) chart.value.tasks.splice(i, 1, t)
}

/** ドラッグで期間を動かした: 先に画面へ反映し、保存に失敗したら読み直す */
async function onMove(t: GanttTask, startOn: string, endOn: string) {
  if (!chart.value) return
  const prev = { startOn: t.startOn, endOn: t.endOn }
  t.startOn = startOn
  t.endOn = endOn
  try {
    const updated = await ganttApi.updateTask(t.id, { startOn, endOn })
    replaceTask(updated)
  } catch {
    t.startOn = prev.startOn
    t.endOn = prev.endOn
    ui.notify('期間の保存に失敗しました')
  }
}

/** 並び替え */
async function onReorder(ids: number[]) {
  if (!chart.value) return
  const map = new Map(chart.value.tasks.map((t) => [t.id, t]))
  const prev = chart.value.tasks
  chart.value.tasks = ids.map((id) => map.get(id)!).filter(Boolean)
  try {
    chart.value.tasks = await ganttApi.reorderTasks(chart.value.id, ids)
  } catch {
    chart.value.tasks = prev
    ui.notify('並び順の保存に失敗しました')
  }
}

// ---- チャートの設定（名前・期間・メモ） ----
const settings = reactive({ open: false, title: '', startOn: '', endOn: '', note: '' })
function openSettings() {
  if (!chart.value) return
  settings.title = chart.value.title
  settings.startOn = chart.value.startOn
  settings.endOn = chart.value.endOn
  settings.note = chart.value.note ?? ''
  settings.open = true
}
const settingsError = computed(() => (settings.startOn && settings.endOn && settings.endOn < settings.startOn ? '終了は開始以降にしてください' : ''))
const canSaveSettings = computed(() => !!settings.title.trim() && !!settings.startOn && !!settings.endOn && !settingsError.value)
async function saveSettings() {
  if (!chart.value || !canSaveSettings.value) return
  try {
    chart.value = await ganttApi.update(chart.value.id, {
      title: settings.title.trim(),
      startOn: settings.startOn,
      endOn: settings.endOn,
      note: settings.note.trim() || null,
    })
    settings.open = false
    ui.notify('設定を保存しました')
  } catch (e) {
    ui.notify(errorMessage(e, '保存に失敗しました'))
  }
}
async function duplicateChart() {
  if (!chart.value) return
  try {
    const c = await ganttApi.duplicate(chart.value.id)
    ui.notify('複製しました')
    settings.open = false
    router.push({ name: 'gantt-chart', params: { id: c.id } })
  } catch {
    ui.notify('複製に失敗しました')
  }
}
async function deleteChart() {
  if (!chart.value) return
  const n = chart.value.tasks.length
  const msg = n ? `「${chart.value.title}」と項目${n}件を削除しますか？` : `「${chart.value.title}」を削除しますか？`
  if (!(await appConfirm(msg, { danger: true, okText: '削除' }))) return
  try {
    await ganttApi.remove(chart.value.id)
    ui.notify('削除しました')
    router.replace({ name: 'gantt' })
  } catch {
    ui.notify('削除に失敗しました')
  }
}

function errorMessage(e: unknown, fallback: string): string {
  const r = (e as { response?: { data?: { message?: string } } })?.response
  return r?.data?.message || fallback
}

const helpText = isTouch
  ? 'バーを横にドラッグすると期間ごと移動、バーの両端を引っ張ると開始日・終了日を変えられます（1日単位）。バーや項目名をタップすると編集、左端の取っ手を上下にドラッグすると並び替えです。「節目」は模試や本番など1日の予定で、ひし形で表示します。'
  : 'バーを横にドラッグすると期間ごと移動、バーの両端をドラッグすると開始日・終了日を変えられます（1日単位）。バーや項目名をクリックすると編集、空いている場所をダブルクリックするとその日から始まる項目を追加、左端の取っ手を上下にドラッグすると並び替えです。「節目」は模試や本番など1日の予定で、ひし形で表示します。'
</script>

<template>
  <div>
    <div v-if="loading && !chart" class="hint" style="text-align: center">読み込み中…</div>

    <template v-else-if="chart">
      <div class="top">
        <div class="left">
          <button v-if="!isMobile" class="back" @click="router.push({ name: 'gantt' })">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>一覧
          </button>
          <button class="ttl-btn" title="名前・表示期間を変更" @click="openSettings">
            <span class="ttl">{{ chart.title }}</span>
            <span class="range">{{ fmtYm(chart.startOn) }} 〜 {{ fmtYm(chart.endOn) }}</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="pen"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
          </button>
          <HelpTip :text="helpText" />
        </div>
        <div class="right">
          <div v-seg class="seg">
            <button v-for="z in GANTT_ZOOMS" :key="z.key" class="seg-btn" :class="{ on: zoom === z.key }" @click="zoom = z.key">{{ z.label }}</button>
          </div>
          <button class="btn-ghost" @click="board?.scrollToToday()">今日</button>
          <button class="btn-dark" @click="openAdd()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>項目を追加
          </button>
        </div>
      </div>

      <GanttBoard
        ref="board"
        :tasks="chart.tasks"
        :start-on="chart.startOn"
        :end-on="chart.endOn"
        :zoom="zoom"
        @move="onMove"
        @reorder="onReorder"
        @edit="openEdit"
        @add="openAdd"
      />

      <div v-if="chart.note" class="note">{{ chart.note }}</div>
    </template>

    <!-- 項目の追加・編集 -->
    <Transition name="ui-modal">
      <GanttTaskModal
        v-if="taskModal.open && chart"
        :task="taskModal.task"
        :initial-start="taskModal.initialStart"
        :chart-start="chart.startOn"
        :chart-end="chart.endOn"
        @save="saveTask"
        @delete="deleteTask"
        @close="taskModal.open = false"
      />
    </Transition>

    <!-- チャートの設定 -->
    <Transition name="ui-modal">
      <div v-if="settings.open" class="overlay ui-overlay ui-sheet ui-swipe" @click="settings.open = false">
        <div class="modal ui-panel" @click.stop>
          <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">チャートの設定</div>
          <div style="display: flex; flex-direction: column; gap: 13px">
            <label class="fld"><span>名前</span><input v-model="settings.title" @keydown.enter="saveSettings" /></label>
            <div class="dates">
              <label class="fld"><span>表示期間の開始</span><input v-model="settings.startOn" type="date" /></label>
              <label class="fld"><span>表示期間の終了</span><input v-model="settings.endOn" type="date" :min="settings.startOn" /></label>
            </div>
            <div v-if="settingsError" class="err">{{ settingsError }}</div>
            <div v-else class="sub">期間を狭めても項目は消えません（期間外の項目は「表示期間より前／後」と表示）</div>
            <label class="fld"><span>メモ（任意）</span><textarea v-model="settings.note" rows="2"></textarea></label>
          </div>
          <div class="foot">
            <button class="btn-danger" @click="deleteChart">削除</button>
            <button class="btn-ghost" @click="duplicateChart">複製</button>
            <div style="flex: 1"></div>
            <button class="btn-ghost" @click="settings.open = false">キャンセル</button>
            <button class="btn-dark" :disabled="!canSaveSettings" @click="saveSettings">保存</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.left,
.right {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.right {
  flex-wrap: wrap;
}
.back {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 7px 10px 7px 6px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
}
.ttl-btn {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-width: 0;
  padding: 4px 8px;
  margin-left: -8px;
  border: none;
  border-radius: 9px;
  background: transparent;
  cursor: pointer;
  text-align: left;
}
.ttl-btn:hover {
  background: #f1f2f4;
}
.ttl {
  font-size: 17px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.range {
  font-size: 12px;
  color: var(--mut);
  white-space: nowrap;
}
.pen {
  color: #b0b6bf;
  align-self: center;
  flex-shrink: 0;
}
.seg {
  display: flex;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 11px;
  padding: 3px;
}
.seg-btn {
  padding: 6px 13px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.btn-dark {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
.btn-ghost {
  padding: 9px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.btn-danger {
  padding: 9px 14px;
  border: 1px solid #f0c3c8;
  border-radius: 9px;
  background: #fdf3f4;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: #c0444f;
}
.note {
  margin-top: 12px;
  font-size: 12.5px;
  color: var(--mut);
  white-space: pre-line;
  line-height: 1.7;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 24px;
  width: 100%;
  max-width: 460px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
  resize: vertical;
}
.dates {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.err {
  font-size: 12px;
  color: #cf5563;
  margin-top: -6px;
}
.sub {
  font-size: 12px;
  color: var(--faint);
  margin-top: -6px;
  line-height: 1.6;
}
.foot {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 20px;
}
@media (max-width: 600px) {
  .ttl {
    font-size: 15px;
  }
  .ttl-btn {
    flex-wrap: wrap;
    gap: 2px 8px;
  }
}
</style>
