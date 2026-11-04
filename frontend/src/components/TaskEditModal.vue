<script setup lang="ts">
/**
 * タスクの追加・編集モーダル。
 * 科目・タイトル・量（140 words など）・期間（1 日あたり／1 週あたり）・取り組む曜日・開始日・終了日・メモ。
 * 下に「日別ではこう表示される」のプレビューを出す。
 */
import { computed, reactive } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import { iso } from '@/lib/design'
import { fmtAmount, UNIT_SUGGESTIONS, WEEKDAY_ORDER, WEEKDAYS } from '@/lib/tasks'
import { useUiStore } from '@/stores/ui'
import type { StudyTask, StudyTaskInput, TaskPeriod, TaskSubject } from '@/types'

const props = defineProps<{
  /** null は新規 */
  task: StudyTask | null
  subjects: TaskSubject[]
  defaultSubjectId?: number | null
  saving?: boolean
}>()
const emit = defineEmits<{ save: [payload: StudyTaskInput]; close: [] }>()

const ui = useUiStore()

const form = reactive({
  title: props.task?.title ?? '',
  subjectId: (props.task ? props.task.subjectId : props.defaultSubjectId ?? null) as number | null,
  amount: props.task?.amount ?? 1,
  unit: props.task?.unit ?? '',
  period: (props.task?.period ?? 'week') as TaskPeriod,
  weekdays: (props.task?.weekdays && props.task.weekdays.length ? [...props.task.weekdays] : [...WEEKDAY_ORDER]) as number[],
  startOn: props.task?.startOn ?? iso(new Date()),
  endOn: props.task?.endOn ?? '',
  note: props.task?.note ?? '',
})

const isNew = computed(() => props.task === null)
const everyday = computed(() => form.weekdays.length === 7)
function toggleWd(d: number) {
  if (form.weekdays.includes(d)) form.weekdays = form.weekdays.filter((x) => x !== d)
  else form.weekdays = [...form.weekdays, d]
}
function setAll() {
  form.weekdays = [...WEEKDAY_ORDER]
}
function setWeekdaysOnly() {
  form.weekdays = [1, 2, 3, 4, 5]
}

/** 日別の表示のプレビュー */
const preview = computed(() => {
  const amount = Math.floor(Number(form.amount))
  if (!Number.isFinite(amount) || amount < 1) return ''
  const unit = form.unit.trim()
  const title = form.title.trim() || 'タスク'
  const n = form.weekdays.length
  if (!n) return '取り組む曜日を 1 つ以上選んでください'
  if (form.period === 'day') return `日別の表示: ${title} ${fmtAmount(amount, unit)}`
  const lo = Math.floor(amount / n)
  const hi = Math.ceil(amount / n)
  const q = lo === hi ? String(lo) : `${lo}〜${hi}`
  return `日別の表示: ${title} ${fmtAmount(q, unit)}（${fmtAmount(amount, unit)} を ${n === 7 ? '毎日' : `週 ${n} 日`}に分けて）`
})

function submit() {
  const title = form.title.trim()
  const amount = Math.floor(Number(form.amount))
  if (!title) {
    ui.notify('タイトルを入力してください')
    return
  }
  if (!Number.isFinite(amount) || amount < 1) {
    ui.notify('量は 1 以上の数を入力してください')
    return
  }
  if (!form.unit.trim()) {
    ui.notify('単位を入力してください（words・単元・ページ など）')
    return
  }
  if (!form.weekdays.length) {
    ui.notify('取り組む曜日を 1 つ以上選んでください')
    return
  }
  if (!form.startOn) {
    ui.notify('開始日を入力してください')
    return
  }
  if (form.endOn && form.endOn < form.startOn) {
    ui.notify('終了日は開始日以降にしてください')
    return
  }
  emit('save', {
    title,
    subjectId: form.subjectId,
    amount,
    unit: form.unit.trim(),
    period: form.period,
    weekdays: form.weekdays.length === 7 ? null : [...form.weekdays].sort((a, b) => a - b),
    startOn: form.startOn,
    endOn: form.endOn || null,
    note: form.note.trim() || null,
  })
}
</script>

