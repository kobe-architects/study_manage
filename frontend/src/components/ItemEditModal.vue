<script setup lang="ts">
import { computed, ref } from 'vue'
import type { StudyItemRow } from '@/types'

const props = defineProps<{
  addMode: boolean
  name: string
  meta: string
  items: StudyItemRow[]
}>()
const emit = defineEmits<{ save: [name: string]; add: [midId: number, name: string]; close: [] }>()

const value = ref(props.name)

// add mode cascade
const subjectId = ref(props.items[0]?.subjectId ?? 0)
const major = ref('')

const subjects = computed(() => {
  const seen = new Map<number, string>()
  props.items.forEach((i) => seen.set(i.subjectId, i.subjectName))
  return [...seen.entries()].map(([id, name]) => ({ id, name }))
})
const majors = computed(() => {
  const s = new Set<string>()
  props.items.filter((i) => i.subjectId === subjectId.value).forEach((i) => s.add(i.major))
  const arr = [...s]
  if (!arr.includes(major.value)) major.value = arr[0] ?? ''
  return arr
})
const mids = computed(() => {
  const s = new Map<string, number>()
  props.items
    .filter((i) => i.subjectId === subjectId.value && i.major === major.value)
    .forEach((i) => s.set(i.mid, i.midId))
  const arr = [...s.entries()].map(([name, id]) => ({ name, id }))
  if (!arr.find((m) => m.id === midSel.value)) midSel.value = arr[0]?.id ?? 0
  return arr
})
const midSel = ref(0)

function save() {
  if (props.addMode) {
    if (!value.value.trim() || !midSel.value) return
    emit('add', midSel.value, value.value.trim())
  } else {
    if (!value.value.trim()) return
    emit('save', value.value.trim())
  }
}
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px">{{ addMode ? '学習項目を追加' : '学習項目を編集' }}</div>
      <div v-if="!addMode" style="font-size: 12px; color: var(--faint); margin-bottom: 18px">{{ meta }}</div>

      <template v-if="addMode">
        <label class="fld"><span>科目</span>
          <select v-model.number="subjectId"><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        </label>
        <label class="fld"><span>大分類</span>
          <select v-model="major"><option v-for="m in majors" :key="m" :value="m">{{ m }}</option></select>
        </label>
        <label class="fld"><span>中分類</span>
          <select v-model.number="midSel"><option v-for="m in mids" :key="m.id" :value="m.id">{{ m.name }}</option></select>
        </label>
      </template>

      <label class="fld"><span>小分類名</span>
        <input v-model="value" placeholder="学習項目名" @keydown.enter="save" />
      </label>

      <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
        <button class="btn-ghost" @click="emit('close')">キャンセル</button>
        <button class="btn-dark" @click="save">{{ addMode ? '追加する' : '保存' }}</button>
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
  padding: 24px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.fld {
  display: block;
  margin-bottom: 14px;
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 6px;
}
.fld select,
.fld input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #d8dce1;
  border-radius: 10px;
  font-size: 14px;
  outline: none;
  background: #fff;
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
.btn-dark {
  padding: 9px 18px;
  border: none;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}
</style>
