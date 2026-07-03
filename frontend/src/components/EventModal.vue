<script setup lang="ts">
import { ref } from 'vue'
import { parseDate } from '@/lib/design'

const props = defineProps<{ date: string; title: string }>()
const emit = defineEmits<{ save: [title: string]; delete: []; close: [] }>()

const value = ref(props.title)
const existing = !!props.title

const d = parseDate(props.date)
const label = `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px">予定の登録</div>
      <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">{{ label }}</div>
      <input
        v-model="value"
        placeholder="例: 全国統一模試"
        style="width: 100%; padding: 10px 12px; border: 1px solid #d8dce1; border-radius: 10px; font-size: 14px; outline: none; margin-bottom: 16px"
        @keydown.enter="emit('save', value)"
      />
      <div style="display: flex; gap: 10px; align-items: center">
        <button v-if="existing" class="btn-danger" @click="emit('delete')">削除</button>
        <div style="display: flex; gap: 10px; margin-left: auto">
          <button class="btn-ghost" @click="emit('close')">キャンセル</button>
          <button class="btn-dark" @click="emit('save', value)">保存</button>
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
  padding: 24px;
  width: 100%;
  max-width: 380px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.btn-danger {
  padding: 9px 16px;
  border: 1px solid #f0c0c0;
  border-radius: 9px;
  background: #fff;
  color: #cf5563;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}
.btn-ghost {
  padding: 9px 16px;
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
  font-weight: 600;
}
</style>
