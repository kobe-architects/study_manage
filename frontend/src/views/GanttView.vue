<script setup lang="ts">
/**
 * 計画表（ガントチャート）の一覧（リスト形式）。複数の計画表（数年分の計画）を作成・複製・削除し、選んで編集画面へ進む。
 * 講師ログインでは講師自身の計画表（生徒側とは別のデータ）を扱い、生徒の計画表を複製して使い始められる。
 * 生徒側では、講師が登録した計画表も「講師の計画表」として一覧に出す（閲覧のみ。開くと編集できない表示になる）。
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
/** 生徒側のみ: 講師が登録した計画表（閲覧のみ） */
const tutorCharts = ref<GanttChart[]>([])
const loading = ref(true)
const isNarrow = computed(() => viewportWidth.value < 700)
const isTutor = computed(() => auth.isTutor)
/** 編集画面のルート名（講師は /tutor 配下） */
const chartRoute = computed(() => (isTutor.value ? 'tutor-gantt-chart' : 'gantt-chart'))
/** 受験日（既定の表示期間に使う。講師は担当生徒の受験日） */
const examDate = computed(() => (isTutor.value ? auth.user?.student?.examDate : auth.settings?.examDate) ?? null)

async function load() {
  loading.value = true
  try {
    const [own, fromTutor] = await Promise.all([ganttApi.list(), isTutor.value ? Promise.resolve([]) : ganttApi.tutorCharts().catch(() => [])])
    charts.value = own
    tutorCharts.value = fromTutor
  } catch {
    ui.notify('計画表の取得に失敗しました')
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
  const r = defaultChartRange(examDate.value)
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
    ui.notify('計画表を作成しました')
    router.push({ name: chartRoute.value, params: { id: c.id } })
  } catch {
    ui.notify('作成に失敗しました')
  } finally {
    saving.value = false
  }
}

function openChart(c: GanttChart) {
  router.push({ name: chartRoute.value, params: { id: c.id } })
}

