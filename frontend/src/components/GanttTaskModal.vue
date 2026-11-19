<script setup lang="ts">
/**
 * ガントチャートの区間（期間のバー／節目）の追加・編集モーダル。
 * task が null のときは新規追加。initialStart は行の空いている場所をダブルクリックしたときの開始日。
 */
import { computed, reactive } from 'vue'
import { iso, parseDate } from '@/lib/design'
import { GANTT_COLORS, GANTT_PHASES, addDays, fmtYmd, isDark } from '@/lib/gantt'
import { vSeg } from '@/lib/segSlide'
import type { GanttRow, GanttTask, GanttTaskKind } from '@/types'
import type { GanttTaskInput } from '@/api/gantt'

const props = defineProps<{ task: GanttTask | null; row: GanttRow; initialStart?: string; chartStart: string; chartEnd: string }>()
const emit = defineEmits<{ save: [payload: GanttTaskInput & { title: string; startOn: string }]; delete: [id: number]; close: [] }>()

const start = props.task?.startOn ?? props.initialStart ?? props.chartStart
const form = reactive({
  kind: (props.task?.kind ?? 'task') as GanttTaskKind,
  title: props.task?.title ?? '',
  startOn: start,
  endOn: props.task?.endOn ?? iso(addDays(parseDate(start), 29)),
  color: props.task?.color ?? GANTT_COLORS[0]!,
  progress: props.task?.progress ?? 0,
  note: props.task?.note ?? '',
})

const isNew = computed(() => props.task === null)
const isMilestone = computed(() => form.kind === 'milestone')
const days = computed(() => {
  if (isMilestone.value || !form.startOn || !form.endOn) return 0
  return Math.round((parseDate(form.endOn).getTime() - parseDate(form.startOn).getTime()) / 86400000) + 1
})
const dateError = computed(() => (!isMilestone.value && form.startOn && form.endOn && form.endOn < form.startOn ? '終了日は開始日以降にしてください' : ''))
const canSave = computed(() => !!form.title.trim() && !!form.startOn && (isMilestone.value || (!!form.endOn && !dateError.value)))

function onStartChange() {
  if (!isMilestone.value && form.endOn && form.endOn < form.startOn) form.endOn = form.startOn
}

// ---- 期間の調整ボタン（iPad などで日付の入力欄を触らずに動かせるように） ----
type DateField = 'startOn' | 'endOn'
/** 開始日／終了日を n 日ずらす。開始日を終了日より後にしたときは終了日も同じ日に、終了日を開始日より前にしたときは開始日に止める */
function shift(field: DateField, n: number) {
  if (!form[field]) return
  form[field] = iso(addDays(parseDate(form[field]), n))
  if (isMilestone.value) return
  if (field === 'startOn') onStartChange()
  else if (form.endOn < form.startOn) form.endOn = form.startOn
}
/** 開始日をその月の 1 日に（すでに 1 日なら前の月の 1 日に） */
function toMonthStart() {
  if (!form.startOn) return
  const d = parseDate(form.startOn)
  form.startOn = iso(d.getDate() === 1 ? new Date(d.getFullYear(), d.getMonth() - 1, 1) : new Date(d.getFullYear(), d.getMonth(), 1))
}
/** 終了日をその月の末日に（すでに末日なら次の月の末日に） */
function toMonthEnd() {
  if (!form.endOn) return
  const d = parseDate(form.endOn)
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0)
  form.endOn = iso(d.getDate() === last.getDate() ? new Date(d.getFullYear(), d.getMonth() + 2, 0) : last)
  if (form.endOn < form.startOn) form.startOn = form.endOn
}
/** 日数を直接入れて終了日を決める */
function onDaysInput(e: Event) {
  const n = Math.floor(Number((e.target as HTMLInputElement).value))
  if (!form.startOn || !Number.isFinite(n) || n < 1) return
  form.endOn = iso(addDays(parseDate(form.startOn), Math.min(3650, n) - 1))
}
/** ひな形（範囲学習など）: 名前と色をまとめて入れる */
function applyPhase(p: { label: string; color: string }) {
  form.title = p.label
  form.color = p.color
  if (form.kind === 'milestone') form.kind = 'task'
}

function submit() {
  if (!canSave.value) return
  emit('save', {
    kind: form.kind,
    title: form.title.trim(),
    startOn: form.startOn,
    endOn: isMilestone.value ? form.startOn : form.endOn,
    color: form.color,
    progress: isMilestone.value ? 0 : Math.max(0, Math.min(100, Math.round(form.progress))),
    note: form.note.trim() || null,
  })
}
</script>

