import axios from 'axios'

const client = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

// 認証トークンを付与
client.interceptors.request.use((config) => {
  const token = localStorage.getItem('sm_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// 401 はログインへ
client.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('sm_token')
      if (location.pathname !== '/login') {
        location.href = '/login'
      }
    }
    return Promise.reject(error)
  },
)

export default client
