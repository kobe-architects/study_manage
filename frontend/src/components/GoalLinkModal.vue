<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { GoalLinkBook, GoalLinkChapter } from '@/types'

const props = defineProps<{
  goalTitle: string
  books: GoalLinkBook[]
  initialIds: number[]
}>()
const emit = defineEmits<{ save: [ids: number[]]; close: [] }>()

// 選択状態（行ID → 選択有無）
const sel = reactive<Record<number, boolean>>({})
props.initialIds.forEach((id) => (sel[id] = true))

const expandBook = reactive<Record<number, boolean>>({})
const expandChap = reactive<Record<string, boolean>>({})
const q = ref('')

const chapKey = (bookId: number, name: string) => `${bookId}::${name}`

// 教材ごとに Check / Think 列を持つか（列がある教材は全行に固定幅のマーク列を確保して先頭を揃える）
const bookMarks = computed<Record<number, { check: boolean; think: boolean }>>(() => {
  const m: Record<number, { check: boolean; think: boolean }> = {}
  for (const b of props.books) {
    m[b.id] = {
      check: b.chapters.some((c) => c.rows.some((r) => r.checkFlag)),
      think: b.chapters.some((c) => c.rows.some((r) => r.think)),
    }
  }
  return m
})
// マーク列の固定幅(px)。1マーク=22px（バッジ18＋余白）。0なら列を出さない。
function marksW(bookId: number) {
  const m = bookMarks.value[bookId]
  if (!m) return 0
  return (m.check ? 22 : 0) + (m.think ? 22 : 0)
}

// 検索でフィルタした教材ツリー（行タイトル/章/教材名で絞り込み）
const filteredBooks = computed<GoalLinkBook[]>(() => {
  const term = q.value.trim()
  if (!term) return props.books
  return props.books
    .map((b) => {
      const chapters = b.chapters
        .map((c) => ({
          ...c,
          rows: c.rows.filter((r) => `${r.title ?? ''}${r.seqNo ?? ''}${c.name}${b.title}`.includes(term)),
        }))
        .filter((c) => c.rows.length)
      return { ...b, chapters }
    })
    .filter((b) => b.chapters.length)
})

function rowIdsOfBook(b: GoalLinkBook): number[] {
  return b.chapters.flatMap((c) => c.rows.map((r) => r.id))
}
function countSel(ids: number[]): number {
  return ids.reduce((n, id) => n + (sel[id] ? 1 : 0), 0)
}
function stateOf(ids: number[]): 'on' | 'mid' | 'off' {
  if (!ids.length) return 'off'
  const n = countSel(ids)
  if (n >= ids.length) return 'on'
  if (n === 0) return 'off'
  return 'mid'
}
function setMany(ids: number[], on: boolean) {
  ids.forEach((id) => (sel[id] = on))
}
function toggleBook(b: GoalLinkBook) {
  const ids = rowIdsOfBook(b)
  setMany(ids, stateOf(ids) !== 'on')
}
function toggleChap(c: GoalLinkChapter) {
  const ids = c.rows.map((r) => r.id)
  setMany(ids, stateOf(ids) !== 'on')
}
function toggleRow(id: number) {
  sel[id] = !sel[id]
}

const selectedTotal = computed(() => Object.keys(sel).filter((k) => sel[Number(k)]).length)

const checkStyle = {
  on: { bg: '#1c2024', bd: '#1c2024', co: '#fff', mark: '✓' },
  mid: { bg: '#fff', bd: '#1c2024', co: '#1c2024', mark: '−' },
  off: { bg: '#fff', bd: '#cbd1d8', co: 'transparent', mark: '' },
}

function clearAll() {
  Object.keys(sel).forEach((k) => (sel[Number(k)] = false))
}