<template>
  <div class="overlay ui-overlay ui-sheet">
    <div class="modal ui-panel" @click.stop>
      <div class="head">
        <div>
          <div style="font-size: 16px; font-weight: 700">{{ isNew ? '区間を追加' : '区間を編集' }}</div>
          <div class="rowname">{{ row.title }}</div>
        </div>
        <div v-seg class="seg">
          <button class="seg-btn" :class="{ on: form.kind === 'task' }" @click="form.kind = 'task'">期間</button>
          <button class="seg-btn" :class="{ on: form.kind === 'milestone' }" @click="form.kind = 'milestone'">節目</button>
        </div>
      </div>

      <div style="display: flex; flex-direction: column; gap: 13px">
        <div v-if="!isMilestone">
          <span class="fld-label">ひな形</span>
          <div class="phases">
            <button
              v-for="p in GANTT_PHASES"
              :key="p.label"
              class="phase"
              :class="{ on: form.title === p.label && form.color === p.color, dark: isDark(p.color) }"
              :style="{ '--c': p.color }"
              @click="applyPhase(p)"
            >
              {{ p.label }}
            </button>
          </div>
        </div>

        <label class="fld">
          <span>名前</span>
          <input v-model="form.title" :placeholder="isMilestone ? '例: 共通テスト' : '例: 範囲学習'" @keydown.enter="submit" />
        </label>

        <div class="dates" :class="{ single: isMilestone }">
          <div class="dcol">
            <label class="fld">
              <span>{{ isMilestone ? '日付' : '開始日' }}</span>
              <input v-model="form.startOn" type="date" @change="onStartChange" />
            </label>
            <div class="steps">
              <button class="step" title="10日前へ" @click="shift('startOn', -10)">−10</button>
              <button class="step" title="1日前へ" @click="shift('startOn', -1)">−1</button>
              <button class="step" title="1日後へ" @click="shift('startOn', 1)">+1</button>
              <button class="step" title="10日後へ" @click="shift('startOn', 10)">+10</button>
              <button class="step edge" title="その月の 1 日に（すでに 1 日なら前の月の 1 日に）" @click="toMonthStart">月初</button>
            </div>
          </div>
          <div v-if="!isMilestone" class="dcol">
            <label class="fld">
              <span>終了日</span>
              <input v-model="form.endOn" type="date" :min="form.startOn" />
            </label>
            <div class="steps">
              <button class="step" title="10日前へ" @click="shift('endOn', -10)">−10</button>
              <button class="step" title="1日前へ" @click="shift('endOn', -1)">−1</button>
              <button class="step" title="1日後へ" @click="shift('endOn', 1)">+1</button>
              <button class="step" title="10日後へ" @click="shift('endOn', 10)">+10</button>
              <button class="step edge" title="その月の末日に（すでに末日なら次の月の末日に）" @click="toMonthEnd">月末</button>
            </div>
          </div>
          <label v-if="!isMilestone" class="fld dcol days">
            <span>日数</span>
            <input :value="days > 0 ? days : ''" type="number" min="1" inputmode="numeric" @change="onDaysInput" />
          </label>
        </div>
        <div v-if="dateError" class="err">{{ dateError }}</div>
        <div v-else-if="!isMilestone && days > 0" class="sub">{{ fmtYmd(form.startOn) }} 〜 {{ fmtYmd(form.endOn) }}・{{ days }}日間</div>

        <div>
          <span class="fld-label">色</span>
          <div class="colors">
            <button
              v-for="c in GANTT_COLORS"
              :key="c"
              class="sw"
              :class="{ on: form.color === c }"
              :style="{ '--c': c }"
              :aria-label="c"
              @click="form.color = c"
            ></button>
          </div>
        </div>

        <div v-if="!isMilestone">
          <div class="row-between">
            <span class="fld-label" style="margin-bottom: 0">進捗</span>
            <span class="dm" style="font-size: 13px; font-weight: 700">{{ form.progress }}%</span>
          </div>
          <input v-model.number="form.progress" type="range" min="0" max="100" step="5" class="range" />
        </div>

        <label class="fld">
          <span>メモ（任意）</span>
          <textarea v-model="form.note" rows="2" placeholder="使う教材・やり方など"></textarea>
        </label>
      </div>

      <div class="foot">
        <button v-if="!isNew" class="btn-danger" @click="emit('delete', task!.id)">削除</button>
        <div style="flex: 1"></div>
        <button class="btn-ghost" @click="emit('close')">キャンセル</button>
        <button class="btn-dark" :disabled="!canSave" @click="submit">{{ isNew ? '追加する' : '保存' }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
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
  padding: 22px 24px;
  width: 100%;
  max-width: 460px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}
.rowname {
  font-size: 12px;
  color: var(--faint);
  margin-top: 2px;
}
.seg {
  display: flex;
  background: #f1f2f4;
  border-radius: 9px;
  padding: 2px;
  flex-shrink: 0;
}
.seg-btn {
  padding: 5px 13px;
  border: none;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg-btn.on {
  background: #fff;
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.phases {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.phase {
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: var(--c);
  color: var(--ink);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.phase.dark {
  color: #fff;
}
.phase.on {
  box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--primary);
}
.fld span,
.fld-label {
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
.fld input:focus,
.fld textarea:focus {
  border-color: #b9c2f2;
}
.dates {
  display: grid;
  grid-template-columns: 1fr 1fr 64px;
  gap: 10px;
  align-items: start;
}
.dates.single {
  grid-template-columns: 1fr;
}
.dcol {
  min-width: 0;
}
.steps {
  display: flex;
  gap: 4px;
  margin-top: 5px;
}
.step {
  flex: 1;
  min-width: 0;
  padding: 5px 0;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  background: #f8f9fb;
  color: var(--mut);
  font-size: 11.5px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  white-space: nowrap;
}
.step:active {
  background: #e9ecf1;
}
.step.edge {
  flex: 1.3;
  background: #fff;
}
.days input {
  text-align: center;
  padding-left: 6px;
  padding-right: 6px;
}
@media (max-width: 520px) {
  .dates {
    grid-template-columns: 1fr 1fr;
  }
  .dates .days {
    grid-column: 1 / -1;
    max-width: 120px;
  }
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
.colors {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sw {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 2px solid #fff;
  background: var(--c);
  cursor: pointer;
  box-shadow: 0 0 0 1px #e3e6ea;
  padding: 0;
}
.sw.on {
  box-shadow: 0 0 0 2px var(--primary);
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.range {
  width: 100%;
  accent-color: var(--primary);
  margin: 4px 0 0;
}
.foot {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 20px;
}
.btn-dark {
  padding: 9px 18px;
  border: none;
  border-radius: 9px;
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
</style>
