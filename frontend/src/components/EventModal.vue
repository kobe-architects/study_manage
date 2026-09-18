<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { parseDate } from '@/lib/design'
import type { CalendarEvent } from '@/types'

/**
 * カレンダーの予定（1日分）の登録・編集モーダル。
 * その日の予定を一覧（登録者名つき）で表示し、追加・編集・削除ができる。1日に複数登録可。
 */
const props = defineProps<{ date: string; events: CalendarEvent[] }>()
const emit = defineEmits<{ save: [id: number | null, title: string, isMock: boolean]; delete: [id: number]; close: [] }>()

const editingId = ref<number | null>(null)
const value = ref('')
const mock = ref(false)
const saving = ref(false)

const d = parseDate(props.date)
const label = `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`
const list = computed(() => props.events)

function startEdit(e: CalendarEvent) {
  editingId.value = e.id
  value.value = e.title
  mock.value = e.isMock
}
function cancelEdit() {
  editingId.value = null
  value.value = ''
  mock.value = false
}
async function submit() {
  if (!value.value.trim() || saving.value) return
  saving.value = true
  try {
    emit('save', editingId.value, value.value.trim(), mock.value)
  } finally {
    saving.value = false
  }
}
// 保存されて一覧が更新されたら入力欄を戻す
watch(
  () => props.events.map((e) => `${e.id}:${e.title}:${e.isMock}`).join('|'),
  () => cancelEdit(),
)
</script>

<template>
  <div class="overlay">
    <div class="modal">
      <div class="head">
        <div>
          <div style="font-size: 16px; font-weight: 700">予定</div>
          <div style="font-size: 12px; color: var(--faint); margin-top: 2px">{{ label }}</div>
        </div>
        <button class="x" @click="emit('close')">×</button>
      </div>

      <div v-if="list.length" class="list">
        <div v-for="e in list" :key="e.id" class="item" :class="{ editing: editingId === e.id }">
          <div class="item-main">
            <div class="item-title"><span v-if="e.isMock" class="mock-tag">模試</span>{{ e.title }}</div>
            <div class="item-by">登録: {{ e.createdByName ?? '不明' }}</div>
          </div>
          <button class="mini" @click="startEdit(e)">編集</button>
          <button class="mini danger" @click="emit('delete', e.id)">削除</button>
        </div>
      </div>
      <div v-else class="empty">この日の予定はまだありません</div>

      <div class="form">
        <div class="form-title">{{ editingId === null ? '予定を追加' : '予定を編集' }}</div>
        <input v-model="value" placeholder="例: 全国統一模試" class="inp" @keydown.enter="submit" />
        <label class="mock-chk">
          <input v-model="mock" type="checkbox" />
          模試として登録する
        </label>
        <div style="display: flex; gap: 10px; justify-content: flex-end">
          <button v-if="editingId !== null" class="btn-ghost" @click="cancelEdit">編集をやめる</button>
          <button class="btn-ghost" @click="emit('close')">閉じる</button>
          <button class="btn-dark" :disabled="!value.trim() || saving" @click="submit">{{ editingId === null ? '追加' : '保存' }}</button>
        </div>
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
  padding: 20px 22px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}
.x {
  border: none;
  background: #f2f3f5;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  font-size: 18px;
  cursor: pointer;
  color: var(--mut);
}
.list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 14px;
}
.item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
}
.item.editing {
  border-color: #1c2024;
  background: #f8f9fb;
}
.item-main {
  flex: 1;
  min-width: 0;
}
.item-title {
  font-size: 13px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.item-by {
  font-size: 11px;
  color: var(--faint);
  margin-top: 1px;
}
.mini {
  padding: 5px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.mini.danger {
  color: #cf5563;
  border-color: #f0c0c0;
}
.empty {
  font-size: 12.5px;
  color: var(--faint);
  padding: 10px 0 14px;
}
.form {
  border-top: 1px solid #f0f1f3;
  padding-top: 12px;
}
.form-title {
  font-size: 12.5px;
  font-weight: 700;
  margin-bottom: 8px;
}
.inp {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #d8dce1;
  border-radius: 10px;
  font-size: 14px;
  outline: none;
  margin-bottom: 10px;
}
.mock-tag {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 999px;
  background: #fbe4ee;
  color: #cf4486;
  margin-right: 6px;
  vertical-align: middle;
}
.mock-chk {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 14px;
  cursor: pointer;
}
.mock-chk input {
  width: 16px;
  height: 16px;
}
.mock-note {
  font-size: 11px;
  font-weight: 400;
  color: var(--faint);
}
.btn-ghost {
  padding: 9px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}
.btn-dark {
  padding: 9px 18px;
  border: none;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
}
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
</style>
