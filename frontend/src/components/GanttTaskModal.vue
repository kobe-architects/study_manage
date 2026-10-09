<script setup lang="ts">
/**
 * ガントチャートの項目（バー／マイルストーン）の追加・編集モーダル。
 * task が null のときは新規追加。initialStart は空いている場所をダブルクリックしたときの開始日。
 */
import { computed, reactive } from 'vue'
import { parseDate } from '@/lib/design'
import { GANTT_COLORS, addDays, fmtYmd } from '@/lib/gantt'
import { iso } from '@/lib/design'
import { vSeg } from '@/lib/segSlide'
import type { GanttTask, GanttTaskKind } from '@/types'
import type { GanttTaskInput } from '@/api/gantt'

const props = defineProps<{ task: GanttTask | null; initialStart?: string; chartStart: string; chartEnd: string }>()
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
  // 開始日を後ろにずらして終了日を越えたら、終了日も同じだけ動かす
  if (!isMilestone.value && form.endOn && form.endOn < form.startOn) form.endOn = form.startOn
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
  <div class="overlay ui-overlay ui-sheet ui-swipe" @click="emit('close')">
    <div class="modal ui-panel" @click.stop>
      <div class="head">
        <div style="font-size: 16px; font-weight: 700">{{ isNew ? '項目を追加' : '項目を編集' }}</div>
        <div v-seg class="seg">
          <button class="seg-btn" :class="{ on: form.kind === 'task' }" @click="form.kind = 'task'">期間</button>
          <button class="seg-btn" :class="{ on: form.kind === 'milestone' }" @click="form.kind = 'milestone'">節目</button>
        </div>
      </div>

      <div style="display: flex; flex-direction: column; gap: 13px">
        <label class="fld">
          <span>タイトル</span>
          <input v-model="form.title" :placeholder="isMilestone ? '例: 共通テスト' : '例: 数学ⅠA 基礎を固める'" @keydown.enter="submit" />
        </label>

        <div class="dates">
          <label class="fld">
            <span>{{ isMilestone ? '日付' : '開始日' }}</span>
            <input v-model="form.startOn" type="date" @change="onStartChange" />
          </label>
          <label v-if="!isMilestone" class="fld">
            <span>終了日</span>
            <input v-model="form.endOn" type="date" :min="form.startOn" />
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
            <span class="dm" style="font-size: 13px; font-weight: 700" :style="{ color: form.color }">{{ form.progress }}%</span>
          </div>
          <input v-model.number="form.progress" type="range" min="0" max="100" step="5" class="range" :style="{ '--c': form.color }" />
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
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}
.seg {
  display: flex;
  background: #f1f2f4;
  border-radius: 9px;
  padding: 2px;
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
  box-shadow: 0 0 0 2px var(--c);
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.range {
  width: 100%;
  accent-color: var(--c);
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