function save() {
  const ids = Object.keys(sel)
    .filter((k) => sel[Number(k)])
    .map(Number)
  emit('save', ids)
}
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div class="modal-head">
        <div>
          <div style="font-size: 16px; font-weight: 700">個別学習データの紐づけ</div>
          <div style="font-size: 12px; color: var(--faint); margin-top: 3px">{{ goalTitle }}</div>
        </div>
        <span class="count-badge">{{ selectedTotal }} 件選択中</span>
      </div>

      <div style="position: relative; margin: 14px 0 8px">
        <input v-model="q" placeholder="教材・章・タイトルで検索…" class="search" />
        <button v-if="selectedTotal" class="clear-link" @click="clearAll">全解除</button>
      </div>

      <div class="tree">
        <div v-for="b in filteredBooks" :key="b.id">
          <!-- 教材 -->
          <div class="row book-row">
            <button class="chev" @click="expandBook[b.id] = !expandBook[b.id]">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expandBook[b.id] ? 'rotate(90deg)' : 'none' }"><path d="M9 6l6 6-6 6" /></svg>
            </button>
            <button
              class="chk"
              :style="{ borderColor: checkStyle[stateOf(rowIdsOfBook(b))].bd, background: checkStyle[stateOf(rowIdsOfBook(b))].bg, color: checkStyle[stateOf(rowIdsOfBook(b))].co }"
              @click="toggleBook(b)"
            >{{ checkStyle[stateOf(rowIdsOfBook(b))].mark }}</button>
            <span class="book-dot" :style="{ background: b.colorVivid }"></span>
            <span class="book-title">{{ b.title }}</span>
            <span class="type-tag">{{ b.type }}</span>
            <span class="row-count">{{ countSel(rowIdsOfBook(b)) }}/{{ b.rowCount }}</span>
          </div>

          <!-- 章 -->
          <template v-if="expandBook[b.id]">
            <div v-for="c in b.chapters" :key="c.name">
              <div class="row chap-row">
                <button class="chev" @click="expandChap[chapKey(b.id, c.name)] = !expandChap[chapKey(b.id, c.name)]">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expandChap[chapKey(b.id, c.name)] ? 'rotate(90deg)' : 'none' }"><path d="M9 6l6 6-6 6" /></svg>
                </button>
                <button
                  class="chk"
                  :style="{ borderColor: checkStyle[stateOf(c.rows.map((r) => r.id))].bd, background: checkStyle[stateOf(c.rows.map((r) => r.id))].bg, color: checkStyle[stateOf(c.rows.map((r) => r.id))].co }"
                  @click="toggleChap(c)"
                >{{ checkStyle[stateOf(c.rows.map((r) => r.id))].mark }}</button>
                <span class="chap-name">{{ c.name }}</span>
                <span class="row-count">{{ countSel(c.rows.map((r) => r.id)) }}/{{ c.rows.length }}</span>
              </div>

              <!-- 行 -->
              <template v-if="expandChap[chapKey(b.id, c.name)]">
                <div v-for="r in c.rows" :key="r.id" class="row leaf-row" @click="toggleRow(r.id)">
                  <button
                    class="chk"
                    :style="{ borderColor: sel[r.id] ? '#1c2024' : '#cbd1d8', background: sel[r.id] ? '#1c2024' : '#fff', color: sel[r.id] ? '#fff' : 'transparent' }"
                    @click.stop="toggleRow(r.id)"
                  >{{ sel[r.id] ? '✓' : '' }}</button>
                  <span v-if="marksW(b.id)" class="leaf-marks" :style="{ width: marksW(b.id) + 'px' }">
                    <span v-if="r.checkFlag" class="mk mk-check" title="Check">{{ r.checkFlag }}</span>
                    <span v-if="r.think" class="mk mk-think" title="Think">{{ r.think }}</span>
                  </span>
                  <span class="leaf-title"><span v-if="r.seqNo" class="seq">{{ r.seqNo }}.</span> {{ r.title ?? '（無題）' }}</span>
                </div>
              </template>
            </div>
          </template>
        </div>
        <div v-if="!filteredBooks.length" class="empty">該当する個別学習データがありません</div>
      </div>

      <div class="modal-actions">
        <button class="btn-ghost" @click="emit('close')">キャンセル</button>
        <button class="btn-dark" @click="save">保存（{{ selectedTotal }} 件）</button>
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
  z-index: 60;
  padding: 20px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 22px;
  width: 100%;
  max-width: 560px;
  max-height: 86vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.count-badge {
  flex-shrink: 0;
  font-size: 11.5px;
  font-weight: 700;
  color: #3b50cc;
  background: #eef1fb;
  padding: 4px 10px;
  border-radius: 99px;
}
.search {
  width: 100%;
  padding: 9px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  outline: none;
}
.clear-link {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: transparent;
  color: #9aa1ab;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}
.tree {
  flex: 1;
  overflow-y: auto;
  border: 1px solid #eceef0;
  border-radius: 12px;
  padding: 4px 0;
  min-height: 200px;
}
.row {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 14px;
}
.book-row {
  border-top: 1px solid #f2f3f5;
}
.book-row:first-child {
  border-top: none;
}
.chap-row {
  padding-left: 40px;
  background: #fafbfc;
}
.leaf-row {
  padding-left: 66px;
  cursor: pointer;
}
.leaf-row:hover {
  background: #f6f8fb;
}
.chev {
  width: 18px;
  height: 18px;
  border: none;
  background: none;
  cursor: pointer;
  color: #9aa1ab;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chk {
  width: 19px;
  height: 19px;
  border: 1.6px solid;
  border-radius: 5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}
.book-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.book-title {
  font-size: 13px;
  font-weight: 700;
  color: #1c2024;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
}
.type-tag {
  font-size: 10.5px;
  font-weight: 600;
  color: #6b7280;
  background: #f1f2f4;
  padding: 1px 7px;
  border-radius: 99px;
  flex-shrink: 0;
}
.chap-name {
  flex: 1;
  min-width: 0;
  font-size: 12.5px;
  font-weight: 600;
  color: #4b5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.row-count {
  font-size: 11px;
  color: var(--faint);
  flex-shrink: 0;
}
.leaf-title {
  font-size: 12px;
  color: #4b5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.seq {
  color: #aeb4bd;
}
/* Focus Gold の Check / Think（固定幅のマーク列で項目名の先頭を揃える） */
.leaf-marks {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.mk {
  flex-shrink: 0;
  width: 18px;
  text-align: center;
  font-size: 11px;
  font-weight: 700;
  border-radius: 4px;
  line-height: 16px;
}
.mk-check {
  color: #3a8a5c;
  background: #eaf6ef;
}
.mk-think {
  color: #6b5bd0;
  background: #eeecfa;
}
.empty {
  padding: 40px;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
  margin-top: 16px;
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
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
</style>
