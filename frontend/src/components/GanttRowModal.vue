<script setup lang="ts">
/**
 * ガントチャートの行（科目・学習分野）の追加・編集モーダル。row が null のときは新規追加。
 * グループ（数学・英語など）が変わる所には太い区切り線が入る。
 */
import { computed, reactive } from 'vue'
import type { GanttRow } from '@/types'

const props = defineProps<{ row: GanttRow | null; groups: string[] }>()
const emit = defineEmits<{ save: [payload: { title: string; group: string | null }]; delete: [id: number]; close: [] }>()

const form = reactive({ title: props.row?.title ?? '', group: props.row?.group ?? (props.groups[props.groups.length - 1] ?? '') })
const isNew = computed(() => props.row === null)
const canSave = computed(() => !!form.title.trim())

function submit() {
  if (!canSave.value) return
  emit('save', { title: form.title.trim(), group: form.group.trim() || null })
}
</script>

<template>
  <div class="overlay ui-overlay ui-sheet ui-swipe" @click="emit('close')">
    <div class="modal ui-panel" @click.stop>
      <div style="font-size: 16px; font-weight: 700; margin-bottom: 16px">{{ isNew ? '行を追加' : '行を編集' }}</div>
      <div style="display: flex; flex-direction: column; gap: 13px">
        <label class="fld">
          <span>科目・学習分野</span>
          <input v-model="form.title" placeholder="例: 英語③ 英文解釈" @keydown.enter="submit" />
        </label>
        <label class="fld">
          <span>グループ（任意・同じグループをまとめて表示）</span>
          <input v-model="form.group" list="gantt-groups" placeholder="例: 英語" @keydown.enter="submit" />
          <datalist id="gantt-groups">
            <option v-for="g in groups" :key="g" :value="g"></option>
          </datalist>
        </label>
        <div v-if="groups.length" class="chips">
          <button v-for="g in groups" :key="g" class="chip" :class="{ on: form.group === g }" @click="form.group = g">{{ g }}</button>
        </div>
        <div v-if="!isNew && row?.tasks.length" class="sub">この行には区間が {{ row.tasks.length }} 件あります。行を削除すると区間も削除されます。</div>
      </div>
      <div class="foot">
        <button v-if="!isNew" class="btn-danger" @click="emit('delete', row!.id)">削除</button>
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
  max-width: 440px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.fld input:focus {
  border-color: #b9c2f2;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: -6px;
}
.chip {
  padding: 4px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.sub {
  font-size: 12px;
  color: var(--faint);
  line-height: 1.6;
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
