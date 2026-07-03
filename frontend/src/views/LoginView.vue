<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('user@example.com')
const password = ref('password')
const loading = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'home' })
  } catch (e: unknown) {
    error.value = 'メールアドレスまたはパスワードが正しくありません。'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-wrap">
    <div class="card login-card">
      <div class="brand">
        <div class="logo dm">学</div>
        <div>
          <div style="font-weight: 700; font-size: 18px">受験ナビ</div>
          <div style="font-size: 11px; color: var(--faint); letter-spacing: 0.04em">STUDY MANAGER</div>
        </div>
      </div>
      <div style="font-size: 13px; color: var(--mut); margin: 6px 0 20px">アカウントにログインしてください</div>
      <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 14px">
        <label class="fld">
          <span>メールアドレス</span>
          <input v-model="email" type="email" autocomplete="username" />
        </label>
        <label class="fld">
          <span>パスワード</span>
          <input v-model="password" type="password" autocomplete="current-password" />
        </label>
        <div v-if="error" style="color: #cf5563; font-size: 12.5px">{{ error }}</div>
        <button class="primary-btn" :disabled="loading" type="submit">
          {{ loading ? 'ログイン中…' : 'ログイン' }}
        </button>
      </form>
      <div style="font-size: 11.5px; color: var(--faint); margin-top: 16px; line-height: 1.6">
        デモ: user@example.com / password
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg);
  padding: 20px;
}
.login-card {
  width: 100%;
  max-width: 380px;
  padding: 32px 30px;
}
.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.logo {
  width: 40px;
  height: 40px;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-weight: 700;
  font-size: 19px;
  display: flex;
  align-items: center;
  justify-content: center;
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
  padding: 10px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 14px;
  outline: none;
}
.fld input:focus {
  border-color: var(--primary);
}
.primary-btn {
  margin-top: 6px;
  padding: 12px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.primary-btn:disabled {
  opacity: 0.6;
}
</style>
