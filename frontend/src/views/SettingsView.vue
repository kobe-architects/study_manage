<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore, type ColorMode, type NavStyle, type ProgressStyle } from '@/stores/ui'
import type { StudyType, UserSettings } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()

const local = reactive<UserSettings>({
  name: '',
  school: '',
  examDate: null,
  defaultType: '問題集',
  reminder: true,
  weeklyReport: true,
  hideEmpty: false,
  startScreen: 'home',
})

watch(
  () => auth.settings,
  (s) => {
    if (s) Object.assign(local, s)
  },
  { immediate: true, deep: true },
)

async function save(patch: Partial<UserSettings>) {
  Object.assign(local, patch)
  try {
    await auth.updateSettings(patch)
    ui.notify('設定を保存しました')
  } catch {
    ui.notify('保存に失敗しました')
  }
}

const colorModes: ColorMode[] = ['落ち着いた', '鮮やか', 'モノトーン']
const navStyles: NavStyle[] = ['トップナビ', 'サイドバー', 'アイコンレール']
const progressStyles: ProgressStyle[] = ['バー', 'リング', 'ドット']

const examDateModel = computed({
  get: () => local.examDate ?? '',
  set: (v: string) => save({ examDate: v || null }),
})
</script>

<template>
  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; align-items: start">
    <!-- account -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 4-6 8-6s8 2 8 6" /></svg>
        アカウント
      </div>
      <div style="display: flex; flex-direction: column; gap: 13px">
        <label class="fld"><span>氏名</span>
          <input :value="local.name" @change="save({ name: ($event.target as HTMLInputElement).value })" />
        </label>
        <label class="fld"><span>志望校</span>
          <input :value="local.school" @change="save({ school: ($event.target as HTMLInputElement).value })" />
        </label>
        <label class="fld"><span>受験日</span>
          <input v-model="examDateModel" type="date" />
        </label>
      </div>
    </div>

    <!-- display -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><circle cx="12" cy="12" r="4" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>
        表示設定
      </div>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <div class="opt">
          <div><div class="opt-t">デフォルト種別</div><div class="opt-s">学習記録の初期選択</div></div>
          <select :value="local.defaultType" @change="save({ defaultType: ($event.target as HTMLSelectElement).value as StudyType })">
            <option value="講義">講義</option><option value="問題集">問題集</option>
          </select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">未着手の項目を隠す</div><div class="opt-s">進捗0%の科目を非表示</div></div>
          <button class="toggle" :class="{ on: local.hideEmpty }" @click="save({ hideEmpty: !local.hideEmpty })"><span></span></button>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">起動時の画面</div><div class="opt-s">ログイン後に表示</div></div>
          <select :value="local.startScreen" @change="save({ startScreen: ($event.target as HTMLSelectElement).value as UserSettings['startScreen'] })">
            <option value="home">トップページ</option><option value="record">学習記録</option><option value="goal">目標設定</option>
          </select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">科目カラー</div><div class="opt-s">配色のテイスト</div></div>
          <select v-model="ui.colorMode"><option v-for="c in colorModes" :key="c" :value="c">{{ c }}</option></select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">ナビゲーション</div><div class="opt-s">メニューの配置</div></div>
          <select v-model="ui.navStyle"><option v-for="n in navStyles" :key="n" :value="n">{{ n }}</option></select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">進捗インジケータ</div><div class="opt-s">科目カードの見せ方</div></div>
          <select v-model="ui.progressStyle"><option v-for="p in progressStyles" :key="p" :value="p">{{ p }}</option></select>
        </div>
      </div>
    </div>

    <!-- notifications -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M10 21a2 2 0 0 0 4 0" /></svg>
        通知
      </div>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <div class="opt">
          <div><div class="opt-t">学習リマインダー</div><div class="opt-s">毎日20:00に通知</div></div>
          <button class="toggle" :class="{ on: local.reminder }" @click="save({ reminder: !local.reminder })"><span></span></button>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">週次レポート</div><div class="opt-s">日曜に進捗サマリーを送信</div></div>
          <button class="toggle" :class="{ on: local.weeklyReport }" @click="save({ weeklyReport: !local.weeklyReport })"><span></span></button>
        </div>
      </div>
    </div>

    <!-- data -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><path d="M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z" /><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6" /><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" /></svg>
        データ管理
      </div>
      <div style="display: flex; flex-direction: column; gap: 9px">
        <button class="data-btn" @click="ui.notify('英単語管理画面からCSVエクスポートできます')"><span>英単語データをCSVでエクスポート</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><path d="M9 18l6-6-6-6" /></svg></button>
        <button class="data-btn" @click="ui.notify('英単語管理画面からCSVインポートできます')"><span>CSVからインポート</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><path d="M9 18l6-6-6-6" /></svg></button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sec {
  padding: 20px;
}
.sec-title {
  font-size: 14px;
  font-weight: 700;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
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
}
.opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 0;
}
.opt.bt {
  border-top: 1px solid #f4f5f7;
}
.opt-t {
  font-size: 13px;
  font-weight: 500;
}
.opt-s {
  font-size: 11px;
  color: var(--faint);
}
.opt select {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  cursor: pointer;
  outline: none;
}
.toggle {
  width: 42px;
  height: 24px;
  border-radius: 99px;
  border: none;
  cursor: pointer;
  background: #d3d7dd;
  position: relative;
}
.toggle.on {
  background: #1c2024;
}
.toggle span {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
  transition: left 0.15s;
}
.toggle.on span {
  left: 20px;
}
.data-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
}
</style>