<template>
  <Teleport to="body">
    <div class="te-bg ui-overlay ui-sheet">
      <div class="te-modal ui-panel">
        <div class="te-title">{{ isNew ? 'タスクを追加' : 'タスクを編集' }}<HelpTip text="科目ごとの「やるべきこと」を、1 日あたり または 1 週あたりの量で登録します。1 週あたりのタスクは取り組む曜日の数で割って日別に表示します（例: 140 words / 週・毎日 → 20 words）。" /></div>

        <label class="fld">
          <span>科目</span>
          <select v-model="form.subjectId">
            <option :value="null">その他（科目なし）</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>

        <label class="fld">
          <span>タイトル</span>
          <input v-model="form.title" type="text" placeholder="例: 英単語 LEAP basic ／ 英文法 テキスト" maxlength="255" @keydown.enter.prevent="submit" />
        </label>

        <div class="te-row">
          <label class="fld" style="flex: 1 1 110px">
            <span>量</span>
            <input v-model.number="form.amount" type="number" inputmode="numeric" min="1" step="1" placeholder="140" />
          </label>
          <label class="fld" style="flex: 1 1 130px">
            <span>単位</span>
            <input v-model="form.unit" type="text" list="te-units" placeholder="words ／ 単元 ／ ページ" maxlength="30" />
            <datalist id="te-units">
              <option v-for="u in UNIT_SUGGESTIONS" :key="u" :value="u" />
            </datalist>
          </label>
          <div class="fld" style="flex: 0 0 auto">
            <span>期間</span>
            <div v-seg class="te-seg">
              <button type="button" class="te-seg-btn" :class="{ on: form.period === 'day' }" @click="form.period = 'day'">1 日あたり</button>
              <button type="button" class="te-seg-btn" :class="{ on: form.period === 'week' }" @click="form.period = 'week'">1 週あたり</button>
            </div>
          </div>
        </div>

        <div class="fld">
          <span class="te-wd-head">
            取り組む曜日
            <button type="button" class="te-mini" :class="{ on: everyday }" @click="setAll">毎日</button>
            <button type="button" class="te-mini" :class="{ on: form.weekdays.length === 5 && [1, 2, 3, 4, 5].every((d) => form.weekdays.includes(d)) }" @click="setWeekdaysOnly">平日</button>
          </span>
          <div class="te-wds">
            <button
              v-for="d in WEEKDAY_ORDER"
              :key="d"
              type="button"
              class="te-wd"
              :class="{ on: form.weekdays.includes(d), sat: d === 6, sun: d === 0 }"
              @click="toggleWd(d)"
            >{{ WEEKDAYS[d] }}</button>
          </div>
        </div>

        <div class="te-row">
          <label class="fld" style="flex: 1 1 140px"><span>開始日</span><input v-model="form.startOn" type="date" /></label>
          <label class="fld" style="flex: 1 1 140px"><span>終了日（任意）</span><input v-model="form.endOn" type="date" :min="form.startOn" /></label>
        </div>

        <label class="fld">
          <span>メモ（任意）</span>
          <textarea v-model="form.note" rows="2" maxlength="2000" placeholder="例: Part 1 → Part 2 の順に"></textarea>
        </label>

        <div v-if="preview" class="te-preview">{{ preview }}</div>

        <div class="te-actions">
          <button type="button" class="te-btn-out" @click="emit('close')">キャンセル</button>
          <button type="button" class="te-btn-dark" :disabled="saving" @click="submit">{{ isNew ? '追加' : '保存' }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.te-bg {
  position: fixed;
  inset: 0;
  background: rgba(20, 22, 26, 0.42);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
  padding: 16px;
}
.te-modal {
  background: #fff;
  border-radius: 15px;
  padding: 22px;
  width: 100%;
  max-width: 520px;
  max-height: 100%;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.te-title {
  font-size: 16px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}
.fld {
  display: block;
}
.fld > span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld select,
.fld textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
  color: var(--ink);
}
.fld textarea {
  resize: vertical;
  line-height: 1.5;
}
.fld input:focus,
.fld select:focus,
.fld textarea:focus {
  border-color: #b9c1cc;
}
.te-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: flex-end;
}
.te-seg {
  display: flex;
  background: #f3f4f6;
  border-radius: 9px;
  padding: 3px;
  height: 37px;
}
.te-seg-btn {
  padding: 0 12px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.te-seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.te-wd-head {
  display: flex !important;
  align-items: center;
  gap: 8px;
}
.te-mini {
  padding: 1px 8px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  color: var(--mut);
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
}
.te-mini.on {
  border-color: #1c2024;
  color: #1c2024;
}
.te-wds {
  display: flex;
  gap: 6px;
}
.te-wd {
  flex: 1;
  min-width: 0;
  height: 36px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.te-wd.sat {
  color: #2563eb;
}
.te-wd.sun {
  color: #d92d20;
}
.te-wd.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.te-preview {
  font-size: 12px;
  color: #3a8a5c;
  background: #eef7f0;
  border-radius: 9px;
  padding: 8px 11px;
  line-height: 1.5;
}
.te-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
  margin-top: 4px;
}
.te-btn-out {
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.te-btn-dark {
  padding: 9px 18px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.te-btn-dark:disabled {
  opacity: 0.5;
  cursor: default;
}
</style>