// ---- 講師: 生徒の計画表から複製 ----
const pick = reactive<{ open: boolean; loading: boolean; charts: GanttChart[]; busyId: number | null }>({ open: false, loading: false, charts: [], busyId: null })
async function openPick() {
  pick.open = true
  pick.loading = true
  try {
    pick.charts = await ganttApi.studentCharts()
  } catch {
    ui.notify('生徒の計画表の取得に失敗しました')
  } finally {
    pick.loading = false
  }
}
async function copyFromStudent(c: GanttChart) {
  if (pick.busyId) return
  pick.busyId = c.id
  try {
    const created = await ganttApi.copyFromStudent(c.id)
    pick.open = false
    ui.notify(`「${c.title}」を複製しました`)
    router.push({ name: chartRoute.value, params: { id: created.id } })
  } catch {
    ui.notify('複製に失敗しました')
  } finally {
    pick.busyId = null
  }
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
        <span>計画表</span>
        <HelpTip
          :text="
            (isTutor
              ? 'ここに作る計画表は講師用で、生徒の画面の計画表とは別に保存されます。「生徒の計画表から複製」で生徒の計画表（受験本番までの学習計画 など）を写して、先生の案として手直しできます。'
              : '数年分の学習計画を計画表（ガントチャート）で立てられます。計画表は複数作れるので、「本番までの全体計画」「夏休みの計画」のように分けて管理できます。') +
            '行（科目・学習分野）の中に「範囲学習」「復習・演習」「過去問」などの区間を並べ、バーのドラッグで期間を動かせます。画像・PDF・Excel で出力できます。'
          "
        />
      </div>
      <div class="actions">
        <button v-if="isTutor" class="btn-ghost" @click="openPick">生徒の計画表から複製</button>
        <button class="btn-dark" @click="openCreate">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>新規作成
        </button>
      </div>
    </div>

    <div v-if="loading" class="hint" style="text-align: center">読み込み中…</div>
    <div v-else-if="!charts.length && !tutorCharts.length" class="hint" style="text-align: center">
      計画表がまだありません。<template v-if="isTutor">「生徒の計画表から複製」で生徒の計画表を写すか、</template>「新規作成」から、計画の名前と期間（数年分）を決めて作成してください。
    </div>
    <div v-else-if="!charts.length" class="hint" style="text-align: center">自分の計画表がまだありません。「新規作成」から、計画の名前と期間（数年分）を決めて作成してください。</div>
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

    <!-- 生徒側: 講師が登録した計画表（閲覧のみ） -->
    <template v-if="!loading && tutorCharts.length">
      <div class="ttl sec">
        <span>講師の計画表</span>
        <span class="ro-badge">閲覧のみ</span>
        <HelpTip text="講師が講師の画面で作った計画表です。開いて内容を見たり、画像・PDF・Excel で出力したりできますが、行や区間の変更はできません。自分の計画として手直ししたいときは講師に相談してください。" />
      </div>
      <div class="card list">
        <div v-if="!isNarrow" class="lrow head">
          <div class="c-name">名前</div>
          <div class="c-range">表示期間</div>
          <div class="c-count">行 / 区間</div>
          <div class="c-upd">更新</div>
          <div class="c-act"></div>
        </div>
        <div v-for="c in tutorCharts" :key="c.id" class="lrow tap" role="button" tabindex="0" @click="openChart(c)" @keydown.enter="openChart(c)">
          <div class="c-name">
            <div class="name">{{ c.title }}</div>
            <div class="note">講師: {{ c.tutorName ?? '—' }}<template v-if="c.note">・{{ c.note }}</template></div>
            <div v-if="isNarrow" class="meta">{{ fmtYm(c.startOn) }} 〜 {{ fmtYm(c.endOn) }}・行 {{ c.rowCount }} / 区間 {{ c.taskCount }}・更新 {{ updatedLabel(c) }}</div>
          </div>
          <template v-if="!isNarrow">
            <div class="c-range">{{ fmtYm(c.startOn) }} 〜 {{ fmtYm(c.endOn) }}</div>
            <div class="c-count dm">{{ c.rowCount }} / {{ c.taskCount }}</div>
            <div class="c-upd">{{ updatedLabel(c) }}</div>
          </template>
          <div class="c-act" @click.stop>
            <button class="pill primary" @click="openChart(c)">開く</button>
          </div>
        </div>
      </div>
    </template>

    <!-- 新規作成 -->
    <Transition name="ui-modal">
      <div v-if="open" class="overlay ui-overlay ui-sheet">
        <div class="modal ui-panel" @click.stop>
          <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">計画表を作成</div>
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

    <!-- 講師: 生徒の計画表から複製 -->
    <Transition name="ui-modal">
      <div v-if="pick.open" class="overlay ui-overlay ui-sheet" @click="pick.open = false">
        <div class="modal ui-panel" @click.stop>
          <div style="font-size: 16px; font-weight: 700; margin-bottom: 6px">生徒の計画表から複製</div>
          <div class="sub" style="margin: 0 0 14px">生徒の計画表（行・区間ごと）を講師用の計画表として写します。写した後の変更は生徒の計画表には影響しません。</div>
          <div v-if="pick.loading" class="hint" style="text-align: center">読み込み中…</div>
          <div v-else-if="!pick.charts.length" class="hint" style="text-align: center">生徒の計画表がありません。</div>
          <div v-else class="pick-list">
            <div v-for="c in pick.charts" :key="c.id" class="pick-row">
              <div style="flex: 1; min-width: 0">
                <div class="name">{{ c.title }}</div>
                <div class="meta">{{ fmtYm(c.startOn) }} 〜 {{ fmtYm(c.endOn) }}・行 {{ c.rowCount }} / 区間 {{ c.taskCount }}・更新 {{ updatedLabel(c) }}</div>
              </div>
              <button class="pill primary" :disabled="pick.busyId !== null" @click="copyFromStudent(c)">{{ pick.busyId === c.id ? '複製中…' : '複製' }}</button>
            </div>
          </div>
          <div style="display: flex; justify-content: flex-end; margin-top: 18px">
            <button class="btn-ghost" @click="pick.open = false">閉じる</button>
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
.ttl.sec {
  margin: 26px 0 12px;
  font-size: 15px;
}
.ro-badge {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--mut);
  background: #eef0f3;
  border-radius: 99px;
  padding: 2px 8px;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}
.pick-list {
  border: 1px solid #eceef1;
  border-radius: 12px;
  overflow: hidden;
  max-height: 50vh;
  overflow-y: auto;
}
.pick-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  border-bottom: 1px solid #f0f1f3;
}
.pick-row:last-child {
  border-bottom: none;
}
.pill:disabled {
  opacity: 0.5;
  cursor: default;
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
