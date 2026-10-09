<script setup lang="ts">
/**
 * ガントチャート一覧（リスト形式）。複数のチャート（数年分の計画）を作成・複製・削除し、選んで編集画面へ進む。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import HelpTip from '@/components/HelpTip.vue'
import { ganttApi } from '@/api/gantt'
import { appConfirm } from '@/lib/dialog'
import { defaultChartRange, fmtYm } from '@/lib/gantt'
import { viewportWidth } from '@/lib/native'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { GanttChart } from '@/types'

const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const charts = ref<GanttChart[]>([])
const loading = ref(true)
const isNarrow = computed(() => viewportWidth.value < 700)

async function load() {
  loading.value = true
  try {
    charts.value = await ganttApi.list()
  } catch {
    ui.notify('ガントチャートの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ---- 新規作成 ----
const open = ref(false)
const saving = ref(false)
const form = reactive({ title: '', startOn: '', endOn: '', note: '' })

function openCreate() {
  const r = defaultChartRange(auth.settings?.examDate)
  form.title = ''
  form.startOn = r.startOn
  form.endOn = r.endOn
  form.note = ''
  open.value = true
}
const rangeError = computed(() => (form.startOn && form.endOn && form.endOn < form.startOn ? '終了は開始以降にしてください' : ''))
const canSave = computed(() => !!form.title.trim() && !!form.startOn && !!form.endOn && !rangeError.value)
const yearsLabel = computed(() => {
  if (!form.startOn || !form.endOn || rangeError.value) return ''
  const days = Math.round((new Date(form.endOn).getTime() - new Date(form.startOn).getTime()) / 86400000) + 1
  return `${fmtYm(form.startOn)} 〜 ${fmtYm(form.endOn)}（約${(days / 365).toFixed(1)}年）`
})

async function create() {
  if (!canSave.value || saving.value) return
  saving.value = true
  try {
    const c = await ganttApi.create({ title: form.title.trim(), startOn: form.startOn, endOn: form.endOn, note: form.note.trim() || null })
    open.value = false
    ui.notify('ガントチャートを作成しました')
    router.push({ name: 'gantt-chart', params: { id: c.id } })
  } catch {
    ui.notify('作成に失敗しました')
  } finally {
    saving.value = false
  }
}

function openChart(c: GanttChart) {
  router.push({ name: 'gantt-chart', params: { id: c.id } })
}

async function duplicate(c: GanttChart) {
  try {
    await ganttApi.duplicate(c.id)
    ui.notify('複製しました')
    await load()
  } catch {
    ui.notify('複製に失敗しました')
  }
}

async function remove(c: GanttChart) {
  const msg = c.rowCount ? `「${c.title}」と行${c.rowCount}件を削除しますか？` : `「${c.title}」を削除しますか？`
  if (!(await appConfirm(msg, { danger: true, okText: '削除' }))) return
  try {
    await ganttApi.remove(c.id)
    charts.value = charts.value.filter((x) => x.id !== c.id)
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}

function updatedLabel(c: GanttChart): string {
  if (!c.updatedAt) return ''
  const d = new Date(c.updatedAt.replace(' ', 'T'))
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}
</script>

<template>
  <div>
    <div class="top">
      <div class="ttl">
        <span>ガントチャート</span>
        <HelpTip
          text="数年分の学習計画をガントチャートで立てられます。チャートは複数作れるので、「本番までの全体計画」「夏休みの計画」のように分けて管理できます。行（科目・学習分野）の中に「範囲学習」「復習・演習」「過去問」などの区間を並べ、バーのドラッグで期間を動かせます。画像・PDF・Excel で出力できます。"
        />
      </div>
      <button class="btn-dark" @click="openCreate">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>新規作成
      </button>
    </div>

    <div v-if="loading" class="hint" style="text-align: center">読み込み中…</div>
    <div v-else-if="!charts.length" class="hint" style="text-align: center">
      ガントチャートがまだありません。「新規作成」から、計画の名前と期間（数年分）を決めて作成してください。
    </div>
    <div v-else class="card list">
      <div v-if="!isNarrow" class="lrow head">
        <div class="c-name">名前</div>
        <div class="c-range">表示期間</div>
        <div class="c-count">行 / 区間</div>
        <div class="c-upd">更新</div>
        <div class="c-act"></div>
      </div>
      <div v-for="c in charts" :key="c.id" class="lrow tap" role="button" tabindex="0" @click="openChart(c)" @keydown.enter="openChart(c)">
        <div class="c-name">
          <div class="name">{{ c.title }}</div>
          <div v-if="c.note" class="note">{{ c.note }}</div>
          <div v-if="isNarrow" class="meta">{{ fmtYm(c.startOn) }} 〜 {{ fmtYm(c.endOn) }}・行 {{ c.rowCount }} / 区間 {{ c.taskCount }}・更新 {{ updatedLabel(c) }}</div>
        </div>
        <template v-if="!isNarrow">
          <div class="c-range">{{ fmtYm(c.startOn) }} 〜 {{ fmtYm(c.endOn) }}</div>
          <div class="c-count dm">{{ c.rowCount }} / {{ c.taskCount }}</div>
          <div class="c-upd">{{ updatedLabel(c) }}</div>
        </template>
        <div class="c-act" @click.stop>
          <button class="pill primary" @click="openChart(c)">開く</button>
          <button class="pill" @click="duplicate(c)">複製</button>
          <button class="pill danger" @click="remove(c)">削除</button>
        </div>
      </div>
    </div>

    <!-- 新規作成 -->
    <Transition name="ui-modal">
      <div v-if="open" class="overlay ui-overlay ui-sheet ui-swipe" @click="open = false">
        <div class="modal ui-panel" @click.stop>
          <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">ガントチャートを作成</div>
          <div style="display: flex; flex-direction: column; gap: 13px">
            <label class="fld"><span>名前</span><input v-model="form.title" placeholder="例: 受験本番までの全体計画" @keydown.enter="create" /></label>
            <div class="dates">
              <label class="fld"><span>表示期間の開始</span><input v-model="form.startOn" type="date" /></label>
              <label class="fld"><span>表示期間の終了</span><input v-model="form.endOn" type="date" :min="form.startOn" /></label>
            </div>
            <div v-if="rangeError" class="err">{{ rangeError }}</div>
            <div v-else-if="yearsLabel" class="sub">{{ yearsLabel }}・期間は後から変更できます</div>
            <label class="fld"><span>メモ（任意）</span><textarea v-model="form.note" rows="2" placeholder="この計画のねらいなど"></textarea></label>
          </div>
          <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
            <button class="btn-ghost" @click="open = false">キャンセル</button>
            <button class="btn-dark" :disabled="!canSave || saving" @click="create">作成する</button>
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
  margin-bottom: 16px;
}
.ttl {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 17px;
  font-weight: 700;
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
  padding: 9px 18px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}

/* ---- リスト ---- */
.list {
  overflow: hidden;
}
.lrow {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 18px;
  border-bottom: 1px solid #f0f1f3;
  cursor: pointer;
  outline: none;
}
.lrow:last-child {
  border-bottom: none;
}
.lrow:hover {
  background: #fafbfc;
}
.lrow:focus-visible {
  box-shadow: inset 0 0 0 2px #b9c2f2;
}
.lrow.head {
  cursor: default;
  padding: 9px 18px;
  background: #f6f7f9;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
}
.lrow.head:hover {
  background: #f6f7f9;
}
.c-name {
  flex: 1;
  min-width: 0;
}
.name {
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.note {
  font-size: 11.5px;
  color: var(--faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}
.meta {
  font-size: 11px;
  color: var(--faint);
  margin-top: 3px;
}
.c-range {
  width: 190px;
  flex-shrink: 0;
  font-size: 12.5px;
  color: var(--mut);
  white-space: nowrap;
}
.c-count {
  width: 80px;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
}
.head .c-count {
  font-weight: 700;
  font-size: 11.5px;
  color: var(--mut);
}
.c-upd {
  width: 120px;
  flex-shrink: 0;
  font-size: 12px;
  color: var(--faint);
  white-space: nowrap;
}
.c-act {
  flex-shrink: 0;
  display: flex;
  gap: 6px;
}
.pill {
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.pill:hover {
  background: #f6f7f9;
  color: var(--ink);
}
.pill.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.pill.danger {
  color: #c0444f;
  border-color: #f0c3c8;
}
.pill.danger:hover {
  background: #fdf3f4;
}
@media (max-width: 700px) {
  .lrow {
    flex-wrap: wrap;
    padding: 12px 14px;
  }
  .c-act {
    width: 100%;
    justify-content: flex-end;
  }
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
}
</style>
